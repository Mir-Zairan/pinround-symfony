import assert from 'node:assert/strict';
import { test } from 'node:test';
import { captureToTarget } from '../src/capture/capture-to-target.js';
import { NEW_REPORT } from '../src/capture/capture-target.js';
import { NEW_REPORT_KEY, loadNewReport, saveTypedReport } from '../src/capture/new-report.js';
import { PAGE_NOT_ON_SCREEN } from '../src/capture/page-pins.js';
import { PinroundApi } from '../src/shared/api.js';
import { ALL_URLS, fakeBrowser } from './fake-browser.mjs';
import { PINROUND_ORIGIN, TOKEN, fakePinround } from './fake-pinround.mjs';

// The tester switches tabs while a capture is on its way (from the dock or the popup): the other tab, a mail or a bank
// page, must never reach pinround with this page's pins. Every target kind goes through capturePinnedTab's check.

const SHOP_PAGE = { id: 7, url: 'https://shop.acme-foods.example/cart', title: 'Acme Foods shop', active: true };
const MAIL_PAGE = { id: 8, url: 'https://mail.acme-foods.example/inbox', title: 'Acme Foods mail' };
const TICKETS = [{ key: 'SHOP-12', title: 'Logo is cut off', step: 'Checking', now: 'Mia checks it', is_your_turn: true, is_assignee: true, updated_at: '2026-03-01T09:00:00+00:00' }];
const NOTEBOOKS = [{ notebook_id: 'b0000000-0000-7000-8000-000000000351', title: 'Checkout', owner: 'lena', last_used_at: '2026-03-02T09:00:00+00:00' }];
const PIN = { x_pct: 10, y_pct: 20, note: 'Total' };
const TYPED = { project: 'SHOP', reportTitle: 'Basket total is wrong' };

/** Every web page in reach, as the test copy grants, so Chrome would capture whichever tab is in front. */
async function shopWithPinnedOverlay() {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE, MAIL_PAGE], hostOrigins: [ALL_URLS] });
    await browser.scripting.executeScript({
        target: { tabId: SHOP_PAGE.id },
        args: [PIN],
        func: (pin) => {
            globalThis.overlayCalls = [];
            globalThis.pinroundOverlay = {
                hideForCapture: async () => [pin],
                show: () => globalThis.overlayCalls.push('show'),
                close: () => globalThis.overlayCalls.push('close'),
            };
        },
    });

    return browser;
}

const overlayCallsOf = async (browser) => (await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => globalThis.overlayCalls }))[0].result;

/** The session storage, with the person going to the mail tab the first time the capture reads it. */
function switchingOnFirstRead(browser) {
    const get = browser.storage.session.get;
    let hasSwitched = false;
    browser.storage.session.get = (keys) => {
        if (!hasSwitched) {
            hasSwitched = true;
            browser.showTab(MAIL_PAGE.id);
        }

        return get(keys);
    };

    return browser.storage.session;
}

for (const [targetName, target, seed] of [
    ['a draft round', { kind: 'ticket', ticketKey: 'SHOP-12' }, () => undefined],
    ['a notebook', { kind: 'notebook', notebookId: NOTEBOOKS[0].notebook_id, title: 'Checkout' }, () => undefined],
    ['a new report', { kind: NEW_REPORT }, (storage) => saveTypedReport(storage, TYPED)],
]) {
    test(`a switch to another tab while a capture into ${targetName} is on its way uploads nothing, and the pins stay on the page`, async () => {
        const browser = await shopWithPinnedOverlay();
        await seed(browser.storage.session);
        const storedBefore = await browser.storage.session.get(null);
        const pinround = fakePinround({ tickets: TICKETS, notebooks: NOTEBOOKS });
        const api = new PinroundApi(PINROUND_ORIGIN, TOKEN, pinround.fetchFn);
        const storage = switchingOnFirstRead(browser);
        if (NEW_REPORT !== target.kind) {
            browser.showTab(MAIL_PAGE.id);
        }

        await assert.rejects(captureToTarget({ api, browser, storage, tabId: SHOP_PAGE.id, target, typed: TYPED, texts: {} }), { message: PAGE_NOT_ON_SCREEN });

        assert.deepEqual(pinround.requests, []);
        assert.deepEqual(await overlayCallsOf(browser), []);
        assert.deepEqual(await browser.storage.session.get(null), storedBefore);
    });
}

test('a capture into a new report with the page still in front is saved, so the switch is what refuses above', async () => {
    const browser = await shopWithPinnedOverlay();
    await saveTypedReport(browser.storage.session, TYPED);
    const pinround = fakePinround({ tickets: TICKETS, notebooks: NOTEBOOKS });
    const api = new PinroundApi(PINROUND_ORIGIN, TOKEN, pinround.fetchFn);

    const message = await captureToTarget({ api, browser, storage: browser.storage.session, tabId: SHOP_PAGE.id, target: { kind: NEW_REPORT }, typed: TYPED, texts: {} });

    assert.equal(message, 'Report draft made with screenshot 1. Capture more pages, or press Done to open it.');
    assert.equal((await loadNewReport(browser.storage.session)).screenshotCount, 1);
    assert.deepEqual(await overlayCallsOf(browser), ['show', 'close']);
    assert.ok(NEW_REPORT_KEY in await browser.storage.session.get(null));
});
