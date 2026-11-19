// The pinround address and the paired token, kept in chrome.storage.local only: never synced to other devices, and
// never readable by a web page.

const STORAGE_KEY = 'pinround';
const TOKEN_PATTERN = /^pr_[A-Za-z0-9_-]{16,200}$/;
const LOCAL_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]']);

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

/** @returns {Promise<{origin: string, token: string}|null>} */
export async function loadSettings(storage = chrome.storage.local) {
    const stored = (await storage.get(STORAGE_KEY))[STORAGE_KEY];
    if (!stored || 'string' !== typeof stored.origin || 'string' !== typeof stored.token) {
        return null;
    }

    return { origin: stored.origin, token: stored.token };
}

export async function saveSettings(origin, token, storage = chrome.storage.local) {
    await storage.set({ [STORAGE_KEY]: { origin: pinroundOriginOf(origin), token: checkedToken(token) } });
}

export async function clearSettings(storage = chrome.storage.local) {
    await storage.remove(STORAGE_KEY);
}
