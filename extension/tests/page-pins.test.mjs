import assert from 'node:assert/strict';
import { test } from 'node:test';
import { CANNOT_DOCK_PAGE, CANNOT_PIN_PAGE, CAPTURED_TOO_FAST,OVERLAY_FILES, PAGE_NOT_ON_SCREEN, activeTabId, capturePinnedTab, closePinOverlay, openPageDock, openPinOverlay } from '../src/capture/page-pins.js';
import { ACTIVE_TAB_NOT_IN_EFFECT, ALL_URLS, FIRST_WINDOW_ID, NEEDS_ACTIVE_TAB, fakeBrowser } from './fake-browser.mjs';

const SHOP_PAGE = { id: 7, url: 'https://shop.acme-foods.example/cart', title: 'Acme Foods shop', active: true };
const SETTINGS_PAGE = { id: 9, url: 'chrome://settings/', title: 'Settings', active: true };
const NEWS_PAGE = { id: 10, url: 'https://news.acme-foods.example/', title: 'Acme Foods news' };
const OTHER_WINDOW_PAGE = { id: 11, url: 'https://mail.acme-foods.example/', title: 'Acme Foods mail', active: true, windowId: FIRST_WINDOW_ID + 1 };

const resultOf = async (browser, injection) => (await browser.scripting.executeScript(injection))[0].result;

/**
 * A browser on the shop page whose icon was clicked, with another tab beside it and one in a second window, logging
 * each capture into the page beside the overlay's calls. hostOrigins ALL_URLS lets Chrome capture whichever tab shows.
 */
function shopBrowser({ hostOrigins = [] } = {}) {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE, NEWS_PAGE, OTHER_WINDOW_PAGE], hostOrigins });
    browser.clickIcon(SHOP_PAGE.id);
    const captureVisibleTab = browser.tabs.captureVisibleTab;
    browser.tabs.captureVisibleTab = async (...windowAndOptions) => {
        await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => { (globalThis.overlayCalls ??= []).push('capture'); } });

        return captureVisibleTab(...windowAndOptions);
    };

    return browser;
}

/** An overlay in the page's isolated world, as overlay.js leaves it, whose hideForCapture answers `answer`. */
async function seedOverlay(browser, answer, { throwsOnHide = false } = {}) {
    await browser.scripting.executeScript({
        target: { tabId: SHOP_PAGE.id },
        args: [answer, throwsOnHide],
        func: (pins, throws) => {
            globalThis.overlayCalls = [];
            globalThis.pinroundOverlay = {
                async hideForCapture() {
                    globalThis.overlayCalls.push('hideForCapture');
                    if (throws) {
                        throw new Error('the page broke the overlay');
                    }

                    return pins;
                },
                show: () => { globalThis.overlayCalls.push('show'); },
                close: () => {
                    globalThis.overlayCalls.push('close');
                    delete globalThis.pinroundOverlay;
                },
            };
        },
    });
}

const overlayCallsOf = (browser) => resultOf(browser, { target: { tabId: SHOP_PAGE.id }, func: () => globalThis.overlayCalls ?? [] });

test('"Pin this page" injects the pin and dock rules, then the overlay, into the active tab, and starts pinning', async () => {
    const browser = shopBrowser();
    await seedOverlay(browser, []);
    await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => { globalThis.pinroundOverlay.startPinning = () => globalThis.overlayCalls.push('startPinning'); } });

    await openPinOverlay(browser, await activeTabId(browser));

    assert.deepEqual(OVERLAY_FILES, ['src/overlay/pin-rules.js', 'src/overlay/dock.js', 'src/overlay/dock-run.js', 'src/overlay/overlay.js']);
    assert.deepEqual(browser.injectedFiles, [{ tabId: SHOP_PAGE.id, files: OVERLAY_FILES }]);
    assert.deepEqual(await overlayCallsOf(browser), ['startPinning']);
});

test('the popup\'s "Open the dock" injects the same files and opens the dock, and a page out of reach says so', async () => {
    const browser = shopBrowser();
    await seedOverlay(browser, []);
    await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => { globalThis.pinroundOverlay.openDock = () => globalThis.overlayCalls.push('openDock'); } });

    await openPageDock(browser, SHOP_PAGE.id);

    assert.deepEqual(browser.injectedFiles, [{ tabId: SHOP_PAGE.id, files: OVERLAY_FILES }]);
    assert.deepEqual(await overlayCallsOf(browser), ['openDock']);
    const settings = fakeBrowser({ tabs: [SETTINGS_PAGE], hostOrigins: [ALL_URLS] });
    await assert.rejects(openPageDock(settings, SETTINGS_PAGE.id), { message: CANNOT_DOCK_PAGE });
    assert.equal(CANNOT_DOCK_PAGE, 'The dock cannot open on this page. Open a web page and try again.');
});

