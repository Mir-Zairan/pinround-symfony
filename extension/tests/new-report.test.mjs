import assert from 'node:assert/strict';
import { test } from 'node:test';
import { PinroundApi } from '../src/shared/api.js';
import { NEW_REPORT, TargetGoneError } from '../src/capture/capture-target.js';
import { captureToTarget } from '../src/capture/capture-to-target.js';
import {
    CAPTURE_TOO_LONG, NEW_REPORT_KEY, NOT_SENT, OLD_REPORT_CAPTURES_KEY, PENDING_FOR_OTHER_PINROUND, REPORTS_WRITE_MISSING, REPORT_FULL, REPORT_GONE, SEND_AGAIN_FIRST,
    addCaptureToNewReport, clearNewReport, dropOldReportCaptures, finishNewReport, loadNewReport, saveTypedReport, sendPendingCapture,
} from '../src/capture/new-report.js';
import { fakeBrowser } from './fake-browser.mjs';
import { PINROUND_ORIGIN, TOKEN, fakePinround } from './fake-pinround.mjs';

const SHOP_PAGE = { id: 7, url: 'https://shop.acme-foods.example/cart', title: 'Acme Foods shop', active: true };
const CHECKOUT_PAGE = { id: 8, url: 'https://shop.acme-foods.example/checkout', title: 'Acme Foods checkout' };
const TYPED = { project: ' shop ', reportTitle: ' Basket total is doubled ' };
const NEW_REPORT_TARGET = { kind: NEW_REPORT };

/** A browser on the shop page whose overlay holds `pins`; closing the overlay is counted in the page. */
async function browserWithPins(pins, tab = SHOP_PAGE) {
    const browser = fakeBrowser({ tabs: [tab] });
    browser.clickIcon(tab.id);
    await placePins(browser, tab.id, pins);

    return browser;
}

async function placePins(browser, tabId, pins) {
    await browser.scripting.executeScript({
        target: { tabId },
        func: (overlayPins) => {
            globalThis.closings = 0;
            globalThis.pinroundOverlay = { hideForCapture: async () => overlayPins, show: () => {}, close: () => { globalThis.closings += 1; } };
        },
        args: [pins],
    });
}

async function overlayClosings(browser, tabId = SHOP_PAGE.id) {
    const [{ result }] = await browser.scripting.executeScript({ target: { tabId }, func: () => globalThis.closings });

    return result;
}

function pinroundAndApi(seed) {
    const pinround = fakePinround(seed);

    return { pinround, api: new PinroundApi(PINROUND_ORIGIN, 'pr_abcdefghijklmnopqrstuvwxyz012345', pinround.fetchFn) };
}

const capture = (api, browser, texts = {}, typed = TYPED, tabId = SHOP_PAGE.id) => captureToTarget({ api, browser, storage: browser.storage.session, tabId, target: NEW_REPORT_TARGET, typed, texts });
const postsOf = (pinround) => pinround.requests.filter(([method]) => 'POST' === method);

