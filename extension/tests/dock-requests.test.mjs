import assert from 'node:assert/strict';
import { test } from 'node:test';
import { DOCK_TEXTS, answerDockRequest } from '../src/background/dock-requests.js';
import { CAPTURE_TARGET_KEY, CHOOSE_TARGET_FIRST, NEW_REPORT, goneMessageOf, loadCaptureTarget, saveCaptureTarget } from '../src/capture/capture-target.js';
import { NEW_REPORT_KEY, REPORT_KEPT_IN_PINROUND, REPORT_OPENED, SEND_AGAIN_FIRST } from '../src/capture/new-report.js';
import { PinroundApi } from '../src/shared/api.js';
import { saveSettings } from '../src/shared/settings.js';
import { fakeBrowser } from './fake-browser.mjs';
import { PINROUND_ORIGIN, TOKEN, fakePinround } from './fake-pinround.mjs';

const SHOP_ORIGIN = 'https://shop.acme-foods.example';
const SHOP_TAB = { id: 7, url: `${SHOP_ORIGIN}/cart`, title: 'Acme Foods shop', active: true };
const NOTEBOOK_ID = 'nb000000-0000-4000-8000-000000000001';
const DRAFT_ID = 'rd000000-0000-4000-8000-000000000001';
const DRAFT_URL = `${PINROUND_ORIGIN}/report-drafts/${DRAFT_ID}`;
const TICKETS = [{ key: 'SHOP-12', title: 'Logo is cut off', step: 'Checking', now: 'Mia checks it', is_your_turn: true, is_assignee: true, updated_at: '2026-03-01T09:00:00+00:00' }];
const NOTEBOOKS = [{ notebook_id: NOTEBOOK_ID, title: 'Checkout', last_used_at: '2026-03-01T08:00:00+00:00' }];
const NEW_REPORT_TARGET = { kind: NEW_REPORT };
const SHOP_12 = { kind: 'ticket', ticketKey: 'SHOP-12' };
const SHOP_77 = { kind: 'ticket', ticketKey: 'SHOP-77' };
const OFFERED_CHOICES = [
    { value: NEW_REPORT, label: 'A new report' },
    { value: 'ticket:SHOP-12', label: 'My draft round on SHOP-12' },
    { value: `notebook:${NOTEBOOK_ID}`, label: 'Notebook: Checkout' },
];
const SHOP_12_ADDED = 'Added to your draft round on SHOP-12. Only you see it.';

/** A report draft as new-report.js keeps it in session storage, with two screenshots made and nothing on its way. */
const madeNewReport = (changes = {}) => ({ project: 'SHOP', reportTitle: 'Logo is cut off', draftId: DRAFT_ID, draftUrl: DRAFT_URL, screenshotCount: 2, pendingCapture: null, ...changes });
const PENDING_CAPTURE = { idempotencyKey: 'key-1', path: `/api/v1/reports/${DRAFT_ID}/screenshots`, bodyJson: '{"upload_id":"upload-9"}', origin: PINROUND_ORIGIN };

/** The extension on the shop tab, its icon clicked, paired unless told otherwise, with this target and new report kept. */
async function dockBrowser({ isPaired = true, target = null, newReport = null } = {}) {
    const browser = fakeBrowser({ tabs: [SHOP_TAB] });
    browser.clickIcon(SHOP_TAB.id);
    if (isPaired) {
        await saveSettings(PINROUND_ORIGIN, TOKEN, browser.storage.local);
    }
    if (null !== target) {
        await saveCaptureTarget(browser.storage.session, target);
    }
    if (null !== newReport) {
        await browser.storage.session.set({ [NEW_REPORT_KEY]: newReport });
    }

    return browser;
}

/** pinround with SHOP-12 and the Checkout notebook, and the apiOf that answers through it, counting each api made. */
function pinroundFor(seed = {}) {
    const pinround = fakePinround({ tickets: TICKETS, notebooks: NOTEBOOKS, ...seed });
    const made = { count: 0 };
    const apiOf = (settings) => {
        made.count += 1;

        return new PinroundApi(settings.origin, settings.token, pinround.fetchFn);
    };

    return { pinround, apiOf, made };
}

