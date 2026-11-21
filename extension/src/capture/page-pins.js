// The pin overlay on the page (src/overlay/) as the popup drives it, and the capture of the page without it: pins are
// data that pinround draws, never pixels in the picture. Every call goes to the overlay's state in the extension's
// isolated world, never to the host element the page can change.
import { checkedPins } from '../overlay/pins.js';
import { pngOf } from './capture.js';

/** The rules first: overlay.js reads what pin-rules.js, dock.js and dock-run.js leave on the global. Paths are from the extension's root. */
export const OVERLAY_FILES = ['src/overlay/pin-rules.js', 'src/overlay/dock.js', 'src/overlay/dock-run.js', 'src/overlay/overlay.js'];

export const CANNOT_PIN_PAGE = 'This page cannot be pinned. Open a web page and try again.';
export const CANNOT_DOCK_PAGE = 'The dock cannot open on this page. Open a web page and try again.';
export const PAGE_NOT_ON_SCREEN = 'The page is no longer on screen, so nothing was captured. Go back to it and capture again.';

/** @returns {Promise<number>} the id of the tab the popup was opened on */
export async function activeTabId(browser) {
    const [tab] = await browser.tabs.query({ active: true, currentWindow: true });
    if (undefined === tab) {
        throw new Error('Open the page you want to capture first.');
    }

    return tab.id;
}

/** Draws the overlay on the tab, or shows the one already there with its pins, and turns pinning on. */
export async function openPinOverlay(browser, tabId) {
    await callOverlay(browser, tabId, () => globalThis.pinroundOverlay?.startPinning(), CANNOT_PIN_PAGE);
}

/** Opens the dock on the tab, in the overlay's root; pins already on the page stay. */
export async function openPageDock(browser, tabId) {
    await callOverlay(browser, tabId, () => globalThis.pinroundOverlay?.openDock(), CANNOT_DOCK_PAGE);
}

async function callOverlay(browser, tabId, func, outOfReachMessage) {
    try {
        await browser.scripting.executeScript({ target: { tabId }, files: OVERLAY_FILES });
        await browser.scripting.executeScript({ target: { tabId }, func });
    } catch (error) {
        throw isOutOfReach(error) ? new Error(outOfReachMessage) : error;
    }
}

/** Closes the tab's overlay and drops its pins; the page may have gone since, so a refusal is no failure. */
export async function closePinOverlay(browser, tabId) {
    await browser.scripting.executeScript({ target: { tabId }, func: () => globalThis.pinroundOverlay?.close() }).catch(() => undefined);
}

/**
 * The tab as a PNG, taken while the tab's overlay is out of the page, with the overlay's pins in percent of that
 * picture. The overlay comes back afterwards, also when the capture or the pins are refused. Chrome captures what a
 * window shows, so the tab must be the shown one of the window the person is in, before and after the capture: a
 * person who switched tabs while the capture was on its way would otherwise send another page with this one's pins.
 *
 * @returns {Promise<{file: Blob, pins: Array<{x_pct: number, y_pct: number, note: string}>}>}
 * @throws {Error} PAGE_NOT_ON_SCREEN, the pin rules' refusal, or Chrome's when the tab cannot be captured
 */
export async function capturePinnedTab(browser, tabId) {
    const windowId = await windowShowing(browser, tabId);
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

        return { file: await shownTabPng(browser, tabId, windowId), pins: [] };
    }
    try {
        const pins = checkedPins(injections[0]?.result);

        return { file: await shownTabPng(browser, tabId, windowId), pins };
    } finally {
        // Only a page that went away mid-capture refuses; the pins stay in its state for the next "Pin this page".
        await browser.scripting.executeScript({ target: { tabId }, func: () => globalThis.pinroundOverlay?.show() }).catch(() => undefined);
    }
}

const isOutOfReach = (error) => error instanceof Error && error.message.startsWith('Cannot access ');

/** @returns {Promise<number|null>} the window the tab shows in as the active tab of the person's window, else null */
async function shownWindowIdOf(browser, tabId) {
    const tab = await browser.tabs.get(tabId);
    const personWindow = await browser.windows.getLastFocused();

    return tab.active && tab.windowId === personWindow.id ? tab.windowId : null;
}

async function windowShowing(browser, tabId) {
    const windowId = await shownWindowIdOf(browser, tabId);
    if (null === windowId) {
        throw new Error(PAGE_NOT_ON_SCREEN);
    }

    return windowId;
}

/**
 * The window's picture, kept only when the tab is still the one it shows once Chrome answered: a switch meanwhile
 * throws the picture away, and is also what a refusal of Chrome's then meant (activeTab stays with the tab).
 */
async function shownTabPng(browser, tabId, windowId) {
    const { dataUrl, refusal } = await browser.tabs.captureVisibleTab(windowId, { format: 'png' }).then((taken) => ({ dataUrl: taken }), (error) => ({ refusal: error }));
    if (windowId !== await shownWindowIdOf(browser, tabId)) {
        throw new Error(PAGE_NOT_ON_SCREEN);
    }
    if (undefined !== refusal) {
        throw refusal;
    }

    return pngOf(dataUrl);
}