test('the first capture makes the draft with its picture, the second adds to it, in order and with their exact bodies', async () => {
    const { pinround, api } = pinroundAndApi({ projects: ['SHOP', 'MOBILE'] });
    const browser = await browserWithPins([{ x_pct: 25.004, y_pct: 50, note: ' Price is wrong ' }]);

    assert.equal(await capture(api, browser, { title: 'Basket' }), 'Report draft made with screenshot 1. Capture more pages, or press Done to open it.');
    assert.equal(await overlayClosings(browser), 1);
    browser.navigate(SHOP_PAGE.id, CHECKOUT_PAGE.url);
    browser.finishLoading(SHOP_PAGE.id);
    browser.clickIcon(SHOP_PAGE.id);
    await placePins(browser, SHOP_PAGE.id, [{ x_pct: 70, y_pct: 10, note: 'Total' }]);
    assert.equal(await capture(api, browser, { summary: 'After paying' }, { project: 'MOBILE', reportTitle: 'Another title' }), 'Screenshot 2 added to the report draft. Capture more pages, or press Done to open it.');

    const [draft, ...otherDrafts] = pinround.myDrafts();
    assert.deepEqual(otherDrafts, []);
    const posts = postsOf(pinround);
    assert.deepEqual(posts.map(([, path, , key]) => [path, 'string' === typeof key]), [
        ['/api/v1/uploads', false],
        ['/api/v1/reports', true],
        ['/api/v1/uploads', false],
        [`/api/v1/reports/${draft.id}/screenshots`, true],
    ]);
    assert.deepEqual([posts[0][2].project, posts[2][2].project], ['SHOP', 'SHOP'], 'A later capture uploads for the draft\'s project, not the one typed since.');
    assert.deepEqual(posts[1][2], { project: 'SHOP', title: 'Basket total is doubled', screenshots: [{ upload_id: 'upload-1', title: 'Basket', pins: [{ x_pct: 25, y_pct: 50, note: 'Price is wrong' }] }] });
    assert.deepEqual(posts[3][2], { upload_id: 'upload-2', summary: 'After paying', pins: [{ x_pct: 70, y_pct: 10, note: 'Total' }] });
    assert.notEqual(posts[1][3], posts[3][3], 'Each capture has a key of its own.');
    assert.deepEqual(draft.screenshots.map((screenshot) => [screenshot.upload_id, screenshot.pins.length]), [['upload-1', 1], ['upload-2', 1]]);
    const stored = await loadNewReport(browser.storage.session);
    assert.deepEqual(stored, { project: 'SHOP', reportTitle: 'Basket total is doubled', draftId: draft.id, draftUrl: `${PINROUND_ORIGIN}/report-drafts/${draft.id}`, screenshotCount: 2, pendingCapture: null });
});

test('a POST whose answer was lost is sent again with the same key and bytes, and the next capture gets a new key', async () => {
    const { pinround, api } = pinroundAndApi();
    const browser = await browserWithPins([{ x_pct: 1, y_pct: 2, note: 'Logo' }]);
    const uploadsBefore = () => pinround.requests.filter(([, path]) => '/api/v1/uploads' === path).length;
    pinround.loseAnswerTo('/api/v1/reports');

    await assert.rejects(capture(api, browser), { message: NOT_SENT });
    assert.equal(await overlayClosings(browser), 0, 'The pins stay on the page while the capture is not known to be saved.');
    assert.equal(pinround.myDrafts().length, 1);
    await assert.rejects(capture(api, browser), { message: SEND_AGAIN_FIRST });
    assert.equal(uploadsBefore(), 1, 'No new upload begins while a capture waits to be sent again.');

    const saved = await sendPendingCapture(browser.storage.session, api);

    assert.equal(pinround.myDrafts().length, 1, 'The repeat makes no second draft.');
    assert.equal(saved.draftId, pinround.myDrafts()[0].id);
    assert.equal(saved.pendingCapture, null);
    const reportPosts = postsOf(pinround).filter(([, path]) => '/api/v1/reports' === path);
    assert.equal(reportPosts.length, 2);
    assert.equal(reportPosts[0][3], reportPosts[1][3]);
    assert.deepEqual(reportPosts[0][2], reportPosts[1][2]);

    await capture(api, browser);
    const addPost = postsOf(pinround).at(-1);
    assert.equal(addPost[1], `/api/v1/reports/${saved.draftId}/screenshots`);
    assert.notEqual(addPost[3], reportPosts[0][3]);
    assert.equal(pinround.myDrafts()[0].screenshots.length, 2);
});

