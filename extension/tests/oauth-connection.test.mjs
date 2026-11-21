import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { test } from 'node:test';
import { CONFIRMED_HOSTS_KEY } from '../src/shared/allowed-hosts.js';
import { ApiError, CONNECT_AGAIN, PAIR_AGAIN } from '../src/shared/api.js';
import { CLIENT_KEY, CLIENT_NAME, ConnectRefused, DENIED, NOT_CONNECTED, NOT_OAUTH_SERVER, SCOPES, pinroundApiOf, pkceChallengeOf } from '../src/shared/oauth-connection.js';
import { loadSettings } from '../src/shared/settings.js';
import { CONNECTED, CONNECTION_SIGNED_OUT_LOCALLY, SIGNED_OUT, connectToPinround, signOut } from '../src/options/pairing.js';
import { FAKE_EXTENSION_ID, fakeBrowser } from './fake-browser.mjs';

const ORIGIN = 'https://pinround.acme-foods.example';
const REDIRECT_URI = `https://${FAKE_EXTENSION_ID}.chromiumapp.org/`;
const CONFIRMED_HOSTS = CONFIRMED_HOSTS_KEY;

/**
 * pinround's OAuth server as ADR-0050 has it: one registration per POST, codes single use and bound to the redirect URI
 * and the PKCE challenge, refresh tokens rotated (the old one refused once used), revocation answering 200 always, and
 * /api/v1 refusing any token that is not a live access token with 401.
 */
function fakeOAuthServer({ discovery = {} } = {}) {
    const registrations = [];
    const codes = new Map();
    const liveAccessTokens = new Set();
    const liveRefreshTokens = new Map();
    const revoked = [];
    const apiCalls = [];
    let issued = 0;
    const json = (status, body) => ({ ok: status < 300, status, json: async () => body });
    const issuePair = (clientId) => {
        issued += 1;
        const pair = { access_token: `access-${issued}-secret`, refresh_token: `refresh-${issued}-secret` };
        liveAccessTokens.add(pair.access_token);
        liveRefreshTokens.set(pair.refresh_token, clientId);

        return json(200, { ...pair, token_type: 'Bearer', expires_in: 3600, scope: SCOPES });
    };
    const server = {
        registrations,
        revoked,
        apiCalls,
        liveAccessTokens,
        liveRefreshTokens,
        refreshAnswer: null,
        /** What the person does on the consent page: Allow, with the state echoed. */
        allow(url) {
            const asked = new URL(url).searchParams;
            const code = `code-${codes.size + 1}`;
            codes.set(code, { clientId: asked.get('client_id'), redirectUri: asked.get('redirect_uri'), challenge: asked.get('code_challenge') });

            return `${asked.get('redirect_uri')}?code=${code}&state=${encodeURIComponent(asked.get('state'))}`;
        },
        async fetchFn(url, init) {
            const { pathname } = new URL(url);
            const form = new URLSearchParams(init.body ?? '');
            if ('/.well-known/oauth-authorization-server' === pathname) {
                return json(200, {
                    issuer: ORIGIN,
                    authorization_endpoint: `${ORIGIN}/oauth/authorize`,
                    token_endpoint: `${ORIGIN}/oauth/token`,
                    registration_endpoint: `${ORIGIN}/oauth/register`,
                    revocation_endpoint: `${ORIGIN}/oauth/revoke`,
                    ...discovery,
                });
            }
            if ('/oauth/register' === pathname) {
                registrations.push(JSON.parse(init.body));

                return json(201, { client_id: `client-${registrations.length}` });
            }
            if ('/oauth/token' === pathname && 'authorization_code' === form.get('grant_type')) {
                const code = codes.get(form.get('code'));
                codes.delete(form.get('code'));
                const verifierMatches = undefined !== code && createHash('sha256').update(form.get('code_verifier')).digest('base64url') === code.challenge;
                if (!verifierMatches || code.redirectUri !== form.get('redirect_uri') || code.clientId !== form.get('client_id')) {
                    return json(400, { error: 'invalid_grant' });
                }

                return issuePair(code.clientId);
            }
            if ('/oauth/token' === pathname && 'refresh_token' === form.get('grant_type')) {
                if (null !== server.refreshAnswer) {
                    return json(server.refreshAnswer, { error: 'server_error' });
                }
                if (liveRefreshTokens.get(form.get('refresh_token')) !== form.get('client_id')) {
                    return json(400, { error: 'invalid_grant' });
                }
                liveRefreshTokens.delete(form.get('refresh_token'));

                return issuePair(form.get('client_id'));
            }
            if ('/oauth/revoke' === pathname) {
                revoked.push({ token: form.get('token'), hint: form.get('token_type_hint'), clientId: form.get('client_id') });
                liveAccessTokens.delete(form.get('token'));
                liveRefreshTokens.delete(form.get('token'));

                return json(200, {});
            }
            if (pathname.startsWith('/api/v1/')) {
                const token = init.headers.Authorization.replace(/^Bearer /, '');
                apiCalls.push({ path: pathname, token });

                return liveAccessTokens.has(token) ? json(200, { tickets: [] }) : json(401, { error: 'Unauthorized' });
            }

            return json(404, {});
        },
    };

    return server;
}

