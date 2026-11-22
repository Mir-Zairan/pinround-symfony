import assert from 'node:assert/strict';
import { test } from 'node:test';
import { DOCK_TEXTS, answerDockRequest } from '../src/background/dock-requests.js';
import { CAPTURE_TARGET_KEY, CHOOSE_TARGET_FIRST, NEW_REPORT, goneMessageOf, loadCaptureTarget, saveCaptureTarget } from '../src/capture/capture-target.js';
import { NEW_REPORT_KEY, NOT_SENT, WAITING_CAPTURES_KEY, loadNewReport } from '../src/capture/new-report.js';
import { NOTHING_WAITING, NO_ROOM, WAITING_FULL, capturedMessage, draftSavedMessage, loadWaitingCaptures } from '../src/capture/waiting-captures.js';
import { PinroundApi } from '../src/shared/api.js';
import { saveSettings } from '../src/shared/settings.js';
import { EXTENSION_PAGE_FETCH, answerExtensionPageFetch } from '../src/background/extension-page-fetch.js';
import { FAKE_EXTENSION_ID, fakeBrowser } from './fake-browser.mjs';
import { PINROUND_ORIGIN, STORAGE_ORIGIN, TOKEN, fakePinround } from './fake-pinround.mjs';

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
    { value: NEW_REPORT, label: 'New report' },
    { value: 'ticket:SHOP-12', label: 'My draft round · SHOP-12' },
    { value: `notebook:${NOTEBOOK_ID}`, label: 'Notebook · Checkout' },
];
const SHOP_12_ADDED = 'Added to your draft round on SHOP-12. Only you see it.';

/** A report draft as new-report.js keeps it in session storage, with two screenshots made and nothing on its way. */
const madeNewReport = (changes = {}) => ({ project: 'SHOP', reportTitle: 'Logo is cut off', draftId: DRAFT_ID, draftUrl: DRAFT_URL, screenshotCount: 2, pendingCapture: null, ...changes });
const PENDING_CAPTURE = { idempotencyKey: 'key-1', path: `/api/v1/reports/${DRAFT_ID}/screenshots`, bodyJson: '{"upload_id":"upload-9"}', origin: PINROUND_ORIGIN };
/** A new report as the popup leaves it once its project key was typed, before any capture. */
const TYPED_NEW_REPORT = { project: 'SHOP', reportTitle: '', draftId: null, draftUrl: null, screenshotCount: 0, pendingCapture: null };
/** The dock's view of a new report whose draft is made and has nothing waiting. */
const MADE_DRAFT_WAITING = { shots: [], needsTitle: false, needsProject: false };
const SAVE = (reportTitle, project = '') => ({ pinroundDock: 'save-draft', value: { reportTitle, project } });
/** The pins of the stand-in overlay putPinOnPage puts on the shop tab. */
const PAGE_PIN = { x_pct: 10, y_pct: 20, note: 'Total' };

