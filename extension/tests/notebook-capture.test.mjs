import assert from 'node:assert/strict';
import { test } from 'node:test';
import { PinroundApi } from '../src/shared/api.js';
import { TargetGoneError } from '../src/capture/capture-target.js';
import { captureToTarget } from '../src/capture/capture-to-target.js';
import { NOTEBOOKS_WRITE_MISSING, captureToNotebook, makeNotebook } from '../src/capture/notebook-capture.js';
import { fakeBrowser } from './fake-browser.mjs';
import { PINROUND_ORIGIN, TOKEN, fakePinround } from './fake-pinround.mjs';

const SHOP_PAGE = { id: 7, url: 'https://shop.acme-foods.example/cart', title: 'Acme Foods shop', active: true };
const CHECKOUT = { notebook_id: 'nb000000-0000-4000-8000-000000000001', title: 'Checkout', last_used_at: '2026-03-01T08:00:00+00:00' };
const OMARS = { notebook_id: 'nb000000-0000-4000-8000-000000000002', title: 'Omar\'s', owner: 'omar', last_used_at: '2026-03-01T09:00:00+00:00' };
const PINS = [{ x_pct: 12.5, y_pct: 80, note: 'Button overlaps' }];
const CHECKOUT_TARGET = { notebookId: CHECKOUT.notebook_id, title: 'Checkout' };

async function browserWithPins(pins = PINS) {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE] });
    browser.clickIcon(SHOP_PAGE.id);
    await browser.scripting.executeScript({
        target: { tabId: SHOP_PAGE.id },
        func: (overlayPins) => {
            globalThis.closings = 0;
            globalThis.pinroundOverlay = { hideForCapture: async () => overlayPins, show: () => {}, close: () => { globalThis.closings += 1; } };
        },
        args: [pins],
    });

    return browser;
}

async function overlayClosings(browser) {
    const [{ result }] = await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => globalThis.closings });

    return result;
}

const apiOf = (pinround) => new PinroundApi(PINROUND_ORIGIN, TOKEN, pinround.fetchFn);
const notebookOf = (pinround, notebookId) => pinround.state.notebooks.get(notebookId);

test('a capture into a notebook uploads for it, then adds the picture with its texts and pins, and closes the overlay', async () => {
    const pinround = fakePinround({ notebooks: [CHECKOUT, OMARS] });
    const browser = await browserWithPins();

    const message = await captureToTarget({ api: apiOf(pinround), browser, storage: browser.storage.session, tabId: SHOP_PAGE.id, target: { kind: 'notebook', ...CHECKOUT_TARGET }, typed: { project: '', reportTitle: '' }, texts: { title: 'Cart' } });

    assert.equal(message, 'Screenshot 1 added to the notebook. Only you see it.');
    const [beginUpload, addScreenshot] = pinround.requests;
    assert.equal(pinround.requests.length, 2);
    assert.deepEqual([beginUpload[0], beginUpload[1], Object.keys(beginUpload[2])], ['POST', `/api/v1/notebooks/${CHECKOUT.notebook_id}/uploads`, ['mime', 'bytes', 'sha256']]);
    assert.equal(beginUpload[2].mime, 'image/png');
    assert.match(beginUpload[2].sha256, /^[0-9a-f]{64}$/);
    assert.deepEqual([addScreenshot[0], addScreenshot[1], addScreenshot[2]], ['POST', `/api/v1/notebooks/${CHECKOUT.notebook_id}/screenshots`, { upload_id: 'upload-1', title: 'Cart', pins: PINS }]);
    assert.deepEqual(notebookOf(pinround, CHECKOUT.notebook_id).screenshots, [{ upload_id: 'upload-1', title: 'Cart', summary: null, pins: PINS }]);
    assert.equal(pinround.state.storedFiles.has('upload-1'), true);
    assert.equal(await overlayClosings(browser), 1);
});