/** A connected browser: Mia allowed the extension on the consent page. */
async function connectedBrowser(server = fakeOAuthServer()) {
    const browserApi = fakeBrowser({ webAuthFlow: async (url) => server.allow(url) });
    assert.equal(await connectToPinround(ORIGIN, browserApi, { fetchFn: server.fetchFn }), CONNECTED);

    return browserApi;
}

test('the PKCE challenge is the RFC 7636 Appendix B one', async () => {
    assert.equal(await pkceChallengeOf('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'), 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
});

test('Connect registers once, opens the consent page with PKCE S256 and a state, and keeps the issued pair', async () => {
    const server = fakeOAuthServer();
    const browserApi = await connectedBrowser(server);

    assert.equal(browserApi.webAuthFlows.length, 1);
    assert.equal(browserApi.webAuthFlows[0].interactive, true);
    const asked = new URL(browserApi.webAuthFlows[0].url);
    assert.equal(`${asked.origin}${asked.pathname}`, `${ORIGIN}/oauth/authorize`);
    assert.deepEqual(Object.fromEntries([...asked.searchParams].filter(([name]) => !['state', 'code_challenge'].includes(name))), {
        response_type: 'code', client_id: 'client-1', redirect_uri: REDIRECT_URI, scope: SCOPES, code_challenge_method: 'S256',
    });
    assert.match(asked.searchParams.get('state'), /^[A-Za-z0-9_-]{22}$/);
    assert.match(asked.searchParams.get('code_challenge'), /^[A-Za-z0-9_-]{43}$/);
    assert.deepEqual(server.registrations, [{
        client_name: CLIENT_NAME, redirect_uris: [REDIRECT_URI], grant_types: ['authorization_code', 'refresh_token'], response_types: ['code'], token_endpoint_auth_method: 'none',
    }]);
    assert.deepEqual(await loadSettings(browserApi.storage.local), {
        origin: ORIGIN, token: 'access-1-secret', refreshToken: 'refresh-1-secret', clientId: 'client-1', tokenEndpoint: `${ORIGIN}/oauth/token`, revocationEndpoint: `${ORIGIN}/oauth/revoke`,
    });

    assert.equal(await connectToPinround(ORIGIN, browserApi, { fetchFn: server.fetchFn }), CONNECTED);
    assert.equal(server.registrations.length, 1, 'the second Connect reuses the kept client');
    assert.equal(new URL(browserApi.webAuthFlows[1].url).searchParams.get('client_id'), 'client-1');
    assert.notEqual(new URL(browserApi.webAuthFlows[1].url).searchParams.get('state'), asked.searchParams.get('state'));
});

test('a state that does not match is refused, and no code is traded and no token kept', async () => {
    const server = fakeOAuthServer();
    const browserApi = fakeBrowser({ webAuthFlow: async (url) => server.allow(url).replace(/state=[^&]+/, 'state=someone-elses-state') });

    await assert.rejects(connectToPinround(ORIGIN, browserApi, { fetchFn: server.fetchFn }), new ConnectRefused(NOT_CONNECTED));
    assert.equal(server.liveAccessTokens.size, 0);
    assert.equal(await loadSettings(browserApi.storage.local), null);
});

test('Deny and a closed window keep no token', async () => {
    const server = fakeOAuthServer();
    const denied = fakeBrowser({ webAuthFlow: async (url) => `${REDIRECT_URI}?error=access_denied&state=${new URL(url).searchParams.get('state')}` });
    await assert.rejects(connectToPinround(ORIGIN, denied, { fetchFn: server.fetchFn }), new ConnectRefused(DENIED));
    assert.equal(await loadSettings(denied.storage.local), null);

    const closed = fakeBrowser();
    await assert.rejects(connectToPinround(ORIGIN, closed, { fetchFn: server.fetchFn }), new ConnectRefused(NOT_CONNECTED));
    assert.equal(await loadSettings(closed.storage.local), null);
});

test('an endpoint on another origin is refused before anything is registered', async () => {
    const server = fakeOAuthServer({ discovery: { token_endpoint: 'https://collector.example/oauth/token' } });
    const browserApi = fakeBrowser({ webAuthFlow: async (url) => server.allow(url) });

    await assert.rejects(connectToPinround(ORIGIN, browserApi, { fetchFn: server.fetchFn }), new ConnectRefused(NOT_OAUTH_SERVER));
    assert.deepEqual(server.registrations, []);
    assert.deepEqual(browserApi.webAuthFlows, []);
    assert.deepEqual(await browserApi.storage.local.get(CLIENT_KEY), {});
});

test('a 401 refreshes once, keeps the rotated pair and sends the call again with the new token', async () => {
    const server = fakeOAuthServer();
    const browserApi = await connectedBrowser(server);
    server.liveAccessTokens.delete('access-1-secret');

    const settings = await loadSettings(browserApi.storage.local);
    assert.deepEqual(await pinroundApiOf(settings, browserApi.storage.local, server.fetchFn).call('GET', '/api/v1/me/tickets'), { tickets: [] });

    assert.deepEqual(server.apiCalls, [{ path: '/api/v1/me/tickets', token: 'access-1-secret' }, { path: '/api/v1/me/tickets', token: 'access-2-secret' }]);
    const renewed = await loadSettings(browserApi.storage.local);
    assert.equal(renewed.token, 'access-2-secret');
    assert.equal(renewed.refreshToken, 'refresh-2-secret');
    assert.equal(server.liveRefreshTokens.has('refresh-1-secret'), false);
});

test('a second 401 after the refresh is not refreshed again', async () => {
    const server = fakeOAuthServer();
    const browserApi = await connectedBrowser(server);
    server.liveAccessTokens.clear();
    const realFetch = server.fetchFn;
    const fetchFn = async (url, init) => (new URL(url).pathname.startsWith('/api/v1/') ? { ok: false, status: 401, json: async () => ({}) } : realFetch(url, init));

    const settings = await loadSettings(browserApi.storage.local);
    await assert.rejects(pinroundApiOf(settings, browserApi.storage.local, fetchFn).call('GET', '/api/v1/me/tickets'), new ApiError(CONNECT_AGAIN, 401));
    assert.equal((await loadSettings(browserApi.storage.local)).refreshToken, 'refresh-2-secret', 'one refresh, not two');
});

test('a refused refresh says Connect again and forgets the tokens, the confirmed hosts stay', async () => {
    const server = fakeOAuthServer();
    const browserApi = await connectedBrowser(server);
    await browserApi.storage.local.set({ [CONFIRMED_HOSTS]: ['https://shop.acme-foods.test'] });
    server.liveAccessTokens.clear();
    server.liveRefreshTokens.clear();

    const settings = await loadSettings(browserApi.storage.local);
    await assert.rejects(pinroundApiOf(settings, browserApi.storage.local, server.fetchFn).call('GET', '/api/v1/me/tickets'), (error) => error instanceof ApiError && CONNECT_AGAIN === error.message && /Connect again/.test(error.message));
    assert.equal(await loadSettings(browserApi.storage.local), null);
    assert.deepEqual((await browserApi.storage.local.get(CONFIRMED_HOSTS))[CONFIRMED_HOSTS], ['https://shop.acme-foods.test']);
    assert.deepEqual(server.apiCalls.length, 1, 'no retry without a token');
});

test('a refresh pinround did not answer, or answered 429 or 5xx, keeps the tokens', async () => {
    for (const refreshAnswer of [429, 503]) {
        const server = fakeOAuthServer();
        const browserApi = await connectedBrowser(server);
        server.liveAccessTokens.clear();
        server.refreshAnswer = refreshAnswer;
        const settings = await loadSettings(browserApi.storage.local);

        await assert.rejects(pinroundApiOf(settings, browserApi.storage.local, server.fetchFn).call('GET', '/api/v1/me/tickets'), (error) => error instanceof ApiError && refreshAnswer === error.status);
        assert.deepEqual(await loadSettings(browserApi.storage.local), settings);
    }
});

test('a refresh another page already made is used, not refused and forgotten', async () => {
    const server = fakeOAuthServer();
    const browserApi = await connectedBrowser(server);
    const before = await loadSettings(browserApi.storage.local);
    server.liveAccessTokens.clear();
    const popupApi = pinroundApiOf(before, browserApi.storage.local, server.fetchFn);
    const dockApi = pinroundApiOf(before, browserApi.storage.local, server.fetchFn);

    await popupApi.call('GET', '/api/v1/me/tickets');
    await dockApi.call('GET', '/api/v1/me/tickets');

    assert.deepEqual(server.apiCalls.map((call) => call.token), ['access-1-secret', 'access-2-secret', 'access-1-secret', 'access-2-secret']);
    assert.equal((await loadSettings(browserApi.storage.local)).token, 'access-2-secret');
});

test('a pasted token is never refreshed: its 401 says pair again', async () => {
    const server = fakeOAuthServer();
    const api = pinroundApiOf({ origin: ORIGIN, token: 'pr_abcdefghijklmnopqrstuvwxyz012345' }, fakeBrowser().storage.local, server.fetchFn);

    await assert.rejects(api.call('GET', '/api/v1/me/tickets'), new ApiError(PAIR_AGAIN, 401));
    assert.equal(server.apiCalls.length, 1);
});

test('Sign out revokes the refresh and the access token, then forgets them', async () => {
    const server = fakeOAuthServer();
    const browserApi = await connectedBrowser(server);

    assert.equal(await signOut(browserApi.storage, server.fetchFn), SIGNED_OUT);
    assert.deepEqual(server.revoked, [
        { token: 'refresh-1-secret', hint: 'refresh_token', clientId: 'client-1' },
        { token: 'access-1-secret', hint: 'access_token', clientId: 'client-1' },
    ]);
    assert.equal(await loadSettings(browserApi.storage.local), null);
    assert.equal((await browserApi.storage.local.get(CLIENT_KEY))[CLIENT_KEY].clientId, 'client-1', 'the client registration stays for the next Connect');
});

test('Sign out with pinround out of reach still forgets the tokens and says so', async () => {
    const server = fakeOAuthServer();
    const browserApi = await connectedBrowser(server);

    assert.equal(await signOut(browserApi.storage, async () => { throw new TypeError('Failed to fetch'); }), CONNECTION_SIGNED_OUT_LOCALLY);
    assert.equal(await loadSettings(browserApi.storage.local), null);
});

test('no token reaches the console in Connect, refresh, a refused refresh or Sign out', async (context) => {
    const printed = [];
    for (const method of ['log', 'info', 'warn', 'error', 'debug', 'trace']) {
        context.mock.method(console, method, (...args) => printed.push(args.map(String).join(' ')));
    }
    const server = fakeOAuthServer();
    const browserApi = await connectedBrowser(server);
    server.liveAccessTokens.clear();
    await pinroundApiOf(await loadSettings(browserApi.storage.local), browserApi.storage.local, server.fetchFn).call('GET', '/api/v1/me/tickets');
    await signOut(browserApi.storage, server.fetchFn);
    const refused = await connectedBrowser(server);
    server.liveAccessTokens.clear();
    server.liveRefreshTokens.clear();
    await assert.rejects(pinroundApiOf(await loadSettings(refused.storage.local), refused.storage.local, server.fetchFn).call('GET', '/api/v1/me/tickets'), (error) => !/secret|code-/.test(error.message));

    assert.equal(printed.filter((line) => /secret|code-\d/.test(line)).length, 0);
});
