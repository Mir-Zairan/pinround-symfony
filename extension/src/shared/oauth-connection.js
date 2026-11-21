// Connect to pinround with its OAuth server (ADR-0050): the extension registers itself once as a public client, the
// person allows it on pinround's consent page in the browser's sign-in window (identity.launchWebAuthFlow), and the issued access token is renewed with
// the refresh token when pinround stops accepting it. Tokens go only to pinround's own endpoints and into the
// extension's local storage; nothing here logs.
import { ApiError, NOT_REACHED, PinroundApi } from './api.js';
import { forgetTokens, loadSettings, pinroundOriginOf, saveConnection } from './settings.js';

export const DISCOVERY_PATH = '/.well-known/oauth-authorization-server';
export const CLIENT_KEY = 'pinroundOAuthClient';
export const CLIENT_NAME = 'pinround browser extension';
export const SCOPES = 'read rounds:write reports:write notebooks:write';
export const NOT_CONNECTED = 'The extension is not connected. Try Connect again.';
export const DENIED = 'You chose Deny, so the extension is not connected.';
export const NOT_OAUTH_SERVER = 'This pinround does not offer Connect. Use a personal access token instead.';
export const WRONG_ANSWER = 'pinround answered something the extension does not understand. Try Connect again.';

const ENDPOINT_FIELDS = ['authorization_endpoint', 'token_endpoint', 'registration_endpoint', 'revocation_endpoint'];
const VERIFIER_BYTES = 32;
const STATE_BYTES = 16;
const TOO_MANY_REQUESTS = 429;
const REQUEST = { credentials: 'omit', redirect: 'error', cache: 'no-store' };

export class ConnectRefused extends Error {}

const defaultFetch = (...args) => fetch(...args);
const defaultRandomBytes = (count) => crypto.getRandomValues(new Uint8Array(count));

function base64UrlOf(bytes) {
    return btoa(String.fromCharCode(...bytes)).replaceAll('+', '-').replaceAll('/', '_').replace(/=+$/, '');
}

/**
 * The S256 code challenge of a PKCE code verifier (RFC 7636 section 4.2): BASE64URL(SHA-256(ASCII(verifier))).
 *
 * @param {string} codeVerifier
 * @returns {Promise<string>}
 */
export async function pkceChallengeOf(codeVerifier) {
    return base64UrlOf(new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(codeVerifier))));
}

async function jsonOf(response) {
    const parsed = await response.json().catch(() => null);

    return null !== parsed && 'object' === typeof parsed && !Array.isArray(parsed) ? parsed : {};
}

async function sent(url, init, fetchFn) {
    try {
        return await fetchFn(url, { ...REQUEST, ...init });
    } catch {
        throw new ConnectRefused(NOT_REACHED);
    }
}

function postForm(url, fields, fetchFn) {
    return sent(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' }, body: new URLSearchParams(fields).toString() }, fetchFn);
}

/**
 * The endpoints pinround's discovery document names, each refused unless it is on pinround's own origin, so a token
 * never goes anywhere else.
 */
async function endpointsOf(origin, fetchFn) {
    const response = await sent(new URL(DISCOVERY_PATH, origin).href, { method: 'GET', headers: { Accept: 'application/json' } }, fetchFn);
    if (!response.ok) {
        throw new ConnectRefused(NOT_OAUTH_SERVER);
    }
    const metadata = await jsonOf(response);
    const isOwnEndpoint = (field) => {
        try {
            return new URL(metadata[field]).origin === origin;
        } catch {
            return false;
        }
    };
    if (!ENDPOINT_FIELDS.every(isOwnEndpoint)) {
        throw new ConnectRefused(NOT_OAUTH_SERVER);
    }

    return Object.fromEntries(ENDPOINT_FIELDS.map((field) => [field, metadata[field]]));
}

/** The client id registered for this origin and redirect address earlier, or a new registration kept for next time. */
async function clientIdOf(origin, redirectUri, endpoints, storageLocal, fetchFn) {
    const kept = (await storageLocal.get(CLIENT_KEY))[CLIENT_KEY];
    if (kept?.origin === origin && kept?.redirectUri === redirectUri && 'string' === typeof kept.clientId) {
        return kept.clientId;
    }
    const response = await sent(endpoints.registration_endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
            client_name: CLIENT_NAME,
            redirect_uris: [redirectUri],
            grant_types: ['authorization_code', 'refresh_token'],
            response_types: ['code'],
            token_endpoint_auth_method: 'none',
        }),
    }, fetchFn);
    const registration = await jsonOf(response);
    if (!response.ok) {
        throw new ConnectRefused('string' === typeof registration.error_description ? registration.error_description : WRONG_ANSWER);
    }
    if ('string' !== typeof registration.client_id || '' === registration.client_id) {
        throw new ConnectRefused(WRONG_ANSWER);
    }
    await storageLocal.set({ [CLIENT_KEY]: { origin, redirectUri, clientId: registration.client_id } });

    return registration.client_id;
}

/** @returns {{token: string, refreshToken: string}|null} the issued pair, or null when the answer is not one */
function issuedPairOf(answer) {
    if ('string' !== typeof answer.access_token || '' === answer.access_token || 'string' !== typeof answer.refresh_token || '' === answer.refresh_token) {
        return null;
    }

    return { token: answer.access_token, refreshToken: answer.refresh_token };
}

