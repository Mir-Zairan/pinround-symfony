// The pinround address and the paired or connected tokens, kept in the extension's local storage only: never synced to
// other devices, and never readable by a web page.
import { CONFIRMED_HOSTS_KEY } from './allowed-hosts.js';
import { browser } from './browser.js';

const STORAGE_KEY = 'pinround';
const TOKEN_PATTERN = /^pr_[A-Za-z0-9_-]{16,200}$/;
const LOCAL_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]']);
const CONNECTION_FIELDS = ['refreshToken', 'clientId', 'tokenEndpoint', 'revocationEndpoint'];

export class SettingsError extends Error {}

/**
 * The origin of a pinround address as typed: https for any host, plain http only for this computer. Anything with a
 * path, a query, a user name or another scheme is refused, so a request can only ever go to that origin.
 *
 * @param {string} typed
 * @returns {string} for example https://pinround.acme-foods.example
 */
export function pinroundOriginOf(typed) {
    let url;
    try {
        url = new URL(typed.trim());
    } catch {
        throw new SettingsError('Type the address you open pinround at, for example https://pinround.acme-foods.example.');
    }
    const isLocal = 'http:' === url.protocol && LOCAL_HOSTS.has(url.hostname);
    if (!('https:' === url.protocol || isLocal) || '' !== url.username || '' !== url.password || ('/' !== url.pathname && '' !== url.pathname) || '' !== url.search || '' !== url.hash) {
        throw new SettingsError('Use the address only, starting with https:// (http:// only for localhost).');
    }

    return url.origin;
}

/**
 * @param {string} typed
 * @returns {string} the token without spaces around it
 */
export function checkedToken(typed) {
    const token = typed.trim();
    if (!TOKEN_PATTERN.test(token)) {
        throw new SettingsError('Paste the whole token. It starts with pr_.');
    }

    return token;
}

/**
 * The pairing: a pasted personal access token ({origin, token}), or a Connect (oauth-connection.js) whose token is the
 * issued access token, with the refresh token, the client id and the token and revocation endpoints it was issued by.
 *
 * @returns {Promise<{origin: string, token: string, refreshToken?: string, clientId?: string, tokenEndpoint?: string,
 *   revocationEndpoint?: string}|null>}
 */
export async function loadSettings(storage = browser.storage.local) {
    const stored = (await storage.get(STORAGE_KEY))[STORAGE_KEY];
    if (!stored || 'string' !== typeof stored.origin || 'string' !== typeof stored.token) {
        return null;
    }
    const connection = CONNECTION_FIELDS.every((field) => 'string' === typeof stored[field])
        ? Object.fromEntries(CONNECTION_FIELDS.map((field) => [field, stored[field]]))
        : {};

    return { origin: stored.origin, token: stored.token, ...connection };
}

export async function saveSettings(origin, token, storage = browser.storage.local) {
    await storage.set({ [STORAGE_KEY]: { origin: pinroundOriginOf(origin), token: checkedToken(token) } });
}

/**
 * Keeps a Connect's tokens; a second save with a refreshed pair replaces the first.
 *
 * @param {{origin: string, token: string, refreshToken: string, clientId: string, tokenEndpoint: string,
 *   revocationEndpoint: string}} connection
 */
export async function saveConnection(connection, storage = browser.storage.local) {
    await storage.set({ [STORAGE_KEY]: { origin: connection.origin, token: connection.token, ...Object.fromEntries(CONNECTION_FIELDS.map((field) => [field, connection[field]])) } });
}

/** Forgets the pairing's tokens only, when pinround refused to refresh them; the allowed and confirmed hosts stay. */
export async function forgetTokens(storage = browser.storage.local) {
    await storage.remove(STORAGE_KEY);
}

/** Forgets the pairing and the hosts the person said "Play anyway" for; the allowed hosts stay. */
export async function clearSettings(storage = browser.storage.local) {
    await storage.remove([STORAGE_KEY, CONFIRMED_HOSTS_KEY]);
}