const ask = (browser, request, apiOf, tab = SHOP_TAB) => answerDockRequest(request, tab, { browser, navigator: {}, apiOf });
const storedState = async (browser) => ({ local: await browser.storage.local.get(null), session: await browser.storage.session.get(null) });
/** The address of each open tab, also one the extension may not see, as the person sees them. */
const openTabUrls = async (browser) => (await browser.tabs.query({})).map((tab) => browser.tabUrl(tab.id));
const writesOf = (pinround) => pinround.requests.filter(([method]) => 'GET' !== method);

/** Puts a stand-in overlay holding one pin on the shop tab, as captureToTarget finds it. */
async function putPinOnPage(browser) {
    await browser.scripting.executeScript({
        target: { tabId: SHOP_TAB.id },
        func: () => {
            globalThis.pinroundOverlay = { hideForCapture: async () => [{ x_pct: 10, y_pct: 20, note: 'Total' }], show: () => {}, close: () => {} };
        },
    });
}

test('every action is refused with "connect first" before pairing, and nothing is asked, stored or opened', async () => {
    const browser = await dockBrowser({ isPaired: false, target: NEW_REPORT_TARGET, newReport: madeNewReport() });
    const before = await storedState(browser);
    const { pinround, apiOf, made } = pinroundFor();
    assert.notDeepEqual(before.session, {}, 'The session holds a target and a report that must stay.');

    for (const request of [{ pinroundDock: 'state' }, { pinroundDock: 'choose', value: '' }, { pinroundDock: 'capture' }, { pinroundDock: 'save-draft' }]) {
        assert.deepEqual(await ask(browser, request, apiOf), { message: DOCK_TEXTS.notPaired, isError: true }, request.pinroundDock);
    }

    assert.deepEqual(await storedState(browser), before);
    assert.equal(made.count, 0);
    assert.deepEqual(pinround.requests, []);
    assert.deepEqual(await openTabUrls(browser), [SHOP_TAB.url]);
});

test('an action the dock does not have, or a sender with no tab id, is an unknown request that changes nothing', async () => {
    const browser = await dockBrowser({ target: SHOP_12, newReport: madeNewReport() });
    const before = await storedState(browser);
    const { pinround, apiOf, made } = pinroundFor();
    const cases = [
        ['an unknown action', { pinroundDock: 'forget-everything' }, SHOP_TAB],
        ['an action named in another case', { pinroundDock: 'State' }, SHOP_TAB],
        ['a sender with no tab id', { pinroundDock: 'state' }, { url: SHOP_TAB.url }],
        ['a tab id that is no integer', { pinroundDock: 'choose', value: '' }, { ...SHOP_TAB, id: '7' }],
    ];

    for (const [name, request, tab] of cases) {
        assert.deepEqual(await ask(browser, request, apiOf, tab), { message: DOCK_TEXTS.unknownRequest, isError: true }, name);
    }

    assert.deepEqual(await storedState(browser), before);
    assert.equal(made.count, 0);
    assert.deepEqual(pinround.requests, []);
});

test('settings that cannot be read answer "something went wrong", not "connect first", and change nothing', async () => {
    const browser = await dockBrowser({ target: SHOP_12 });
    const before = await storedState(browser);
    browser.storage.local.get = async () => {
        throw new Error('Extension context invalidated.');
    };
    const { apiOf, made } = pinroundFor();

    assert.deepEqual(await ask(browser, { pinroundDock: 'state' }, apiOf), { message: DOCK_TEXTS.failed, isError: true });
    assert.equal(made.count, 0);
    assert.deepEqual(await browser.storage.session.get(null), before.session);
});

