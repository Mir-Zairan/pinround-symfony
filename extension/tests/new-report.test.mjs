import assert from 'node:assert/strict';
import { test } from 'node:test';
import { REPORT_CAPTURES_KEY, addCaptureToNewReport, clearReportCaptures, loadReportCaptures } from '../src/capture/new-report.js';
import { capturePinnedTab } from '../src/capture/page-pins.js';
import { fakeBrowser } from './fake-browser.mjs';

const SHOP_PAGE = { id: 7, url: 'https://shop.acme-foods.example/cart', title: 'Acme Foods shop', active: true };
const PICTURE = new Blob(['picture'], { type: 'image/png' });

/** An api that answers each upload with the next upload id and records the projects the uploads were begun for. */
function recordingApi() {
    const begunFor = [];

    return {
        begunFor,
        async call(method, path, body) {
            begunFor.push(body.project);

            return { upload_id: `upload-${begunFor.length}`, put_url: `https://storage.acme-foods.example/upload-${begunFor.length}`, headers: {} };
        },
        async putFile() {},
    };
}

const storedCaptures = async (storage) => (await storage.get(REPORT_CAPTURES_KEY))[REPORT_CAPTURES_KEY];

test('a capture with pins puts them, with its texts, into the stored new-report item', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE] });
    browser.clickIcon(SHOP_PAGE.id);
    await browser.scripting.executeScript({
        target: { tabId: SHOP_PAGE.id },
        func: () => {
            globalThis.pinroundOverlay = { hideForCapture: async () => [{ x_pct: 25.004, y_pct: 50, note: 'Price is wrong ' }], show: () => {} };
        },
    });
    const api = recordingApi();

    const { file, pins } = await capturePinnedTab(browser, SHOP_PAGE.id);
    await addCaptureToNewReport(browser.storage.session, api, { project: ' shop ', reportTitle: 'Checkout total is off' }, { file, texts: { title: 'Basket' }, pins });

    const stored = await storedCaptures(browser.storage.session);
    assert.equal(stored.project, 'SHOP');
    assert.equal(stored.reportTitle, 'Checkout total is off');
    assert.deepEqual(stored.items, [{ upload_id: 'upload-1', title: 'Basket', pins: [{ x_pct: 25, y_pct: 50, note: 'Price is wrong' }] }]);
    assert.deepEqual(api.begunFor, ['SHOP']);
});

test('a later capture keeps the saved project and adds its item after the first, with no pins when none were dropped', async () => {
    const { storage } = fakeBrowser();
    const api = recordingApi();
    await addCaptureToNewReport(storage.session, api, { project: 'SHOP', reportTitle: '' }, { file: PICTURE, texts: {}, pins: [{ x_pct: 1, y_pct: 2, note: '' }] });

    const updated = await addCaptureToNewReport(storage.session, api, { project: 'OTHER', reportTitle: 'Basket total' }, { file: PICTURE, texts: { summary: 'After paying' }, pins: [] });

    assert.deepEqual(api.begunFor, ['SHOP', 'SHOP']);
    assert.deepEqual(updated.items, [{ upload_id: 'upload-1', pins: [{ x_pct: 1, y_pct: 2, note: '' }] }, { upload_id: 'upload-2', summary: 'After paying', pins: [] }]);
    assert.deepEqual(await storedCaptures(storage.session), updated);
});

test('a blank project is refused before any upload, and nothing is stored', async () => {
    const { storage } = fakeBrowser();
    const api = recordingApi();

    await assert.rejects(addCaptureToNewReport(storage.session, api, { project: '  ', reportTitle: 'Basket total' }, { file: PICTURE, texts: {}, pins: [] }), { message: 'Type the project key, for example SHOP.' });

    assert.deepEqual(api.begunFor, []);
    assert.equal(await storedCaptures(storage.session), undefined);
});

test('the 11th capture is refused before its upload and the ten stay as they were', async () => {
    const { storage } = fakeBrowser();
    const api = recordingApi();
    for (let capture = 1; capture <= 10; capture += 1) {
        await addCaptureToNewReport(storage.session, api, { project: 'SHOP', reportTitle: '' }, { file: PICTURE, texts: {}, pins: [] });
    }
    const tenCaptures = await storedCaptures(storage.session);

    await assert.rejects(addCaptureToNewReport(storage.session, api, { project: 'SHOP', reportTitle: '' }, { file: PICTURE, texts: {}, pins: [] }), { message: 'A report holds at most 10 screenshots. Make the report draft first.' });

    assert.equal(api.begunFor.length, 10);
    assert.equal(tenCaptures.items.length, 10);
    assert.deepEqual(await storedCaptures(storage.session), tenCaptures);
});

test('with nothing stored the captures start empty, and clearing them starts over', async () => {
    const { storage } = fakeBrowser();
    const empty = await loadReportCaptures(storage.session);
    assert.deepEqual({ ...empty, idempotencyKey: 'any' }, { project: '', reportTitle: '', items: [], idempotencyKey: 'any' });
    assert.match(empty.idempotencyKey, /^[0-9a-f-]{36}$/);

    await addCaptureToNewReport(storage.session, recordingApi(), { project: 'SHOP', reportTitle: '' }, { file: PICTURE, texts: {}, pins: [] });
    await clearReportCaptures(storage.session);

    assert.equal(await storedCaptures(storage.session), undefined);
});