/** The extension on the shop tab, its icon clicked, paired unless told otherwise, with this target and new report kept. */
async function dockBrowser({ isPaired = true, target = null, newReport = null, offscreenListener = null } = {}) {
    const browser = fakeBrowser({ tabs: [SHOP_TAB], offscreenListener });
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

const ask = (browser, request, apiOf, tab = SHOP_TAB) => answerDockRequest(request, tab, { browser, apiOf });
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

    for (const request of [{ pinroundDock: 'state' }, { pinroundDock: 'choose', value: '' }, { pinroundDock: 'capture' }, SAVE('Total is wrong')]) {
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
        ['a save with no typed texts', { pinroundDock: 'save-draft' }, SHOP_TAB],
        ['a save whose title is no text', { pinroundDock: 'save-draft', value: { reportTitle: 42, project: '' } }, SHOP_TAB],
        ['a save whose title runs past any cap', SAVE('x'.repeat(1001)), SHOP_TAB],
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

test('state for a new report counts the captures waiting, offers Save draft only with one waiting or one to send again, and asks only for what is not known', async () => {
    const waitingShot = { id: 'w1', pngDataUrl: 'data:image/png;base64,iVBORw0KGgo=', texts: { title: 'Cart' }, pins: [PAGE_PIN, { x_pct: 90, y_pct: 80, note: '' }] };
    const cases = [
        ['nothing waiting, project typed in the popup', TYPED_NEW_REPORT, [], { count: 0, canSave: false, newReport: { shots: [], needsTitle: true, needsProject: false } }],
        ['one waiting, no project known', { ...TYPED_NEW_REPORT, project: ' ' }, [waitingShot], { count: 1, canSave: true, newReport: { shots: [{ pinSpots: [{ x_pct: 10, y_pct: 20 }, { x_pct: 90, y_pct: 80 }] }], needsTitle: true, needsProject: true } }],
        ['a draft made, a capture to send again', madeNewReport({ pendingCapture: PENDING_CAPTURE }), [], { count: 0, canSave: true, newReport: MADE_DRAFT_WAITING }],
        ['a draft made, nothing waiting', madeNewReport(), [], { count: 0, canSave: false, newReport: MADE_DRAFT_WAITING }],
    ];
    for (const [name, newReport, waiting, expected] of cases) {
        const browser = await dockBrowser({ target: NEW_REPORT_TARGET, newReport });
        await browser.storage.session.set({ [WAITING_CAPTURES_KEY]: waiting });
        const { pinround, apiOf } = pinroundFor();

        assert.deepEqual(await ask(browser, { pinroundDock: 'state' }, apiOf), { state: { choices: OFFERED_CHOICES, chosen: NEW_REPORT, ...expected } }, name);
        assert.deepEqual(writesOf(pinround), [], name);
    }
});

test('state for a ticket counts the screenshots of my draft round on it, and says it cannot tell when the list fails', async () => {
    const draftRounds = [{ ticketKey: 'SHOP-12', screenshots: [{ screenshot_id: 'shot-1', title: 'Basket' }, { screenshot_id: 'shot-2', title: 'Total', try_count: 3 }] }];
    const browser = await dockBrowser({ target: SHOP_12 });
    const { pinround, apiOf } = pinroundFor({ draftRounds });

    assert.deepEqual(await ask(browser, { pinroundDock: 'state' }, apiOf), { state: { choices: OFFERED_CHOICES, chosen: 'ticket:SHOP-12', count: 2, canSave: false, newReport: null } });

    pinround.failOnServer('/api/v1/tickets/SHOP-12/draft/screenshots');
    assert.deepEqual(await ask(browser, { pinroundDock: 'state' }, apiOf), { state: { choices: OFFERED_CHOICES, chosen: 'ticket:SHOP-12', count: null, canSave: false, newReport: null } });
    assert.deepEqual(await loadCaptureTarget(browser.storage.session), SHOP_12);
});

test('state forgets a remembered ticket pinround no longer lists and says so', async () => {
    const browser = await dockBrowser({ target: SHOP_77 });
    const { apiOf } = pinroundFor();

    assert.deepEqual(await ask(browser, { pinroundDock: 'state' }, apiOf), {
        state: { choices: OFFERED_CHOICES, chosen: '', count: null, canSave: false, newReport: null },
        message: goneMessageOf(SHOP_77),
        isError: true,
    });
    assert.equal(await loadCaptureTarget(browser.storage.session), null);
});

test('choose remembers an offered target, and an empty value forgets it', async () => {
    const browser = await dockBrowser({ target: NEW_REPORT_TARGET, newReport: madeNewReport() });
    const { apiOf } = pinroundFor();

    assert.deepEqual(await ask(browser, { pinroundDock: 'choose', value: 'ticket:SHOP-12' }, apiOf), { state: { choices: OFFERED_CHOICES, chosen: 'ticket:SHOP-12', count: 0, canSave: false, newReport: null } });
    assert.deepEqual(await loadCaptureTarget(browser.storage.session), SHOP_12);

    assert.deepEqual(await ask(browser, { pinroundDock: 'choose', value: '' }, apiOf), { state: { choices: OFFERED_CHOICES, chosen: '', count: null, canSave: false, newReport: null } });
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
            state: { choices: OFFERED_CHOICES, chosen: NEW_REPORT, count: 0, canSave: false, newReport: MADE_DRAFT_WAITING },
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
        state: { choices: OFFERED_CHOICES, chosen: '', count: null, canSave: false, newReport: null },
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
        state: { choices: OFFERED_CHOICES, chosen: 'ticket:SHOP-12', count: 1, canSave: false, newReport: null },
    });
    const added = pinround.requests.filter(([method, path]) => 'POST' === method && '/api/v1/tickets/SHOP-12/draft/screenshots' === path).map(([, , body]) => body);
    assert.deepEqual(added, [{ upload_id: 'upload-1', title: 'Acme Foods shop', pins: [PAGE_PIN] }], 'No summary: the page address, window and browser are not what the picture shows.');
    assert.deepEqual(await loadCaptureTarget(browser.storage.session), SHOP_12);
});