test('state for a new report counts its screenshots and offers Save draft only with a draft and nothing on its way', async () => {
    const cases = [
        ['a draft with two screenshots', madeNewReport(), { count: 2, canSave: true }],
        ['a capture still on its way', madeNewReport({ pendingCapture: PENDING_CAPTURE }), { count: 2, canSave: false }],
        ['no draft made yet', madeNewReport({ draftId: null, draftUrl: null, screenshotCount: 0 }), { count: 0, canSave: false }],
    ];
    for (const [name, newReport, expected] of cases) {
        const browser = await dockBrowser({ target: NEW_REPORT_TARGET, newReport });
        const { pinround, apiOf } = pinroundFor();

        assert.deepEqual(await ask(browser, { pinroundDock: 'state' }, apiOf), { state: { choices: OFFERED_CHOICES, chosen: NEW_REPORT, ...expected } }, name);
        assert.deepEqual(writesOf(pinround), [], name);
    }
});

test('state for a ticket counts the screenshots of my draft round on it, and says it cannot tell when the list fails', async () => {
    const draftRounds = [{ ticketKey: 'SHOP-12', screenshots: [{ screenshot_id: 'shot-1', title: 'Basket' }, { screenshot_id: 'shot-2', title: 'Total', try_count: 3 }] }];
    const browser = await dockBrowser({ target: SHOP_12 });
    const { pinround, apiOf } = pinroundFor({ draftRounds });

    assert.deepEqual(await ask(browser, { pinroundDock: 'state' }, apiOf), { state: { choices: OFFERED_CHOICES, chosen: 'ticket:SHOP-12', count: 2, canSave: false } });

    pinround.failOnServer('/api/v1/tickets/SHOP-12/draft/screenshots');
    assert.deepEqual(await ask(browser, { pinroundDock: 'state' }, apiOf), { state: { choices: OFFERED_CHOICES, chosen: 'ticket:SHOP-12', count: null, canSave: false } });
    assert.deepEqual(await loadCaptureTarget(browser.storage.session), SHOP_12);
});

test('state forgets a remembered ticket pinround no longer lists and says so', async () => {
    const browser = await dockBrowser({ target: SHOP_77 });
    const { apiOf } = pinroundFor();

    assert.deepEqual(await ask(browser, { pinroundDock: 'state' }, apiOf), {
        state: { choices: OFFERED_CHOICES, chosen: '', count: null, canSave: false },
        message: goneMessageOf(SHOP_77),
        isError: true,
    });
    assert.equal(await loadCaptureTarget(browser.storage.session), null);
});

test('choose remembers an offered target, and an empty value forgets it', async () => {
    const browser = await dockBrowser({ target: NEW_REPORT_TARGET, newReport: madeNewReport() });
    const { apiOf } = pinroundFor();

    assert.deepEqual(await ask(browser, { pinroundDock: 'choose', value: 'ticket:SHOP-12' }, apiOf), { state: { choices: OFFERED_CHOICES, chosen: 'ticket:SHOP-12', count: 0, canSave: false } });
    assert.deepEqual(await loadCaptureTarget(browser.storage.session), SHOP_12);

    assert.deepEqual(await ask(browser, { pinroundDock: 'choose', value: '' }, apiOf), { state: { choices: OFFERED_CHOICES, chosen: '', count: null, canSave: false } });
    assert.equal(await loadCaptureTarget(browser.storage.session), null);
});

test('choose refuses a value pinround does not offer and keeps the target chosen before', async () => {
    const browser = await dockBrowser({ target: NEW_REPORT_TARGET, newReport: madeNewReport() });
    const before = await storedState(browser);
    const { pinround, apiOf } = pinroundFor();

    for (const value of ['ticket:SHOP-99', 'notebook:nb-of-someone-else', 'ticket:shop-12', undefined]) {
        assert.deepEqual(await ask(browser, { pinroundDock: 'choose', value }, apiOf), {
            message: DOCK_TEXTS.choiceGone,
            isError: true,
            state: { choices: OFFERED_CHOICES, chosen: NEW_REPORT, count: 2, canSave: true },
        }, String(value));
    }

    assert.deepEqual(await storedState(browser), before);
    assert.deepEqual(writesOf(pinround), []);
});

test('capture with no target asks to choose one first and sends nothing', async () => {
    const browser = await dockBrowser();
    await putPinOnPage(browser);
    const before = await storedState(browser);
    const { pinround, apiOf } = pinroundFor();

    assert.deepEqual(await ask(browser, { pinroundDock: 'capture' }, apiOf), {
        message: CHOOSE_TARGET_FIRST,
        isError: true,
        state: { choices: OFFERED_CHOICES, chosen: '', count: null, canSave: false },
    });
    assert.deepEqual(await storedState(browser), before);
    assert.deepEqual(writesOf(pinround), []);
});