test('a later capture pinround never got is sent again once, and the draft gets the picture once', async () => {
    const { pinround, api } = pinroundAndApi();
    const browser = await browserWithPins([]);
    await capture(api, browser);
    pinround.failToReach(`/api/v1/reports/${pinround.myDrafts()[0].id}/screenshots`);

    await assert.rejects(capture(api, browser), { message: NOT_SENT });
    const stored = await loadNewReport(browser.storage.session);
    assert.equal(stored.screenshotCount, 1);
    assert.match(stored.pendingCapture.idempotencyKey, /^[0-9a-f-]{36}$/);

    const saved = await sendPendingCapture(browser.storage.session, api);

    assert.equal(saved.screenshotCount, 2);
    assert.equal(pinround.myDrafts()[0].screenshots.length, 2);
    const adds = postsOf(pinround).filter(([, path]) => path.endsWith('/screenshots'));
    assert.deepEqual(adds.map(([, , , key]) => key), [stored.pendingCapture.idempotencyKey, stored.pendingCapture.idempotencyKey]);
});

test('the 11th capture is refused before its upload, and the draft and stored report stay as they were', async () => {
    const { pinround, api } = pinroundAndApi();
    const browser = await browserWithPins([{ x_pct: 5, y_pct: 5, note: 'Kept' }]);
    for (let screenshot = 1; screenshot <= 10; screenshot += 1) {
        await capture(api, browser);
    }
    await placePins(browser, SHOP_PAGE.id, [{ x_pct: 5, y_pct: 5, note: 'Kept' }]);
    const tenStored = await loadNewReport(browser.storage.session);
    const requestsBefore = pinround.requests.length;

    await assert.rejects(capture(api, browser), { message: REPORT_FULL });

    assert.equal(tenStored.screenshotCount, 10);
    assert.equal(pinround.requests.length, requestsBefore);
    assert.equal(pinround.myDrafts()[0].screenshots.length, 10);
    assert.deepEqual(await loadNewReport(browser.storage.session), tenStored);
    assert.equal(await overlayClosings(browser), 0);
});

test('without reports:write the first capture says so, begins no upload intent and makes no report, and the pins stay', async () => {
    const { pinround, api } = pinroundAndApi({ scopes: ['read', 'rounds:write'] });
    const browser = await browserWithPins([{ x_pct: 40, y_pct: 40, note: 'Total' }]);

    await assert.rejects(capture(api, browser), { message: REPORTS_WRITE_MISSING });

    assert.deepEqual(pinround.requests.map(([method, path]) => [method, path]), [['POST', '/api/v1/uploads']]);
    assert.equal(pinround.state.uploads.size, 0);
    assert.deepEqual(pinround.myDrafts(), []);
    assert.equal(await overlayClosings(browser), 0);
    assert.deepEqual(await loadNewReport(browser.storage.session), { project: '', reportTitle: '', draftId: null, draftUrl: null, screenshotCount: 0, pendingCapture: null });
});

test('a blank project or title is refused before anything is captured or sent', async () => {
    const { pinround, api } = pinroundAndApi();
    const browser = await browserWithPins([]);

    await assert.rejects(capture(api, browser, {}, { project: '  ', reportTitle: 'Basket' }), { message: 'Type the project key, for example SHOP.' });
    await assert.rejects(capture(api, browser, {}, { project: 'SHOP', reportTitle: ' \n' }), { message: 'Write what is wrong in one line, at most 200 characters.' });
    await assert.rejects(capture(api, browser, {}, { project: 'SHOP', reportTitle: 'é'.repeat(201) }), /at most 200 characters/);

    assert.deepEqual(pinround.requests, []);
});

test('a project pinround does not know is refused with its key named, and no draft is made', async () => {
    const { pinround, api } = pinroundAndApi({ projects: ['SHOP'] });
    const browser = await browserWithPins([]);

    await assert.rejects(capture(api, browser, {}, { project: 'mobile', reportTitle: 'Basket' }), { message: 'pinround has no project MOBILE you may report in. Check the project key.' });

    assert.deepEqual(pinround.myDrafts(), []);
});