/** The shop tab with "A new report" chosen and the popup's project key kept, the page holding one pin. */
async function newReportBrowser(newReport = TYPED_NEW_REPORT) {
    const browser = await dockBrowser({ target: NEW_REPORT_TARGET, newReport });
    await putPinOnPage(browser);

    return browser;
}

test('a capture into a new report waits in the extension, sends nothing, and says how many wait', async () => {
    const browser = await newReportBrowser();
    const { pinround, apiOf } = pinroundFor();

    const first = await ask(browser, { pinroundDock: 'capture' }, apiOf);
    const second = await ask(browser, { pinroundDock: 'capture' }, apiOf);

    assert.deepEqual([first.message, second.message], [capturedMessage(1), capturedMessage(2)]);
    assert.deepEqual(second.state, { choices: OFFERED_CHOICES, chosen: NEW_REPORT, count: 2, canSave: true, newReport: { shots: [{ pinSpots: [{ x_pct: 10, y_pct: 20 }] }, { pinSpots: [{ x_pct: 10, y_pct: 20 }] }], needsTitle: true, needsProject: false } });
    assert.deepEqual(writesOf(pinround), [], 'Nothing reaches pinround before Save draft.');
    const waiting = await loadWaitingCaptures(browser.storage.session);
    assert.equal(waiting.length, 2);
    assert.deepEqual(waiting.map(({ texts, pins }) => ({ texts, pins })), [{ texts: { title: 'Acme Foods shop' }, pins: [PAGE_PIN] }, { texts: { title: 'Acme Foods shop' }, pins: [PAGE_PIN] }]);
    assert.ok(waiting.every(({ pngDataUrl }) => pngDataUrl.startsWith('data:image/png;base64,')));
});

test('save-draft makes one report draft with the typed title and every waiting capture, then forgets the report and keeps "New report" chosen', async () => {
    const browser = await newReportBrowser();
    const { pinround, apiOf } = pinroundFor();
    await ask(browser, { pinroundDock: 'capture' }, apiOf);
    await ask(browser, { pinroundDock: 'capture' }, apiOf);

    assert.deepEqual(await ask(browser, SAVE('  Total is wrong  '), apiOf), {
        message: draftSavedMessage(2),
        state: { choices: OFFERED_CHOICES, chosen: NEW_REPORT, count: 0, canSave: false, newReport: { shots: [], needsTitle: true, needsProject: true } },
    });

    const [draft, ...otherDrafts] = pinround.myDrafts();
    assert.deepEqual(otherDrafts, []);
    assert.deepEqual([draft.project, draft.title, draft.screenshots.length], ['SHOP', 'Total is wrong', 2]);
    assert.equal(pinround.requests.filter(([method, requestPath]) => 'POST' === method && '/api/v1/reports' === requestPath).length, 1);
    assert.deepEqual(await browser.storage.session.get(null), { [CAPTURE_TARGET_KEY]: NEW_REPORT_TARGET });
    assert.deepEqual(await openTabUrls(browser), [SHOP_TAB.url], 'The tester keeps testing: no tab opens.');
});

test('save-draft takes the project typed in the panel when the popup has none', async () => {
    const browser = await newReportBrowser({ ...TYPED_NEW_REPORT, project: '' });
    const { pinround, apiOf } = pinroundFor();
    await ask(browser, { pinroundDock: 'capture' }, apiOf);

    assert.equal((await ask(browser, SAVE('Total is wrong', 'shop'), apiOf)).message, draftSavedMessage(1));
    assert.deepEqual(pinround.myDrafts().map(({ project, title }) => [project, title]), [['SHOP', 'Total is wrong']]);
});

