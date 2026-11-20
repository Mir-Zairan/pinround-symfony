// Pairing and sign-out (P10.T8), apart from the options page so they can be tested without a browser.
import { ApiError, PinroundApi } from '../shared/api.js';
import { browser } from '../shared/browser.js';
import { checkedToken, clearSettings, loadSettings, pinroundOriginOf, saveSettings } from '../shared/settings.js';

export const CONNECTED = 'Connected. You can capture pages now.';
export const SIGNED_OUT = 'Signed out. The token no longer works.';
export const SIGNED_OUT_LOCALLY = 'Signed out here, but pinround could not be reached. Revoke the token on the API tokens page.';

/**
 * Checks the address and the token with one call to pinround, then keeps them. Nothing is kept when either is refused.
 *
 * @throws {SettingsError|ApiError} with the message to show
 */
export async function pair(typedOrigin, typedToken, storage = browser.storage.local, fetchFn = undefined) {
    const origin = pinroundOriginOf(typedOrigin);
    const token = checkedToken(typedToken);
    await new PinroundApi(origin, token, fetchFn).call('GET', '/api/v1/me/tickets');
    await saveSettings(origin, token, storage);

    return CONNECTED;
}

/**
 * Revokes the token in pinround, then forgets it here whatever pinround answered: a token pinround no longer accepts
 * (401) counts as revoked.
 *
 * @returns {Promise<string>} the message to show
 */
export async function signOut(storage = browser.storage.local, fetchFn = undefined) {
    const settings = await loadSettings(storage);
    if (null === settings) {
        return SIGNED_OUT;
    }
    let message = SIGNED_OUT;
    try {
        await new PinroundApi(settings.origin, settings.token, fetchFn).call('POST', '/api/v1/me/token/revoke');
    } catch (error) {
        if (!(error instanceof ApiError) || 401 !== error.status) {
            message = SIGNED_OUT_LOCALLY;
        }
    }
    await clearSettings(storage);

    return message;
}
