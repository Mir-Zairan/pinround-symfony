// The pin overlay on the page (src/overlay/) as the popup drives it, and the capture of the page without it: pins are
// data that pinround draws, never pixels in the picture. Every call goes to the overlay's state in the extension's
// isolated world, never to the host element the page can change.
import { checkedPins } from '../overlay/pins.js';
import { pngOf } from './capture.js';

/** pin-rules.js first: overlay.js reads the rules it leaves on the global. Paths are from the extension's root. */
export const OVERLAY_FILES = ['src/overlay/pin-rules.js', 'src/overlay/overlay.js'];

export const CANNOT_PIN_PAGE = 'This page cannot be pinned. Open a web page and try again.';

/** @returns {Promise<number>} the id of the tab the popup was opened on */
export async function activeTabId(browser) {
    const [tab] = await browser.tabs.query({ active: true, currentWindow: true });
    if (undefined === tab) {
        throw new Error('Open the page you want to capture first.');
    }

    return tab.id;
}

/** Draws the overlay on the tab, or shows the one already there with its pins. */
export async function openPinOverlay(browser, tabId) {
    try {
        await browser.scripting.executeScript({ target: { tabId }, files: OVERLAY_FILES });
    } catch (error) {
        throw isOutOfReach(error) ? new Error(CANNOT_PIN_PAGE) : error;
    }
}

/** Closes the tab's overlay and drops its pins; the page may have gone since, so a refusal is no failure. */
export async function closePinOverlay(browser, tabId) {
    await browser.scripting.executeScript({ target: { tabId }, func: () => globalThis.pinroundOverlay?.close() }).catch(() => undefined);
}

/**
 * The visible tab as a PNG, taken while the tab's overlay is out of the page, with the overlay's pins in percent of
 * that picture. The overlay comes back afterwards, also when the capture or the pins are refused.
 *
 * @returns {Promise<{file: Blob, pins: Array<{x_pct: number, y_pct: number, note: string}>}>}
 * @throws {Error} with the pin rules' refusal, or Chrome's when the tab cannot be captured
 */
export async function capturePinnedTab(browser, tabId) {
    let injections;
    try {
        injections = await browser.scripting.executeScript({
            target: { tabId },
            func: async () => (undefined === globalThis.pinroundOverlay ? [] : globalThis.pinroundOverlay.hideForCapture()),
        });
    } catch (error) {
        // A page out of the extension's reach cannot hold an overlay: injecting one needs the same access. Chrome may
        // still capture it (a file:// page under activeTab), so it is captured with no pins.
        if (!isOutOfReach(error)) {
            throw error;
        }

        return { file: await visibleTabPng(browser), pins: [] };
    }
    try {
        const pins = checkedPins(injections[0]?.result);

        return { file: await visibleTabPng(browser), pins };
    } finally {
        // Only a page that went away mid-capture refuses; the pins stay in its state for the next "Pin this page".
        await browser.scripting.executeScript({ target: { tabId }, func: () => globalThis.pinroundOverlay?.show() }).catch(() => undefined);
    }
}

const isOutOfReach = (error) => error instanceof Error && error.message.startsWith('Cannot access ');

const visibleTabPng = async (browser) => pngOf(await browser.tabs.captureVisibleTab({ format: 'png' }));