test('save-draft refuses a blank or too long title, or no project, before anything is sent, and keeps every capture waiting', async () => {
    const cases = [
        ['a blank title', TYPED_NEW_REPORT, SAVE(' \r\n '), 'Write what is wrong in one line, at most 200 characters.'],
        ['a title of 201 characters', TYPED_NEW_REPORT, SAVE('\u{1F600}'.repeat(201)), 'Write what is wrong in one line, at most 200 characters.'],
        ['no project anywhere', { ...TYPED_NEW_REPORT, project: '' }, SAVE('Total is wrong', '  '), 'Type the project key, for example SHOP.'],
    ];
    for (const [name, newReport, request, refusal] of cases) {
        const browser = await newReportBrowser(newReport);
        const { pinround, apiOf } = pinroundFor();
        await ask(browser, { pinroundDock: 'capture' }, apiOf);
        const before = await storedState(browser);

        const answer = await ask(browser, request, apiOf);

        assert.deepEqual([answer.message, answer.isError, answer.state.count], [refusal, true, 1], name);
        assert.deepEqual(writesOf(pinround), [], name);
        assert.deepEqual(await storedState(browser), before, name);
    }
});

test('a title of exactly 200 characters is saved', async () => {
    const browser = await newReportBrowser();
    const { pinround, apiOf } = pinroundFor();
    await ask(browser, { pinroundDock: 'capture' }, apiOf);

    assert.equal((await ask(browser, SAVE('\u{1F600}'.repeat(200)), apiOf)).message, draftSavedMessage(1));
    assert.equal(pinround.myDrafts()[0].title, '\u{1F600}'.repeat(200));
});

test('save-draft with nothing waiting says so and keeps the target and report', async () => {
    const cases = [
        ['a ticket', { target: SHOP_12, newReport: madeNewReport() }, DOCK_TEXTS.nothingToSave, { chosen: 'ticket:SHOP-12', count: 0, canSave: false, newReport: null }],
        ['no target', { newReport: madeNewReport() }, DOCK_TEXTS.nothingToSave, { chosen: '', count: null, canSave: false, newReport: null }],
        ['a new report with nothing captured', { target: NEW_REPORT_TARGET, newReport: TYPED_NEW_REPORT }, NOTHING_WAITING, { chosen: NEW_REPORT, count: 0, canSave: false, newReport: { shots: [], needsTitle: true, needsProject: false } }],
    ];
    for (const [name, seed, message, expectedState] of cases) {
        const browser = await dockBrowser(seed);
        const before = await storedState(browser);
        const { pinround, apiOf } = pinroundFor();

        assert.deepEqual(await ask(browser, SAVE('Total is wrong'), apiOf), { message, isError: true, state: { choices: OFFERED_CHOICES, ...expectedState } }, name);
        assert.deepEqual(await storedState(browser), before, name);
        assert.deepEqual(writesOf(pinround), [], name);
    }
});

test('a save whose answer is lost keeps that capture to send again, and the next save sends it once and the rest after it', async () => {
    const browser = await newReportBrowser();
    const { pinround, apiOf } = pinroundFor();
    await ask(browser, { pinroundDock: 'capture' }, apiOf);
    await ask(browser, { pinroundDock: 'capture' }, apiOf);
    pinround.loseAnswerTo('/api/v1/reports');

    const lost = await ask(browser, SAVE('Total is wrong'), apiOf);

    assert.deepEqual([lost.message, lost.isError, lost.state.count, lost.state.canSave], [NOT_SENT, true, 1, true]);
    assert.notEqual((await loadNewReport(browser.storage.session)).pendingCapture, null);

    assert.equal((await ask(browser, SAVE(''), apiOf)).message, draftSavedMessage(2));
    const [draft, ...otherDrafts] = pinround.myDrafts();
    assert.deepEqual([otherDrafts, draft.title, draft.screenshots.length], [[], 'Total is wrong', 2]);
});

test('a capture past ten waiting is refused before the tab is captured, and nothing more is kept', async () => {
    const browser = await newReportBrowser({ ...TYPED_NEW_REPORT, draftId: DRAFT_ID, draftUrl: DRAFT_URL, screenshotCount: 9 });
    const { apiOf } = pinroundFor();
    await ask(browser, { pinroundDock: 'capture' }, apiOf);
    const captureAsChrome = browser.tabs.captureVisibleTab;
    let tabCaptures = 0;
    browser.tabs.captureVisibleTab = (...args) => {
        tabCaptures += 1;

        return captureAsChrome(...args);
    };

    const refused = await ask(browser, { pinroundDock: 'capture' }, apiOf);

    assert.deepEqual([refused.message, refused.isError, refused.state.count], [WAITING_FULL, true, 1]);
    assert.equal(tabCaptures, 0);
    assert.equal((await loadWaitingCaptures(browser.storage.session)).length, 1);
});