test('a draft no longer the person\'s is forgotten with a plain message and nothing is added', async () => {
    const { pinround, api } = pinroundAndApi();
    const browser = await browserWithPins([]);
    await capture(api, browser);
    const [draft] = pinround.myDrafts();
    draft.owner = 'omar';

    await assert.rejects(capture(api, browser), (error) => error instanceof TargetGoneError && REPORT_GONE === error.message && NEW_REPORT === error.target.kind);

    assert.equal(draft.screenshots.length, 1);
    assert.deepEqual(await loadNewReport(browser.storage.session), { project: '', reportTitle: '', draftId: null, draftUrl: null, screenshotCount: 0, pendingCapture: null });
});

test('"Done" opens the draft only when its address is on the paired pinround, and forgets the report either way', async () => {
    for (const [draftUrl, opensTab] of [[`${PINROUND_ORIGIN}/report-drafts/d1`, true], ['https://pinround.acme-foods.example.evil.example/report-drafts/d1', false], ['javascript:alert(1)', false], ['not a url', false]]) {
        const browser = fakeBrowser({ tabs: [SHOP_PAGE] });
        await browser.storage.session.set({ [NEW_REPORT_KEY]: { project: 'SHOP', reportTitle: 'Basket', draftId: 'd1', draftUrl, screenshotCount: 1, pendingCapture: null } });

        assert.equal(await finishNewReport(browser.storage.session, browser, PINROUND_ORIGIN), opensTab, draftUrl);

        const openedUrls = (await browser.tabs.query({})).map((tab) => browser.tabUrl(tab.id)).filter((url) => url !== SHOP_PAGE.url);
        assert.deepEqual(openedUrls, opensTab ? [draftUrl] : [], draftUrl);
        assert.deepEqual(await browser.storage.session.get(NEW_REPORT_KEY), {}, draftUrl);
    }
});

test('"Done" is refused while a capture waits to be sent again, and the report is kept', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE] });
    const waiting = { project: 'SHOP', reportTitle: 'Basket', draftId: 'd1', draftUrl: `${PINROUND_ORIGIN}/report-drafts/d1`, screenshotCount: 1, pendingCapture: { idempotencyKey: 'k1', path: '/api/v1/reports/d1/screenshots', bodyJson: '{"upload_id":"u2","pins":[]}', origin: PINROUND_ORIGIN } };
    await browser.storage.session.set({ [NEW_REPORT_KEY]: waiting });

    await assert.rejects(finishNewReport(browser.storage.session, browser, PINROUND_ORIGIN), { message: SEND_AGAIN_FIRST });

    assert.deepEqual(await loadNewReport(browser.storage.session), waiting);
    assert.equal((await browser.tabs.query({})).length, 1);
});

test('a stored report of another shape starts empty, and the old upload-id list is dropped without being sent', async () => {
    const browser = fakeBrowser();
    const storage = browser.storage.session;
    for (const stored of [{ project: 'SHOP', reportTitle: '', items: [{ upload_id: 'u1' }], idempotencyKey: 'k' }, 'text', null, { project: 'SHOP', reportTitle: 'x', draftId: 7, draftUrl: null, screenshotCount: 1, pendingCapture: null }, { project: 'SHOP', reportTitle: 'x', draftId: null, draftUrl: null, screenshotCount: 11, pendingCapture: null }, { project: 'SHOP', reportTitle: 'x', draftId: null, draftUrl: null, screenshotCount: 0, pendingCapture: { idempotencyKey: 'k', path: '/api/v1/notebooks', bodyJson: '{}', origin: PINROUND_ORIGIN } }, { project: 'SHOP', reportTitle: 'x', draftId: null, draftUrl: null, screenshotCount: 0, pendingCapture: { idempotencyKey: 'k', path: '/api/v1/reports', bodyJson: '{}' } }]) {
        await storage.set({ [NEW_REPORT_KEY]: stored });
        assert.deepEqual(await loadNewReport(storage), { project: '', reportTitle: '', draftId: null, draftUrl: null, screenshotCount: 0, pendingCapture: null }, JSON.stringify(stored));
    }

    await storage.set({ [OLD_REPORT_CAPTURES_KEY]: { project: 'SHOP', reportTitle: 'Basket', items: [{ upload_id: 'u1', pins: [] }], idempotencyKey: 'k1' } });
    assert.equal(await dropOldReportCaptures(storage), true);
    assert.deepEqual(await storage.get(OLD_REPORT_CAPTURES_KEY), {});
    assert.equal(await dropOldReportCaptures(storage), false);
    await storage.set({ [OLD_REPORT_CAPTURES_KEY]: { items: [] } });
    assert.equal(await dropOldReportCaptures(storage), false, 'An empty old list had nothing to tell.');
    assert.deepEqual(await storage.get(OLD_REPORT_CAPTURES_KEY), {});
});