test('a full notebook refuses with pinround\'s message, keeps its ten, and the pins stay on the page', async () => {
    const full = { ...CHECKOUT, screenshots: Array.from({ length: 10 }, (unused, index) => ({ upload_id: `seeded-${index}`, pins: [] })) };
    const pinround = fakePinround({ notebooks: [full] });
    const browser = await browserWithPins();

    await assert.rejects(captureToNotebook(apiOf(pinround), browser, CHECKOUT_TARGET, SHOP_PAGE.id), { message: 'A notebook holds at most 10 screenshots.' });

    assert.equal(notebookOf(pinround, CHECKOUT.notebook_id).screenshots.length, 10);
    assert.equal(await overlayClosings(browser), 0);
});

test('without notebooks:write a capture says so, begins no upload and adds nothing, and the pins stay', async () => {
    const pinround = fakePinround({ scopes: ['read', 'reports:write'], notebooks: [CHECKOUT] });
    const browser = await browserWithPins();

    await assert.rejects(captureToNotebook(apiOf(pinround), browser, CHECKOUT_TARGET, SHOP_PAGE.id), { message: NOTEBOOKS_WRITE_MISSING });

    assert.deepEqual(pinround.requests.map(([method, path]) => [method, path]), [['POST', `/api/v1/notebooks/${CHECKOUT.notebook_id}/uploads`]]);
    assert.equal(pinround.state.uploads.size, 0);
    assert.deepEqual(notebookOf(pinround, CHECKOUT.notebook_id).screenshots, []);
    assert.equal(await overlayClosings(browser), 0);
});

test('another person\'s notebook, or one gone, is refused as gone and nothing is added', async () => {
    const pinround = fakePinround({ notebooks: [CHECKOUT, OMARS] });
    const browser = await browserWithPins();

    for (const notebook of [{ notebookId: OMARS.notebook_id, title: 'Omar\'s' }, { notebookId: 'nb000000-0000-4000-8000-000000000099', title: 'Deleted' }]) {
        await assert.rejects(captureToNotebook(apiOf(pinround), browser, notebook, SHOP_PAGE.id), (error) => error instanceof TargetGoneError && error.message === `The notebook "${notebook.title}" is gone. Choose where to put the next screenshot.`);
    }

    assert.deepEqual(notebookOf(pinround, OMARS.notebook_id).screenshots, []);
    assert.equal(pinround.state.uploads.size, 0);
    assert.equal(await overlayClosings(browser), 0);
});

test('a picture the notebook holds already is told apart from a new one', async () => {
    const pinround = fakePinround({ notebooks: [CHECKOUT] });
    const browser = await browserWithPins();
    const api = apiOf(pinround);
    await captureToNotebook(api, browser, CHECKOUT_TARGET, SHOP_PAGE.id);
    const sameUpload = pinround.requests.at(-1)[2];

    const answer = await api.call('POST', `/api/v1/notebooks/${CHECKOUT.notebook_id}/screenshots`, sameUpload);

    assert.deepEqual([answer.status, answer.screenshot_count], ['already_in_notebook', 1]);
    assert.equal(notebookOf(pinround, CHECKOUT.notebook_id).screenshots.length, 1);
});

test('a new notebook is made with its trimmed title; a blank or too long one is refused before any request', async () => {
    const pinround = fakePinround();
    const api = apiOf(pinround);

    await assert.rejects(makeNotebook(api, ' \r\n '), { message: 'Give the notebook a title.' });
    await assert.rejects(makeNotebook(api, 'é'.repeat(121)), { message: 'Keep the notebook title to 120 characters or fewer.' });
    assert.deepEqual(pinround.requests, []);

    const made = await makeNotebook(api, ' Checkout – Größe ');

    assert.deepEqual(pinround.requests.map(([method, path, body]) => [method, path, body]), [['POST', '/api/v1/notebooks', { title: 'Checkout – Größe' }]]);
    assert.deepEqual(made, { notebookId: [...pinround.state.notebooks.keys()][0], title: 'Checkout – Größe' });
    assert.equal(pinround.state.notebooks.get(made.notebookId).owner, 'lena');
});

test('without notebooks:write no notebook is made and the message says what to do', async () => {
    const pinround = fakePinround({ scopes: ['read'] });

    await assert.rejects(makeNotebook(apiOf(pinround), 'Checkout'), { message: NOTEBOOKS_WRITE_MISSING });

    assert.equal(pinround.state.notebooks.size, 0);
});