test('a page out of reach cannot be pinned, with a plain message; any other refusal keeps Chrome\'s', async () => {
    const browser = fakeBrowser({ tabs: [SETTINGS_PAGE], hostOrigins: [ALL_URLS] });

    await assert.rejects(openPinOverlay(browser, SETTINGS_PAGE.id), { message: CANNOT_PIN_PAGE });
    assert.equal(CANNOT_PIN_PAGE, 'This page cannot be pinned. Open a web page and try again.');
    await assert.rejects(openPinOverlay(browser, 99), { message: 'No tab with id: 99' });
    assert.deepEqual(browser.injectedFiles, []);
});

test('with no active tab there is nothing to pin or capture', async () => {
    const browser = fakeBrowser({ tabs: [{ ...SHOP_PAGE, active: false }] });

    await assert.rejects(activeTabId(browser), { message: 'Open the page you want to capture first.' });
});

test('a capture hides the overlay, takes the tab, shows the overlay again, and answers the checked pins', async () => {
    const browser = shopBrowser();
    await seedOverlay(browser, [{ x_pct: 25, y_pct: 33.333333, note: ' Logo is cut off ' }, { x_pct: 70, y_pct: 55, note: '' }]);

    const { file, pins } = await capturePinnedTab(browser, SHOP_PAGE.id);

    assert.deepEqual(pins, [{ x_pct: 25, y_pct: 33.33, note: 'Logo is cut off' }, { x_pct: 70, y_pct: 55, note: '' }]);
    assert.equal(file.type, 'image/png');
    assert.deepEqual(await overlayCallsOf(browser), ['hideForCapture', 'capture', 'show']);
});

test('a page with no overlay is captured with no pins', async () => {
    const browser = shopBrowser();

    const { file, pins } = await capturePinnedTab(browser, SHOP_PAGE.id);

    assert.deepEqual(pins, []);
    assert.equal(file.type, 'image/png');
    assert.deepEqual(await overlayCallsOf(browser), ['capture']);
});

test('pins the page changed are refused before the capture, and the overlay comes back with them', async () => {
    for (const [answer, message] of [
        [[{ x_pct: 100.5, y_pct: 10, note: '' }], 'A pin is outside the picture. Pin the page again.'],
        [[{ x_pct: 10, y_pct: 10, note: 'a'.repeat(1001) }], 'Keep each pin note to 1000 characters or fewer.'],
        [Array.from({ length: 101 }, () => ({ x_pct: 1, y_pct: 1, note: '' })), 'A picture holds at most 100 pins.'],
        [{ pins: [] }, 'The pins on this page could not be read. Pin the page again.'],
    ]) {
        const browser = shopBrowser();
        await seedOverlay(browser, answer);

        await assert.rejects(capturePinnedTab(browser, SHOP_PAGE.id), { message });
        assert.deepEqual(await overlayCallsOf(browser), ['hideForCapture', 'show'], message);
    }
});

test('an overlay that throws while hiding is unreadable, never no pins, and is shown again', async () => {
    const browser = shopBrowser();
    await seedOverlay(browser, [], { throwsOnHide: true });

    await assert.rejects(capturePinnedTab(browser, SHOP_PAGE.id), { message: 'The pins on this page could not be read. Pin the page again.' });
    assert.deepEqual(await overlayCallsOf(browser), ['hideForCapture', 'show']);
});

test('a page out of reach holds no overlay, so the capture goes on and answers Chrome\'s own refusal', async () => {
    const browser = fakeBrowser({ tabs: [SETTINGS_PAGE], hostOrigins: [ALL_URLS] });

    await assert.rejects(capturePinnedTab(browser, SETTINGS_PAGE.id), { message: ACTIVE_TAB_NOT_IN_EFFECT });
});

test('a refusal that is not about reach stops the capture', async () => {
    const browser = shopBrowser();
    let captures = 0;
    browser.tabs.captureVisibleTab = async () => {
        captures += 1;

        return '';
    };

    await assert.rejects(capturePinnedTab(browser, 99), { message: 'No tab with id: 99.' });
    assert.equal(captures, 0);
});