test('capture into SHOP-12 adds the page with its pin to my draft round and answers the new count', async () => {
    const browser = await dockBrowser({ target: SHOP_12 });
    await putPinOnPage(browser);
    const { pinround, apiOf } = pinroundFor();

    assert.deepEqual(await ask(browser, { pinroundDock: 'capture' }, apiOf), {
        message: SHOP_12_ADDED,
        state: { choices: OFFERED_CHOICES, chosen: 'ticket:SHOP-12', count: 1, canSave: false },
    });
    const added = pinround.requests.filter(([method, path]) => 'POST' === method && '/api/v1/tickets/SHOP-12/draft/screenshots' === path).map(([, , body]) => body);
    assert.deepEqual(added, [{ upload_id: 'upload-1', title: 'Acme Foods shop', summary: `Page: ${SHOP_TAB.url}`, pins: [{ x_pct: 10, y_pct: 20, note: 'Total' }] }]);
    assert.deepEqual(await loadCaptureTarget(browser.storage.session), SHOP_12);
});

test('save-draft opens a new report\'s draft on pinround, forgets the report and keeps "A new report" chosen', async () => {
    const browser = await dockBrowser({ target: NEW_REPORT_TARGET, newReport: madeNewReport() });
    const { pinround, apiOf } = pinroundFor();

    assert.deepEqual(await ask(browser, { pinroundDock: 'save-draft' }, apiOf), {
        message: REPORT_OPENED,
        state: { choices: OFFERED_CHOICES, chosen: NEW_REPORT, count: 0, canSave: false },
    });
    assert.deepEqual(await openTabUrls(browser), [SHOP_TAB.url, DRAFT_URL]);
    assert.deepEqual(await browser.storage.session.get(null), { [CAPTURE_TARGET_KEY]: NEW_REPORT_TARGET });
    assert.deepEqual(writesOf(pinround), []);
});

test('save-draft of a draft whose address is not on pinround keeps it there and opens no tab', async () => {
    const browser = await dockBrowser({ target: NEW_REPORT_TARGET, newReport: madeNewReport({ draftUrl: `${SHOP_ORIGIN}/report-drafts/${DRAFT_ID}` }) });
    const { apiOf } = pinroundFor();

    assert.deepEqual(await ask(browser, { pinroundDock: 'save-draft' }, apiOf), {
        message: REPORT_KEPT_IN_PINROUND,
        state: { choices: OFFERED_CHOICES, chosen: NEW_REPORT, count: 0, canSave: false },
    });
    assert.deepEqual(await openTabUrls(browser), [SHOP_TAB.url]);
    assert.deepEqual(await browser.storage.session.get(null), { [CAPTURE_TARGET_KEY]: NEW_REPORT_TARGET });
});

test('save-draft with nothing to save says so and keeps the target and report', async () => {
    const cases = [
        ['a ticket', { target: SHOP_12, newReport: madeNewReport() }, { chosen: 'ticket:SHOP-12', count: 0, canSave: false }],
        ['no target', { newReport: madeNewReport() }, { chosen: '', count: null, canSave: false }],
        ['a new report with no draft yet', { target: NEW_REPORT_TARGET, newReport: madeNewReport({ draftId: null, draftUrl: null, screenshotCount: 0 }) }, { chosen: NEW_REPORT, count: 0, canSave: false }],
    ];
    for (const [name, seed, expectedState] of cases) {
        const browser = await dockBrowser(seed);
        const before = await storedState(browser);
        const { apiOf } = pinroundFor();

        assert.deepEqual(await ask(browser, { pinroundDock: 'save-draft' }, apiOf), { message: DOCK_TEXTS.nothingToSave, isError: true, state: { choices: OFFERED_CHOICES, ...expectedState } }, name);
        assert.deepEqual(await storedState(browser), before, name);
        assert.deepEqual(await openTabUrls(browser), [SHOP_TAB.url], name);
    }
});