/**
 * Connects to the pinround at the typed address: reads its OAuth endpoints, registers the extension as a public client
 * the first time, opens the consent page in the browser's sign-in window (identity.launchWebAuthFlow) with a PKCE S256 challenge and a random state,
 * checks the state that comes back and trades the code for tokens. Keeps nothing but the client registration.
 *
 * @param {string} typedOrigin
 * @param {{identity: object, storage: {local: object}}} browserApi
 * @returns {Promise<{origin: string, token: string, refreshToken: string, clientId: string, tokenEndpoint: string,
 *   revocationEndpoint: string}>} the connection for settings.js saveConnection
 * @throws {SettingsError|ConnectRefused} with the message to show
 */
export async function connect(typedOrigin, browserApi, { fetchFn = defaultFetch, randomBytes = defaultRandomBytes } = {}) {
    const origin = pinroundOriginOf(typedOrigin);
    const endpoints = await endpointsOf(origin, fetchFn);
    const redirectUri = browserApi.identity.getRedirectURL();
    const clientId = await clientIdOf(origin, redirectUri, endpoints, browserApi.storage.local, fetchFn);
    const codeVerifier = base64UrlOf(randomBytes(VERIFIER_BYTES));
    const state = base64UrlOf(randomBytes(STATE_BYTES));
    const consentUrl = new URL(endpoints.authorization_endpoint);
    consentUrl.search = new URLSearchParams({
        response_type: 'code',
        client_id: clientId,
        redirect_uri: redirectUri,
        scope: SCOPES,
        state,
        code_challenge: await pkceChallengeOf(codeVerifier),
        code_challenge_method: 'S256',
    }).toString();

    let reached;
    try {
        reached = new URL(await browserApi.identity.launchWebAuthFlow({ url: consentUrl.href, interactive: true }));
    } catch {
        throw new ConnectRefused(NOT_CONNECTED);
    }
    if (`${reached.origin}${reached.pathname}` !== redirectUri || reached.searchParams.get('state') !== state) {
        throw new ConnectRefused(NOT_CONNECTED);
    }
    if ('access_denied' === reached.searchParams.get('error')) {
        throw new ConnectRefused(DENIED);
    }
    const code = reached.searchParams.get('code');
    if (null === code || '' === code) {
        throw new ConnectRefused(NOT_CONNECTED);
    }

    const response = await postForm(endpoints.token_endpoint, { grant_type: 'authorization_code', code, redirect_uri: redirectUri, client_id: clientId, code_verifier: codeVerifier }, fetchFn);
    const issued = response.ok ? issuedPairOf(await jsonOf(response)) : null;
    if (null === issued) {
        throw new ConnectRefused(NOT_CONNECTED);
    }

    return { origin, ...issued, clientId, tokenEndpoint: endpoints.token_endpoint, revocationEndpoint: endpoints.revocation_endpoint };
}

/**
 * A new access token after pinround refused `refusedToken`: the one another page already renewed, or one the refresh
 * token buys, kept with its rotated refresh token. When pinround refuses the refresh token, the tokens are forgotten
 * unless another page rotated them meanwhile.
 *
 * @returns {Promise<string|null>} the token to retry with, or null when the connection ended
 * @throws {ApiError} NOT_REACHED when pinround did not answer; the tokens stay
 */
export async function renewAccessToken(refusedToken, storageLocal, fetchFn = defaultFetch) {
    const connection = await loadSettings(storageLocal);
    if (null === connection || undefined === connection.refreshToken) {
        return null;
    }
    if (connection.token !== refusedToken) {
        return connection.token;
    }
    let response;
    try {
        response = await postForm(connection.tokenEndpoint, { grant_type: 'refresh_token', refresh_token: connection.refreshToken, client_id: connection.clientId }, fetchFn);
    } catch {
        throw new ApiError(NOT_REACHED, 0);
    }
    const issued = response.ok ? issuedPairOf(await jsonOf(response)) : null;
    if (null !== issued) {
        await saveConnection({ ...connection, ...issued }, storageLocal);

        return issued.token;
    }
    if (TOO_MANY_REQUESTS === response.status || response.status >= 500) {
        throw new ApiError(`pinround answered ${response.status}.`, response.status);
    }
    const now = await loadSettings(storageLocal);
    if (null !== now && now.refreshToken !== connection.refreshToken) {
        return now.token;
    }
    await forgetTokens(storageLocal);

    return null;
}

/**
 * The API client for the kept pairing: a Connect's access token is renewed once after a 401 (renewAccessToken), a
 * pasted token is not.
 */
export function pinroundApiOf(settings, storageLocal, fetchFn = undefined) {
    const renewToken = undefined === settings.refreshToken ? null : (refusedToken) => renewAccessToken(refusedToken, storageLocal, fetchFn);

    return new PinroundApi(settings.origin, settings.token, fetchFn, renewToken);
}

/**
 * Revokes a Connect's refresh token, then its access token (RFC 7009), at the revocation endpoint it was issued by.
 *
 * @returns {Promise<boolean>} true when pinround answered 200 to both
 */
export async function revokeConnection(connection, fetchFn = defaultFetch) {
    const revoked = async (token, hint) => {
        try {
            return (await postForm(connection.revocationEndpoint, { token, token_type_hint: hint, client_id: connection.clientId }, fetchFn)).ok;
        } catch {
            return false;
        }
    };
    const refreshRevoked = await revoked(connection.refreshToken, 'refresh_token');
    const accessRevoked = await revoked(connection.token, 'access_token');

    return refreshRevoked && accessRevoked;
}
