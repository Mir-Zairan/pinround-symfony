import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { test } from 'node:test';
import { CONFIRMED_HOSTS_KEY } from '../src/shared/allowed-hosts.js';
import { ApiError, CONNECT_AGAIN, NOT_REACHED, PAIR_AGAIN } from '../src/shared/api.js';
import {
    CLIENT_KEY, CLIENT_NAME, ConnectRefused, DENIED, NOT_CONNECTED, NOT_OAUTH_SERVER, SCOPES, TOKEN_REFRESH_LOCK, pinroundApiOf, pkceChallengeOf, renewAccessToken, tokenRefreshLocksOf,
} from '../src/shared/oauth-connection.js';
import { loadSettings, saveConnection } from '../src/shared/settings.js';
import { CONNECTED, SIGNED_OUT, connectToPinround, pair, signOut } from '../src/options/pairing.js';
import { FAKE_EXTENSION_ID, fakeBrowser } from './fake-browser.mjs';
import { fakeLocks } from './fake-locks.mjs';

const ORIGIN = 'https://pinround.acme-foods.example';
const UNUSABLE_REFRESH_TOKEN = 'The refresh token is unknown, used, revoked or expired. Connect again.';
const REDIRECT_URI = `https://${FAKE_EXTENSION_ID}.chromiumapp.org/`;
const CONFIRMED_HOSTS = CONFIRMED_HOSTS_KEY;

/**
 * pinround's OAuth server as ADR-0050 has it: one registration per POST, codes single use and bound to the redirect URI
 * and the PKCE challenge, refresh tokens rotated, a used one coming back revoking every token of its grant's family
 * (RefreshOAuthTokens), each refused refresh answered invalid_grant with RefreshOAuthTokens::UNUSABLE_REFRESH_TOKEN,
 * revocation answering 200 always, and /api/v1 refusing any token that is not a live access token with 401.
 */