test('save-draft while a capture is on its way asks to send it again and forgets nothing', async () => {
    const browser = await dockBrowser({ target: NEW_REPORT_TARGET, newReport: madeNewReport({ pendingCapture: PENDING_CAPTURE }) });
    const before = await storedState(browser);
    const { apiOf } = pinroundFor();

    assert.deepEqual(await ask(browser, { pinroundDock: 'save-draft' }, apiOf), {
        message: SEND_AGAIN_FIRST,
        isError: true,
        state: { choices: OFFERED_CHOICES, chosen: NEW_REPORT, count: 2, canSave: false },
    });
    assert.deepEqual(await storedState(browser), before);
    assert.deepEqual(await openTabUrls(browser), [SHOP_TAB.url]);
});

test('a capture into a ticket that is gone forgets that target, even when the ticket list cannot tell', async () => {
    const browser = await dockBrowser({ target: SHOP_77 });
    await putPinOnPage(browser);
    const { pinround, apiOf } = pinroundFor();
    // With the list unloaded the state keeps an unchecked target, so only the gone capture can forget it.
    pinround.failOnServer('/api/v1/me/tickets');

    assert.deepEqual(await ask(browser, { pinroundDock: 'capture' }, apiOf), {
        message: goneMessageOf(SHOP_77),
        isError: true,
        state: { choices: OFFERED_CHOICES.filter((choice) => 'ticket:SHOP-12' !== choice.value), chosen: '', count: null, canSave: false },
    });
    assert.equal(await loadCaptureTarget(browser.storage.session), null);
});

test('a capture into a gone ticket keeps the target chosen meanwhile in the popup', async () => {
    const browser = await dockBrowser({ target: SHOP_77 });
    await putPinOnPage(browser);
    const pinround = fakePinround({ tickets: TICKETS, notebooks: NOTEBOOKS });
    // The person picks SHOP-12 in the popup while the dock's capture into SHOP-77 is under way.
    const choosingMeanwhile = async (href, init) => {
        if ('/api/v1/uploads' === new URL(href).pathname) {
            await saveCaptureTarget(browser.storage.session, SHOP_12);
        }

        return pinround.fetchFn(href, init);
    };
    const apiOf = (settings) => new PinroundApi(settings.origin, settings.token, choosingMeanwhile);

    assert.deepEqual(await ask(browser, { pinroundDock: 'capture' }, apiOf), {
        message: goneMessageOf(SHOP_77),
        isError: true,
        state: { choices: OFFERED_CHOICES, chosen: 'ticket:SHOP-12', count: 0, canSave: false },
    });
    assert.deepEqual(await loadCaptureTarget(browser.storage.session), SHOP_12);
});

test('the service worker answers a dock message from a tab through the listener it adds, and leaves every other message', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_TAB] });
    globalThis.chrome = browser;
    try {
        await import('../src/background/service-worker.js');
        assert.equal(browser.messageListeners.length, 1);
        const [listener] = browser.messageListeners;
        const send = (message, sender) => new Promise((resolve) => {
            const keepsChannelOpen = listener(message, sender, resolve);
            if (!keepsChannelOpen) {
                resolve({ keepsChannelOpen });
            }
        });

        assert.deepEqual(await send({ pinroundDock: 'state' }, { tab: SHOP_TAB }), { message: DOCK_TEXTS.notPaired, isError: true });
        assert.deepEqual(await send({ pinroundDock: 'forget-everything' }, { tab: SHOP_TAB }), { message: DOCK_TEXTS.unknownRequest, isError: true });
        assert.deepEqual(await send({ pinroundDock: 'state' }, { id: 'extension-id' }), { keepsChannelOpen: false }, 'The popup has no tab.');
        assert.deepEqual(await send({ pinroundOverlay: 'state' }, { tab: SHOP_TAB }), { keepsChannelOpen: false });
        assert.deepEqual(await send('state', { tab: SHOP_TAB }), { keepsChannelOpen: false });
        assert.deepEqual(await send(null, { tab: SHOP_TAB }), { keepsChannelOpen: false });
    } finally {
        delete globalThis.chrome;
    }
});