test('what is typed is kept until the draft is made, then the draft\'s project and title stay', async () => {
    const { api } = pinroundAndApi();
    const browser = await browserWithPins([]);
    await saveTypedReport(browser.storage.session, { project: 'SHOP', reportTitle: 'Typed' });
    assert.deepEqual(await loadNewReport(browser.storage.session), { project: 'SHOP', reportTitle: 'Typed', draftId: null, draftUrl: null, screenshotCount: 0, pendingCapture: null });

    await capture(api, browser, {}, { project: 'SHOP', reportTitle: 'Typed' });
    await saveTypedReport(browser.storage.session, { project: 'MOBILE', reportTitle: 'Changed' });

    const stored = await loadNewReport(browser.storage.session);
    assert.deepEqual([stored.project, stored.reportTitle], ['SHOP', 'Typed']);
});

test('a refusal of the POST drops its key, so the next capture is not answered with that refusal again', async () => {
    const { pinround, api } = pinroundAndApi();
    const browser = await browserWithPins([]);
    await capture(api, browser);
    const [draft] = pinround.myDrafts();
    draft.screenshots.push(...Array.from({ length: 9 }, (unused, index) => ({ upload_id: `seeded-${index}`, pins: [] })));

    await assert.rejects(capture(api, browser), { message: 'A report holds at most 10 screenshots.' });

    assert.equal((await loadNewReport(browser.storage.session)).pendingCapture, null);
    assert.equal(draft.screenshots.length, 10);
});

/** An api whose request to `path` first runs `meanwhile`, as a "Start over" or sign-out while the request is under way. */
function apiChangingStorageDuring(pinround, path, meanwhile) {
    return new PinroundApi(PINROUND_ORIGIN, TOKEN, async (href, init) => {
        if (path === new URL(href).pathname) {
            await meanwhile();
        }

        return pinround.fetchFn(href, init);
    });
}

test('a report forgotten while its first capture was under way is not written back when the answer comes', async () => {
    const pinround = fakePinround();
    const browser = await browserWithPins([{ x_pct: 1, y_pct: 2, note: 'Logo' }]);
    const storage = browser.storage.session;
    const api = apiChangingStorageDuring(pinround, '/api/v1/reports', () => clearNewReport(storage));

    await capture(api, browser);

    assert.equal(pinround.myDrafts().length, 1, 'pinround made the draft; only the popup forgot it.');
    assert.deepEqual(await storage.get(NEW_REPORT_KEY), {});
});

test('a refusal or a gone draft answered after the report was started over leaves the new report as it is', async () => {
    for (const [refuse, refusalMessage] of [
        [(draft) => { draft.owner = 'omar'; }, 'Not found. It does not exist, or you may not see it.'],
        [(draft) => { draft.screenshots.push(...Array.from({ length: 9 }, (unused, index) => ({ upload_id: `seeded-${index}`, pins: [] }))); }, 'A report holds at most 10 screenshots.'],
    ]) {
        const pinround = fakePinround();
        const browser = await browserWithPins([]);
        const storage = browser.storage.session;
        await capture(new PinroundApi(PINROUND_ORIGIN, TOKEN, pinround.fetchFn), browser);
        const [draft] = pinround.myDrafts();
        refuse(draft);
        const typedAfterStartOver = { project: 'MOBILE', reportTitle: 'Typed after starting over', draftId: null, draftUrl: null, screenshotCount: 0, pendingCapture: null };
        const api = apiChangingStorageDuring(pinround, `/api/v1/reports/${draft.id}/screenshots`, () => storage.set({ [NEW_REPORT_KEY]: typedAfterStartOver }));

        await assert.rejects(capture(api, browser), (error) => !(error instanceof TargetGoneError) && refusalMessage === error.message);

        assert.deepEqual(await loadNewReport(storage), typedAfterStartOver, refusalMessage);
    }
});