test('Chrome\'s refusal of a third capture in one second is said in plain words, and the pins come back on the page', async () => {
    const browser = shopBrowser();
    await seedOverlay(browser, [{ x_pct: 25, y_pct: 40, note: 'Total' }]);
    browser.tabs.captureVisibleTab = async () => {
        throw new Error('This request exceeds the MAX_CAPTURE_VISIBLE_TAB_CALLS_PER_SECOND quota.');
    };

    await assert.rejects(capturePinnedTab(browser, SHOP_PAGE.id), { message: CAPTURED_TOO_FAST });
    assert.deepEqual(await overlayCallsOf(browser), ['hideForCapture', 'show']);
    assert.equal(CAPTURED_TOO_FAST, 'Chrome takes at most two captures a second, so nothing was captured. Press Capture again.');
});

test('a tab that is not on screen is refused before the overlay hides, and nothing is captured', async () => {
    for (const goElsewhere of [
        (browser) => browser.showTab(NEWS_PAGE.id),
        (browser) => browser.showTab(OTHER_WINDOW_PAGE.id),
    ]) {
        const browser = shopBrowser();
        await seedOverlay(browser, [{ x_pct: 25, y_pct: 40, note: 'Total' }]);
        goElsewhere(browser);

        await assert.rejects(capturePinnedTab(browser, SHOP_PAGE.id), { message: PAGE_NOT_ON_SCREEN });
        assert.deepEqual(await overlayCallsOf(browser), []);
    }
    assert.equal(PAGE_NOT_ON_SCREEN, 'The page is no longer on screen, so nothing was captured. Go back to it and capture again.');
});

/** The browser with the person going to `tabId` as the overlay hides, and the windows each capture named. */
function switchingWhileHiding(browser, tabId) {
    const capturedWindows = [];
    const captureVisibleTab = browser.tabs.captureVisibleTab;
    browser.tabs.captureVisibleTab = async (windowId, options) => {
        capturedWindows.push(windowId);

        return captureVisibleTab(windowId, options);
    };
    const executeScript = browser.scripting.executeScript;
    browser.scripting.executeScript = (injection) => {
        const answer = executeScript(injection);
        if (String(injection.func).includes('hideForCapture')) {
            browser.showTab(tabId);
        }

        return answer;
    };

    return capturedWindows;
}

test('a switch to another tab while the overlay hides throws the picture away, or turns Chrome\'s refusal into the plain one, and the pins stay', async () => {
    for (const [hostOrigins, switchTo, chromeTook] of [
        [[ALL_URLS], NEWS_PAGE.id, 'the news page'],
        [[], NEWS_PAGE.id, 'nothing: activeTab stays with the shop page'],
        [[ALL_URLS], OTHER_WINDOW_PAGE.id, 'the shop page, which is no longer in front'],
    ]) {
        const browser = shopBrowser({ hostOrigins });
        await seedOverlay(browser, [{ x_pct: 25, y_pct: 40, note: 'Total' }]);
        const capturedWindows = switchingWhileHiding(browser, switchTo);

        await assert.rejects(capturePinnedTab(browser, SHOP_PAGE.id), { message: PAGE_NOT_ON_SCREEN }, chromeTook);
        assert.deepEqual(capturedWindows, [FIRST_WINDOW_ID], chromeTook);
        assert.deepEqual(await overlayCallsOf(browser), ['hideForCapture', 'capture', 'show'], chromeTook);
        assert.deepEqual(await resultOf(browser, { target: { tabId: SHOP_PAGE.id }, func: () => globalThis.pinroundOverlay.hideForCapture() }), [{ x_pct: 25, y_pct: 40, note: 'Total' }]);
    }
});

test('Chrome\'s own refusal of a tab still on screen keeps its message', async () => {
    const iconNotClicked = fakeBrowser({ tabs: [SHOP_PAGE] });

    await assert.rejects(capturePinnedTab(iconNotClicked, SHOP_PAGE.id), { message: NEEDS_ACTIVE_TAB });
});

test('closing the overlay drops it from the page, and a tab that went away is no failure', async () => {
    const browser = shopBrowser();
    await seedOverlay(browser, []);

    await closePinOverlay(browser, SHOP_PAGE.id);
    await closePinOverlay(browser, 99);

    assert.deepEqual(await overlayCallsOf(browser), ['close']);
    assert.equal(await resultOf(browser, { target: { tabId: SHOP_PAGE.id }, func: () => 'pinroundOverlay' in globalThis }), false);
});
