// Connect (P14.T20), pairing with a pasted token and sign-out (P10.T8), apart from the options page so they can be
// tested without a browser.
import { forgetCaptureSession } from '../capture/new-report.js';
import { ApiError, PinroundApi } from '../shared/api.js';
import { browser } from '../shared/browser.js';
import { TOKEN_REFRESH_LOCK, connect, revokeConnection, tokenRefreshLocksOf } from '../shared/oauth-connection.js';
import { checkedToken, clearSettings, loadSettings, pinroundOriginOf, saveConnection, saveSettings } from '../shared/settings.js';

export const CONNECTED = 'Connected. You can capture pages now.';
export const SIGNED_OUT = 'Signed out. The token no longer works.';
export const SIGNED_OUT_LOCALLY = 'Signed out here, but pinround could not be reached. Revoke the token on the API tokens page.';
export const CONNECTION_SIGNED_OUT_LOCALLY = 'Signed out here, but pinround could not be reached, so it may still accept the connection.';

/**
 * Checks the address and the token with one call to pinround, then keeps them. Nothing is kept when either is refused.
 * What the popup kept for captures under the earlier pairing is forgotten, so it is never sent with this token. Saved
 * under the TOKEN_REFRESH_LOCK, so a renewal of an earlier Connect never writes its tokens over this one.
 *
 * @param {{local: object, session: object}} storage the extension's storage areas
 * @throws {SettingsError|ApiError} with the message to show
 */
export async function pair(typedOrigin, typedToken, storage = browser.storage, fetchFn = undefined, locks = tokenRefreshLocksOf()) {
    const origin = pinroundOriginOf(typedOrigin);
    const token = checkedToken(typedToken);
    await new PinroundApi(origin, token, fetchFn).call('GET', '/api/v1/me/tickets');
    await forgetCaptureSession(storage.session);
    await locks.request(TOKEN_REFRESH_LOCK, () => saveSettings(origin, token, storage.local));

    return CONNECTED;
}

/**
 * Connects with pinround's consent page (oauth-connection.js connect), then keeps the issued tokens. Nothing is kept
 * when the person denies, closes the window or pinround refuses; what the popup kept for captures is forgotten. Saved
 * under the TOKEN_REFRESH_LOCK, so a renewal of the earlier Connect never writes its tokens over the new ones.
 *
 * @param {{identity: object, storage: {local: object, session: object}}} browserApi
 * @param {{fetchFn?: typeof fetch, randomBytes?: (count: number) => Uint8Array, locks?: object}} connectOptions
 * @throws {SettingsError|ConnectRefused} with the message to show
 */
export async function connectToPinround(typedOrigin, browserApi = browser, { locks = tokenRefreshLocksOf(), ...connectOptions } = {}) {
    const connection = await connect(typedOrigin, browserApi, connectOptions);
    await forgetCaptureSession(browserApi.storage.session);
    await locks.request(TOKEN_REFRESH_LOCK, () => saveConnection(connection, browserApi.storage.local));

    return CONNECTED;
}

/**
 * Revokes the token in pinround, then forgets it here whatever pinround answered: a token pinround no longer accepts
 * (401) counts as revoked. A Connect's refresh and access tokens are revoked at its revocation endpoint. What the popup
 * kept for captures is forgotten with it. Runs under the TOKEN_REFRESH_LOCK, so a renewal under way ends first and its
 * tokens are the ones revoked and forgotten, and one waiting finds no tokens.
 *
 * @param {{local: object, session: object}} storage the extension's storage areas
 * @returns {Promise<string>} the message to show
 */
export async function signOut(storage = browser.storage, fetchFn = undefined, locks = tokenRefreshLocksOf()) {
    await forgetCaptureSession(storage.session);

    return locks.request(TOKEN_REFRESH_LOCK, () => signedOutUnderLock(storage, fetchFn));
}

async function signedOutUnderLock(storage, fetchFn) {
    const settings = await loadSettings(storage.local);
    if (null === settings) {
        return SIGNED_OUT;
    }
    if (undefined !== settings.refreshToken) {
        const isRevoked = await revokeConnection(settings, fetchFn);
        await clearSettings(storage.local);

        return isRevoked ? SIGNED_OUT : CONNECTION_SIGNED_OUT_LOCALLY;
    }
    let message = SIGNED_OUT;
    try {
        await new PinroundApi(settings.origin, settings.token, fetchFn).call('POST', '/api/v1/me/token/revoke');
    } catch (error) {
        if (!(error instanceof ApiError) || 401 !== error.status) {
            message = SIGNED_OUT_LOCALLY;
        }
    }
    await clearSettings(storage.local);

    return message;
}