test('a 500 from pinround keeps the capture to send again, and each resend adds its picture exactly once', async () => {
    const { pinround, api } = pinroundAndApi();
    const browser = await browserWithPins([{ x_pct: 1, y_pct: 2, note: 'Logo' }]);
    const storage = browser.storage.session;
    pinround.failOnServer('/api/v1/reports');

    await assert.rejects(capture(api, browser), { message: NOT_SENT });
    assert.equal((await loadNewReport(storage)).pendingCapture?.path, '/api/v1/reports');
    assert.deepEqual(pinround.myDrafts(), []);
    const made = await sendPendingCapture(storage, api);
    const [draft, ...otherDrafts] = pinround.myDrafts();
    assert.deepEqual(otherDrafts, []);
    assert.deepEqual([made.draftId, made.screenshotCount, made.pendingCapture], [draft.id, 1, null]);

    pinround.failOnServer(`/api/v1/reports/${draft.id}/screenshots`);
    await assert.rejects(capture(api, browser), { message: NOT_SENT });
    assert.equal((await loadNewReport(storage)).pendingCapture?.path, `/api/v1/reports/${draft.id}/screenshots`);
    assert.equal(draft.screenshots.length, 1);
    const added = await sendPendingCapture(storage, api);

    assert.equal(added.screenshotCount, 2);
    assert.deepEqual(draft.screenshots.map((screenshot) => screenshot.upload_id), ['upload-1', 'upload-2']);
    assert.equal(pinround.myDrafts().length, 1);
});

test('a capture over pinround\'s 1 MB body cap is refused before its upload begins; one just under it is sent', async () => {
    const file = new Blob([new Uint8Array([137, 80, 78, 71])], { type: 'image/png' });
    // é is two bytes: the first note is under 1 MB in characters but over it in bytes.
    for (const [note, isRefused] of [['é'.repeat(500_000), true], ['é'.repeat(499_000), false]]) {
        const { pinround, api } = pinroundAndApi();
        const storage = fakeBrowser().storage.session;
        const capturing = addCaptureToNewReport(storage, api, TYPED, { file, texts: {}, pins: [{ x_pct: 1, y_pct: 1, note }] });

        if (isRefused) {
            await assert.rejects(capturing, { message: CAPTURE_TOO_LONG });
            assert.deepEqual(pinround.requests, []);
            assert.deepEqual(await storage.get(null), {});
        } else {
            assert.equal((await capturing).screenshotCount, 1);
            assert.equal(pinround.myDrafts()[0].screenshots[0].pins[0].note.length, 499_000);
        }
    }
});

test('a capture waiting to be sent to another pinround address is dropped with its report, and nothing is sent', async () => {
    const { pinround, api } = pinroundAndApi();
    const storage = fakeBrowser().storage.session;
    const otherOrigin = 'https://pinround.other-foods.example';
    await storage.set({ [NEW_REPORT_KEY]: { project: 'SHOP', reportTitle: 'Basket', draftId: null, draftUrl: null, screenshotCount: 0, pendingCapture: { idempotencyKey: 'k1', path: '/api/v1/reports', bodyJson: '{"project":"SHOP","title":"Basket","screenshots":[]}', origin: otherOrigin } } });

    await assert.rejects(sendPendingCapture(storage, api), { message: PENDING_FOR_OTHER_PINROUND });

    assert.deepEqual(pinround.requests, []);
    assert.deepEqual(await storage.get(null), {});
});