function fakeOAuthServer({ discovery = {} } = {}) {
    const registrations = [];
    const codes = new Map();
    const liveAccessTokens = new Set();
    const liveRefreshTokens = new Map();
    const familyOfToken = new Map();
    const usedRefreshTokens = new Set();
    const refreshesAsked = [];
    const revokedFamilies = [];
    const revoked = [];
    const apiCalls = [];
    let issued = 0;
    const json = (status, body) => ({ ok: status < 300, status, json: async () => body });
    const issuePair = (clientId, family) => {
        issued += 1;
        const pair = { access_token: `access-${issued}-secret`, refresh_token: `refresh-${issued}-secret` };
        liveAccessTokens.add(pair.access_token);
        liveRefreshTokens.set(pair.refresh_token, clientId);
        familyOfToken.set(pair.access_token, family);
        familyOfToken.set(pair.refresh_token, family);

        return json(200, { ...pair, token_type: 'Bearer', expires_in: 3600, scope: SCOPES });
    };
    const revokeFamily = (family) => {
        revokedFamilies.push(family);
        for (const [token, tokenFamily] of familyOfToken) {
            if (tokenFamily === family) {
                liveAccessTokens.delete(token);
                liveRefreshTokens.delete(token);
            }
        }
    };
    const server = {
        registrations,
        revoked,
        apiCalls,
        liveAccessTokens,
        liveRefreshTokens,
        /** The refresh_token of each refresh_token grant asked for, in order. */
        refreshesAsked,
        revokedFamilies,
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

                return issuePair(code.clientId, form.get('code'));
            }
            if ('/oauth/token' === pathname && 'refresh_token' === form.get('grant_type')) {
                const refreshToken = form.get('refresh_token');
                refreshesAsked.push(refreshToken);
                if (null !== server.refreshAnswer) {
                    return json(server.refreshAnswer, { error: 'server_error' });
                }
                if (liveRefreshTokens.get(refreshToken) !== form.get('client_id')) {
                    if (usedRefreshTokens.has(refreshToken)) {
                        revokeFamily(familyOfToken.get(refreshToken));
                    }

                    return json(400, { error: 'invalid_grant', error_description: UNUSABLE_REFRESH_TOKEN });
                }
                liveRefreshTokens.delete(refreshToken);
                usedRefreshTokens.add(refreshToken);

                return issuePair(form.get('client_id'), familyOfToken.get(refreshToken));
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

test('a Connect that gets no code forgets the kept client, so the next one registers again; Deny keeps it', async () => {
    const server = fakeOAuthServer();
    let inWindow = async (url) => server.allow(url);
    const browserApi = fakeBrowser({ webAuthFlow: (url) => inWindow(url) });
    assert.equal(await connectToPinround(ORIGIN, browserApi, { fetchFn: server.fetchFn }), CONNECTED);
    assert.equal((await browserApi.storage.local.get(CLIENT_KEY))[CLIENT_KEY].clientId, 'client-1');

    // A reset pinround no longer knows client-1: its consent page shows an error and the window is closed.
    inWindow = async () => null;
    await assert.rejects(connectToPinround(ORIGIN, browserApi, { fetchFn: server.fetchFn }), new ConnectRefused(NOT_CONNECTED));
    assert.deepEqual(await browserApi.storage.local.get(CLIENT_KEY), {});

    inWindow = async (url) => server.allow(url);
    assert.equal(await connectToPinround(ORIGIN, browserApi, { fetchFn: server.fetchFn }), CONNECTED);
    assert.equal(server.registrations.length, 2, 'the next Connect registered a new client');

    inWindow = async (url) => `${REDIRECT_URI}?error=access_denied&state=${new URL(url).searchParams.get('state')}`;
    await assert.rejects(connectToPinround(ORIGIN, browserApi, { fetchFn: server.fetchFn }), new ConnectRefused(DENIED));
    assert.equal((await browserApi.storage.local.get(CLIENT_KEY))[CLIENT_KEY].clientId, 'client-2', 'Deny keeps the client');
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

test('a refused refresh shows pinround\'s reason and forgets the tokens, the confirmed hosts stay', async () => {
    const server = fakeOAuthServer();
    const browserApi = await connectedBrowser(server);
    await browserApi.storage.local.set({ [CONFIRMED_HOSTS]: ['https://shop.acme-foods.test'] });
    server.liveAccessTokens.clear();
    server.liveRefreshTokens.clear();

    const settings = await loadSettings(browserApi.storage.local);
    await assert.rejects(pinroundApiOf(settings, browserApi.storage.local, server.fetchFn).call('GET', '/api/v1/me/tickets'), new ApiError(UNUSABLE_REFRESH_TOKEN, 401));
    assert.equal(await loadSettings(browserApi.storage.local), null);
    assert.deepEqual((await browserApi.storage.local.get(CONFIRMED_HOSTS))[CONFIRMED_HOSTS], ['https://shop.acme-foods.test']);
    assert.deepEqual(server.apiCalls.length, 1, 'no retry without a token');
});

test('a refresh refused for the token cap shows the cap\'s reason, and one refused with no reason says Connect to pinround', async () => {
    const capReason = 'You have 20 tokens that still work in this organization.';
    for (const [refusal, shown] of [[{ error: 'invalid_grant', error_description: capReason }, capReason], [{ error: 'invalid_grant' }, CONNECT_AGAIN], [{ error: 'invalid_grant', error_description: '' }, CONNECT_AGAIN]]) {
        const server = fakeOAuthServer();
        const browserApi = await connectedBrowser(server);
        server.liveAccessTokens.clear();
        const fetchFn = async (url, init) => ('/oauth/token' === new URL(url).pathname ? { ok: false, status: 400, json: async () => refusal } : server.fetchFn(url, init));

        await assert.rejects(pinroundApiOf(await loadSettings(browserApi.storage.local), browserApi.storage.local, fetchFn).call('GET', '/api/v1/me/tickets'), new ApiError(shown, 401));
        assert.equal(await loadSettings(browserApi.storage.local), null);
    }
    assert.match(CONNECT_AGAIN, /Press Connect to pinround in the extension options\./);
});

test('a refused refresh keeps the tokens a Connect saved while it was asked', async () => {
    const server = fakeOAuthServer();
    const browserApi = await connectedBrowser(server);
    const before = await loadSettings(browserApi.storage.local);
    const reconnected = { ...before, token: 'access-9-secret', refreshToken: 'refresh-9-secret' };
    const fetchFn = async (url, init) => {
        if ('/oauth/token' !== new URL(url).pathname) {
            return server.fetchFn(url, init);
        }
        await saveConnection(reconnected, browserApi.storage.local);

        return { ok: false, status: 400, json: async () => ({ error: 'invalid_grant', error_description: UNUSABLE_REFRESH_TOKEN }) };
    };

    assert.equal(await renewAccessToken('access-1-secret', browserApi.storage.local, fetchFn, fakeLocks()), 'access-9-secret');
    assert.deepEqual(await loadSettings(browserApi.storage.local), reconnected);
});

test('two pages renewing at once send the refresh token once, and both get the new access token', async () => {
    const sharedLocks = fakeLocks();
    for (const locks of [sharedLocks, tokenRefreshLocksOf({})]) {
        const server = fakeOAuthServer();
        const browserApi = await connectedBrowser(server);
        const before = await loadSettings(browserApi.storage.local);
        server.liveAccessTokens.delete('access-1-secret');
        const popupApi = pinroundApiOf(before, browserApi.storage.local, server.fetchFn, locks);
        const dockApi = pinroundApiOf(before, browserApi.storage.local, server.fetchFn, locks);

        const answers = await Promise.all([popupApi.call('GET', '/api/v1/me/tickets'), dockApi.call('GET', '/api/v1/me/tickets')]);

        assert.deepEqual(answers, [{ tickets: [] }, { tickets: [] }]);
        assert.deepEqual(server.refreshesAsked, ['refresh-1-secret']);
        assert.deepEqual(server.revokedFamilies, []);
        assert.deepEqual([popupApi.token, dockApi.token], ['access-2-secret', 'access-2-secret']);
        assert.deepEqual(await loadSettings(browserApi.storage.local), { ...before, token: 'access-2-secret', refreshToken: 'refresh-2-secret' });
        assert.equal(server.liveRefreshTokens.has('refresh-2-secret'), true);
    }
    assert.deepEqual(sharedLocks.granted, [TOKEN_REFRESH_LOCK, TOKEN_REFRESH_LOCK]);
});

test('a renewal that waited for the lock and finds the tokens renewed meanwhile uses them and posts nothing', async () => {
    const server = fakeOAuthServer();
    const browserApi = await connectedBrowser(server);
    const storage = browserApi.storage.local;
    const renewedByOtherPage = { ...(await loadSettings(storage)), token: 'access-9-secret', refreshToken: 'refresh-9-secret' };
    const locks = fakeLocks();
    let release;
    let markGranted;
    const granted = new Promise((resolve) => {
        markGranted = resolve;
    });
    const otherPage = locks.request(TOKEN_REFRESH_LOCK, () => {
        markGranted();

        return new Promise((resolve) => {
            release = resolve;
        });
    });
    await granted;

    const renewal = renewAccessToken('access-1-secret', storage, server.fetchFn, locks);
    await saveConnection(renewedByOtherPage, storage);
    release();
    await otherPage;

    assert.equal(await renewal, 'access-9-secret');
    assert.deepEqual(server.refreshesAsked, []);
    assert.deepEqual(await loadSettings(storage), renewedByOtherPage);
});

test('a renewal pinround did not answer lets the next one have the lock', async () => {
    for (const locks of [fakeLocks(), tokenRefreshLocksOf({})]) {
        const server = fakeOAuthServer();
        const browserApi = await connectedBrowser(server);
        server.liveAccessTokens.clear();

        await assert.rejects(renewAccessToken('access-1-secret', browserApi.storage.local, async () => { throw new TypeError('Failed to fetch'); }, locks), new ApiError(NOT_REACHED, 0));
        assert.equal(await renewAccessToken('access-1-secret', browserApi.storage.local, server.fetchFn, locks), 'access-2-secret');
    }
});

/**
 * The server's fetch, but a refresh's answer is held after pinround issued the new pair until `release()`: a renewal
 * under way, whose new tokens pinround already rotated to.
 */
function refreshHeldByNetwork(server) {
    let markReached;
    let release;
    const reached = new Promise((resolve) => {
        markReached = resolve;
    });
    const released = new Promise((resolve) => {
        release = resolve;
    });
    const fetchFn = async (url, init) => {
        const answer = await server.fetchFn(url, init);
        if ('/oauth/token' === new URL(url).pathname && 'refresh_token' === new URLSearchParams(init.body).get('grant_type')) {
            markReached();
            await released;
        }

        return answer;
    };

    return { fetchFn, reached, release };
}

// A save that skips the lock never makes the second request, so the time limit turns that wait into a failure.
test('Sign out during a renewal waits for it, revokes the renewed tokens and keeps them gone, and no refresh follows', { timeout: 5000 }, async () => {
    const server = fakeOAuthServer();
    const browserApi = await connectedBrowser(server);
    const before = await loadSettings(browserApi.storage.local);
    server.liveAccessTokens.delete('access-1-secret');
    const locks = fakeLocks();
    const network = refreshHeldByNetwork(server);

    const popupCall = pinroundApiOf(before, browserApi.storage.local, network.fetchFn, locks).call('GET', '/api/v1/me/tickets');
    await network.reached;
    const signedOut = signOut(browserApi.storage, server.fetchFn, locks);
    await locks.requested(2);
    network.release();

    assert.deepEqual(await popupCall, { tickets: [] });
    assert.equal(await signedOut, SIGNED_OUT);
    assert.equal(await loadSettings(browserApi.storage.local), null);
    assert.deepEqual(server.revoked.map((revocation) => revocation.token), ['refresh-2-secret', 'access-2-secret']);
    assert.equal(server.liveAccessTokens.has('access-2-secret'), false);

    await assert.rejects(pinroundApiOf(before, browserApi.storage.local, server.fetchFn, locks).call('GET', '/api/v1/me/tickets'), new ApiError(CONNECT_AGAIN, 401));
    assert.deepEqual(server.refreshesAsked, ['refresh-1-secret']);
    assert.equal(await loadSettings(browserApi.storage.local), null);
});

test('a Connect or a pasted token saved during a renewal is not overwritten by the renewed tokens', { timeout: 5000 }, async () => {
    const pastedToken = 'pr_abcdefghijklmnopqrstuvwxyz012345';
    const saveDuringRenewal = {
        Connect: async (browserApi, server, locks) => connectToPinround(ORIGIN, browserApi, { fetchFn: server.fetchFn, locks }),
        'a pasted token': async (browserApi, server, locks) => pair(ORIGIN, pastedToken, browserApi.storage, async (url, init) => (`Bearer ${pastedToken}` === init.headers.Authorization ? { ok: true, status: 200, json: async () => ({ tickets: [] }) } : server.fetchFn(url, init)), locks),
    };
    const kept = {};
    for (const [way, save] of Object.entries(saveDuringRenewal)) {
        const server = fakeOAuthServer();
        const browserApi = await connectedBrowser(server);
        const before = await loadSettings(browserApi.storage.local);
        server.liveAccessTokens.delete('access-1-secret');
        const locks = fakeLocks();
        const network = refreshHeldByNetwork(server);

        const renewal = renewAccessToken('access-1-secret', browserApi.storage.local, network.fetchFn, locks);
        await network.reached;
        const saved = save(browserApi, server, locks);
        await locks.requested(2);
        network.release();

        assert.equal(await renewal, 'access-2-secret');
        assert.equal(await saved, CONNECTED);
        kept[way] = (await loadSettings(browserApi.storage.local)).token;
        assert.notEqual(kept[way], before.token);
    }
    assert.deepEqual(kept, { Connect: 'access-3-secret', 'a pasted token': pastedToken });
});

test('the renewal lock is navigator.locks, which pages and the service worker share, when the browser has it', () => {
    const navigatorLocks = fakeLocks();

    assert.equal(tokenRefreshLocksOf({ locks: navigatorLocks }), navigatorLocks);
    assert.notEqual(tokenRefreshLocksOf({}), navigatorLocks);
    assert.equal(tokenRefreshLocksOf(null), tokenRefreshLocksOf({}));
    assert.equal(tokenRefreshLocksOf(), globalThis.navigator.locks);
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

    // Spelled out, so the words that send the person to the page where pinround ends the connection cannot drift.
    assert.equal(
        await signOut(browserApi.storage, async () => { throw new TypeError('Failed to fetch'); }),
        'Signed out here, but pinround could not be reached. End the connection under Connected apps on the API tokens page.',
    );
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