test('a capture the session storage has no room for is refused, keeps the captures before it, and leaves the pins on the page', async () => {
    const browser = await newReportBrowser();
    const { apiOf } = pinroundFor();
    await ask(browser, { pinroundDock: 'capture' }, apiOf);
    const storeAsChrome = browser.storage.session.set;
    browser.storage.session.set = async (items) => {
        if (Object.hasOwn(items, WAITING_CAPTURES_KEY)) {
            throw new Error('Session storage quota bytes exceeded. Values were not stored.');
        }

        return storeAsChrome(items);
    };
    await browser.scripting.executeScript({ target: { tabId: SHOP_TAB.id }, func: () => { globalThis.pinroundOverlay.close = () => { globalThis.pinroundClosed = true; }; } });

    const refused = await ask(browser, { pinroundDock: 'capture' }, apiOf);

    assert.deepEqual([refused.message, refused.isError, refused.state.count], [NO_ROOM, true, 1]);
    assert.equal((await loadWaitingCaptures(browser.storage.session)).length, 1);
    const [{ result: wasClosed }] = await browser.scripting.executeScript({ target: { tabId: SHOP_TAB.id }, func: () => true === globalThis.pinroundClosed });
    assert.equal(wasClosed, false, 'The pins stay for the next try.');
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
        state: { choices: OFFERED_CHOICES.filter((choice) => 'ticket:SHOP-12' !== choice.value), chosen: '', count: null, canSave: false, newReport: null },
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
        state: { choices: OFFERED_CHOICES, chosen: 'ticket:SHOP-12', count: 0, canSave: false, newReport: null },
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

test('with no apiOf given, the dock\'s calls and each picture\'s PUT go from the offscreen document, where an accepted certificate holds', async () => {
    const pinround = fakePinround({ tickets: TICKETS, notebooks: NOTEBOOKS });
    const offscreenListener = (message, sender, sendResponse) => {
        const answer = answerExtensionPageFetch(message, sender, { extensionId: FAKE_EXTENSION_ID, fetchFn: pinround.fetchFn });
        if (null === answer) {
            return false;
        }
        answer.then(sendResponse);

        return true;
    };
    const browser = await dockBrowser({ target: NEW_REPORT_TARGET, newReport: TYPED_NEW_REPORT, offscreenListener });
    await putPinOnPage(browser);

    assert.equal((await answerDockRequest({ pinroundDock: 'capture' }, SHOP_TAB, { browser })).message, capturedMessage(1));
    assert.equal((await answerDockRequest(SAVE('Total is wrong'), SHOP_TAB, { browser })).message, draftSavedMessage(1));

    assert.deepEqual(pinround.myDrafts().map((draft) => [draft.title, draft.screenshots.length]), [['Total is wrong', 1]]);
    const sentFromPage = browser.sentRuntimeMessages.map((message) => message[EXTENSION_PAGE_FETCH]).map(({ method, url }) => `${method} ${new URL(url).origin}`);
    assert.ok(sentFromPage.includes(`PUT ${STORAGE_ORIGIN}`), sentFromPage.join(', '));
    assert.ok(sentFromPage.includes(`POST ${PINROUND_ORIGIN}`), sentFromPage.join(', '));
    assert.equal(browser.offscreenDocuments.length, 1);
});

test('a waiting capture this version cannot read is left out, never sent', async () => {
    const good = { id: 'w1', pngDataUrl: 'data:image/png;base64,iVBORw0KGgo=', texts: { title: 'Cart' }, pins: [PAGE_PIN] };
    const browser = await dockBrowser({ target: NEW_REPORT_TARGET, newReport: TYPED_NEW_REPORT });
    await browser.storage.session.set({ [WAITING_CAPTURES_KEY]: [good, { ...good, id: 'w2', pngDataUrl: 'https://evil.example/x.png' }, { ...good, id: 'w3', pins: 'all' }, null] });
    const { apiOf } = pinroundFor();

    assert.deepEqual((await loadWaitingCaptures(browser.storage.session)).map(({ id }) => id), ['w1']);
    assert.equal((await ask(browser, { pinroundDock: 'state' }, apiOf)).state.count, 1);
});
