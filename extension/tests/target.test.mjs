import assert from 'node:assert/strict';
import { test } from 'node:test';
import { ApiError, PinroundApi } from '../src/shared/api.js';
import { CAPTURE_TARGET_KEY, NEW_REPORT, TargetGoneError, forgetCaptureTarget, loadCaptureTarget, saveCaptureTarget, targetChoices } from '../src/capture/capture-target.js';
import { NEW_SCREENSHOT, captureToTarget, retakeChoices } from '../src/capture/capture-to-target.js';
import { shownText, unwrappedText } from '../src/capture/untrusted-text.js';
import { fakeBrowser } from './fake-browser.mjs';
import { NOT_FOUND_TEXT, PINROUND_ORIGIN, TOKEN, TRIES_FULL_TEXT, fakePinround } from './fake-pinround.mjs';

const SHOP_PAGE = { id: 7, url: 'https://shop.acme-foods.example/cart', title: 'Acme Foods shop', active: true };
const TICKETS = [
    { key: 'SHOP-7', title: 'Basket total is wrong', step: 'Building', now: 'Omar builds it', is_your_turn: true, is_assignee: false, updated_at: '2026-03-02T09:00:00+00:00' },
    { key: 'SHOP-12', title: 'Logo is cut off', step: 'Checking', now: 'Mia checks it', is_your_turn: true, is_assignee: true, updated_at: '2026-03-01T09:00:00+00:00' },
];
const NOTEBOOK_ID = (number) => `nb000000-0000-4000-8000-${String(number).padStart(12, '0')}`;
// Seeded out of last-used order, with one of another person's that must never be offered.
const NOTEBOOKS = [
    { notebook_id: NOTEBOOK_ID(1), title: 'Checkout', last_used_at: '2026-03-01T08:00:00+00:00' },
    { notebook_id: NOTEBOOK_ID(2), title: 'Fish & <chips>', last_used_at: '2026-03-01T12:00:00+00:00' },
    { notebook_id: NOTEBOOK_ID(3), title: 'Omar\'s notebook', owner: 'omar', last_used_at: '2026-03-01T13:00:00+00:00' },
];

const apiOf = (pinround) => new PinroundApi(PINROUND_ORIGIN, TOKEN, pinround.fetchFn);
const labelsOf = (choices) => choices.map((choice) => choice.label);

test('a chosen target is remembered for the session, and a stored shape this version does not know is no target', async () => {
    const { storage } = fakeBrowser();
    assert.equal(await loadCaptureTarget(storage.session), null);

    for (const target of [{ kind: NEW_REPORT }, { kind: 'ticket', ticketKey: 'SHOP-12' }, { kind: 'notebook', notebookId: NOTEBOOK_ID(1), title: 'Checkout' }]) {
        await saveCaptureTarget(storage.session, target);
        assert.deepEqual(await loadCaptureTarget(storage.session), target);
    }
    for (const stored of ['new-report', null, { kind: 'ticket' }, { kind: 'ticket', ticketKey: 'X'.repeat(65) }, { kind: 'notebook', notebookId: NOTEBOOK_ID(1) }, { kind: 'board', id: 'b1' }]) {
        await storage.session.set({ [CAPTURE_TARGET_KEY]: stored });
        assert.equal(await loadCaptureTarget(storage.session), null, JSON.stringify(stored));
    }
    await saveCaptureTarget(storage.session, { kind: NEW_REPORT });
    await forgetCaptureTarget(storage.session);
    assert.equal(await loadCaptureTarget(storage.session), null);
});

test('the picker offers a new report, my draft rounds and my own notebooks last used first, their titles unwrapped', async () => {
    const pinround = fakePinround({ tickets: TICKETS, notebooks: NOTEBOOKS });

    const { choices, target, droppedMessage, loadProblems } = await targetChoices(apiOf(pinround), null);

    assert.deepEqual(labelsOf(choices), ['A new report', 'My draft round on SHOP-7', 'My draft round on SHOP-12', 'Notebook: Fish & <chips>', 'Notebook: Checkout']);
    assert.deepEqual(choices[3].target, { kind: 'notebook', notebookId: NOTEBOOK_ID(2), title: 'Fish & <chips>' });
    assert.deepEqual([target, droppedMessage, loadProblems], [null, null, []]);
    assert.deepEqual(pinround.requests.map(([method, path]) => [method, path]), [['GET', '/api/v1/me/tickets?limit=50'], ['GET', '/api/v1/notebooks']]);
});

test('a remembered ticket or notebook pinround still lists stays chosen', async () => {
    const pinround = fakePinround({ tickets: TICKETS, notebooks: NOTEBOOKS });

    assert.deepEqual((await targetChoices(apiOf(pinround), { kind: 'ticket', ticketKey: 'SHOP-12' })).target, { kind: 'ticket', ticketKey: 'SHOP-12' });
    const notebook = await targetChoices(apiOf(pinround), { kind: 'notebook', notebookId: NOTEBOOK_ID(1), title: 'An older title' });
    assert.deepEqual(notebook.target, { kind: 'notebook', notebookId: NOTEBOOK_ID(1), title: 'Checkout' }, 'The title is pinround\'s, not the remembered one.');
    assert.equal(notebook.droppedMessage, null);
});

test('a remembered ticket or notebook gone from a full list is dropped with a plain message, and nothing offers it', async () => {
    const pinround = fakePinround({ tickets: TICKETS, notebooks: NOTEBOOKS });

    const ticket = await targetChoices(apiOf(pinround), { kind: 'ticket', ticketKey: 'SHOP-3' });
    assert.equal(ticket.target, null);
    assert.equal(ticket.droppedMessage, 'You can no longer add screenshots to your draft round on SHOP-3. Choose where to put the next one.');
    assert.equal(ticket.choices.some((choice) => choice.label.includes('SHOP-3')), false);

    const othersNotebook = await targetChoices(apiOf(pinround), { kind: 'notebook', notebookId: NOTEBOOK_ID(3), title: 'Omar\'s notebook' });
    assert.equal(othersNotebook.target, null);
    assert.equal(othersNotebook.droppedMessage, 'The notebook "Omar\'s notebook" is gone. Choose where to put the next screenshot.');
    assert.equal(othersNotebook.choices.some((choice) => choice.value.endsWith(NOTEBOOK_ID(3))), false);
});

test('a remembered target the lists cannot rule out stays offered: more tickets or notebooks than one page, or a list that did not load', async () => {
    const manyTickets = Array.from({ length: 51 }, (unused, index) => ({ ...TICKETS[0], key: `SHOP-${100 + index}` }));
    const manyNotebooks = Array.from({ length: 21 }, (unused, index) => ({ notebook_id: NOTEBOOK_ID(10 + index), title: `Notebook ${index}`, last_used_at: `2026-03-01T${String(index).padStart(2, '0')}:00:00+00:00` }));
    const full = fakePinround({ tickets: manyTickets, notebooks: manyNotebooks });

    const ticket = await targetChoices(apiOf(full), { kind: 'ticket', ticketKey: 'SHOP-150' });
    assert.deepEqual([ticket.target, ticket.droppedMessage], [{ kind: 'ticket', ticketKey: 'SHOP-150' }, null]);
    assert.equal(ticket.choices.filter((choice) => choice.label === 'My draft round on SHOP-150').length, 1);
    const notebook = await targetChoices(apiOf(full), { kind: 'notebook', notebookId: NOTEBOOK_ID(10), title: 'Notebook 0' });
    assert.deepEqual([notebook.target, notebook.droppedMessage], [{ kind: 'notebook', notebookId: NOTEBOOK_ID(10), title: 'Notebook 0' }, null]);
    assert.equal(notebook.choices.filter((choice) => choice.target.kind === 'notebook').length, 21, 'The 20 of the first page and the remembered one.');

    const unread = fakePinround({ scopes: ['rounds:write'], tickets: TICKETS });
    const unchecked = await targetChoices(apiOf(unread), { kind: 'ticket', ticketKey: 'SHOP-12' });
    assert.deepEqual(unchecked.target, { kind: 'ticket', ticketKey: 'SHOP-12' });
    assert.deepEqual(labelsOf(unchecked.choices), ['A new report', 'My draft round on SHOP-12']);
    assert.deepEqual(unchecked.loadProblems, [
        'Your tickets could not be loaded. This token does not have the "read" scope. Make a token with it in Settings.',
        'Your notebooks could not be loaded. This token does not have the "read" scope. Make a token with it in Settings.',
    ]);
});

test('a capture into a draft round pinround no longer offers is refused as gone, and the pins stay', async () => {
    const pinround = fakePinround({ tickets: TICKETS });
    const browser = fakeBrowser({ tabs: [SHOP_PAGE] });
    browser.clickIcon(SHOP_PAGE.id);
    await browser.scripting.executeScript({
        target: { tabId: SHOP_PAGE.id },
        func: () => {
            globalThis.closings = 0;
            globalThis.pinroundOverlay = { hideForCapture: async () => [{ x_pct: 10, y_pct: 20, note: 'Logo' }], show: () => {}, close: () => { globalThis.closings += 1; } };
        },
    });
    const capture = (ticketKey) => captureToTarget({ api: apiOf(pinround), browser, storage: browser.storage.session, tabId: SHOP_PAGE.id, target: { kind: 'ticket', ticketKey }, typed: { project: '', reportTitle: '' }, texts: {} });

    await assert.rejects(capture('SHOP-3'), (error) => error instanceof TargetGoneError && error.message.startsWith('You can no longer add screenshots to your draft round on SHOP-3.') && 'SHOP-3' === error.target.ticketKey && 'ticket' === error.target.kind);
    const [{ result: closingsAfterRefusal }] = await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => globalThis.closings });
    assert.equal(closingsAfterRefusal, 0);

    assert.equal(await capture('SHOP-12'), 'Added to your draft round on SHOP-12. Only you see it.');
    const add = pinround.requests.at(-1);
    assert.deepEqual([add[1], add[2]], ['/api/v1/tickets/SHOP-12/draft/screenshots', { upload_id: 'upload-1', pins: [{ x_pct: 10, y_pct: 20, note: 'Logo' }] }]);
    const [{ result: closings }] = await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => globalThis.closings });
    assert.equal(closings, 1);
});

const SCREENSHOT_ID = (number) => `sc000000-0000-4000-8000-${String(number).padStart(12, '0')}`;
// My draft on SHOP-12 with three chains, another person's draft on the same ticket and my draft on another ticket,
// neither of which may be offered.
const DRAFT_ROUNDS = [
    { ticketKey: 'SHOP-12', screenshots: [{ screenshot_id: SCREENSHOT_ID(1), title: 'Basket', try_count: 2 }, { screenshot_id: SCREENSHOT_ID(2), title: null }, { screenshot_id: SCREENSHOT_ID(3), title: 'Receipt', try_count: 5 }] },
    { ticketKey: 'SHOP-12', owner: 'omar', screenshots: [{ screenshot_id: SCREENSHOT_ID(9), title: 'Omar\'s picture' }] },
    { ticketKey: 'SHOP-7', screenshots: [{ screenshot_id: SCREENSHOT_ID(7), title: 'Total' }] },
];

/** The shop page with an overlay holding one pin, counting how often the overlay was closed. */
async function pinnedShopPage() {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE] });
    browser.clickIcon(SHOP_PAGE.id);
    await browser.scripting.executeScript({
        target: { tabId: SHOP_PAGE.id },
        func: () => {
            globalThis.closings = 0;
            globalThis.pinroundOverlay = { hideForCapture: async () => [{ x_pct: 10, y_pct: 20, note: 'Logo' }], show: () => {}, close: () => { globalThis.closings += 1; } };
        },
    });
    const closings = async () => (await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => globalThis.closings }))[0].result;

    return { browser, closings };
}

const captureToDraftOf = (pinround, browser, retakeOf) => captureToTarget({ api: apiOf(pinround), browser, storage: browser.storage.session, tabId: SHOP_PAGE.id, target: { kind: 'ticket', ticketKey: 'SHOP-12' }, typed: { project: '', reportTitle: '' }, texts: {}, retakeOf });
const triesOf = (pinround, ticketKey) => pinround.state.draftRounds.find((draft) => ticketKey === draft.ticketKey && 'lena' === draft.owner).chains.map((chain) => chain.tryIds.length);

test('the retake picker offers New screenshot first, then each screenshot of my own draft in its number order', async () => {
    const pinround = fakePinround({ tickets: TICKETS, draftRounds: DRAFT_ROUNDS });

    const { choices, loadProblem } = await retakeChoices(apiOf(pinround), 'SHOP-12');

    assert.deepEqual(choices, [
        { value: NEW_SCREENSHOT, label: 'New screenshot' },
        { value: SCREENSHOT_ID(1), label: 'Retake of screenshot 1 · Basket' },
        { value: SCREENSHOT_ID(2), label: 'Retake of screenshot 2' },
        { value: SCREENSHOT_ID(3), label: 'Retake of screenshot 3 · Receipt' },
    ]);
    assert.equal(loadProblem, null);
    assert.deepEqual(pinround.requests.map(([method, path]) => [method, path]), [['GET', '/api/v1/tickets/SHOP-12/draft/screenshots']]);

    const empty = await retakeChoices(apiOf(fakePinround({ tickets: TICKETS })), 'SHOP-12');
    assert.deepEqual([empty.choices, empty.loadProblem], [[{ value: NEW_SCREENSHOT, label: 'New screenshot' }], null]);
});

test('a retake title with markup, CRLF, a BOM or multibyte text is shown as plain text, controls written out, cut by characters', async () => {
    const title = '﻿<img src=x onerror=alert(1)> Kasse &amp;\r\nGröße 😀‮gnp';
    const pinround = fakePinround({ tickets: TICKETS, draftRounds: [{ ticketKey: 'SHOP-12', screenshots: [{ screenshot_id: SCREENSHOT_ID(1), title }, { screenshot_id: SCREENSHOT_ID(2), title: '😀'.repeat(121) }] }] });

    const { choices } = await retakeChoices(apiOf(pinround), 'SHOP-12');

    assert.deepEqual(choices.map((choice) => choice.label).slice(1), [
        'Retake of screenshot 1 · ﻿<img src=x onerror=alert(1)> Kasse &amp;\\u000D\\u000AGröße 😀\\u202Egnp',
        `Retake of screenshot 2 · ${'😀'.repeat(119)}…`,
    ]);
});

test('a list that cannot be loaded leaves only New screenshot with the reason, and never throws', async () => {
    const unreached = fakePinround({ tickets: TICKETS, draftRounds: DRAFT_ROUNDS });
    unreached.failToReach('/api/v1/tickets/SHOP-12/draft/screenshots');
    const cases = [
        [unreached, 'SHOP-12', 'Your screenshots to retake could not be loaded. Could not reach pinround. Check the address and your connection.'],
        [fakePinround({ scopes: ['rounds:write'], tickets: TICKETS, draftRounds: DRAFT_ROUNDS }), 'SHOP-12', 'Your screenshots to retake could not be loaded. This token does not have the "read" scope. Make a token with it in Settings.'],
        [fakePinround({ tickets: TICKETS, draftRounds: DRAFT_ROUNDS }), 'SHOP-3', `Your screenshots to retake could not be loaded. ${NOT_FOUND_TEXT}`],
    ];

    for (const [pinround, ticketKey, problem] of cases) {
        assert.deepEqual(await retakeChoices(apiOf(pinround), ticketKey), { choices: [{ value: NEW_SCREENSHOT, label: 'New screenshot' }], loadProblem: problem });
    }
});

test('a capture chosen as a retake sends retake_of and becomes that screenshot\'s next try; New screenshot sends none', async () => {
    const pinround = fakePinround({ tickets: TICKETS, draftRounds: DRAFT_ROUNDS });
    const { browser, closings } = await pinnedShopPage();

    assert.equal(await captureToDraftOf(pinround, browser, SCREENSHOT_ID(1)), 'Retake added to your draft round on SHOP-12. Only you see it.');
    assert.deepEqual(pinround.requests.at(-1).slice(1, 3), ['/api/v1/tickets/SHOP-12/draft/screenshots', { upload_id: 'upload-1', pins: [{ x_pct: 10, y_pct: 20, note: 'Logo' }], retake_of: SCREENSHOT_ID(1) }]);
    assert.deepEqual(triesOf(pinround, 'SHOP-12'), [3, 1, 5]);
    assert.equal(await closings(), 1);

    assert.equal(await captureToDraftOf(pinround, browser, null), 'Added to your draft round on SHOP-12. Only you see it.');
    assert.equal('retake_of' in pinround.requests.at(-1)[2], false);
    assert.deepEqual(triesOf(pinround, 'SHOP-12'), [3, 1, 5, 1]);
});

test('a refused retake shows pinround\'s reason, keeps the target and the pins, saves nothing, and the next capture works', async () => {
    const pinround = fakePinround({ tickets: TICKETS, draftRounds: DRAFT_ROUNDS });
    const { browser, closings } = await pinnedShopPage();
    const isPlainRefusal = (status, message) => (error) => error instanceof ApiError && !(error instanceof TargetGoneError) && status === error.status && message === error.message;

    // Another person's screenshot, one of my draft on another ticket, and an unknown id are all "not found".
    for (const foreignId of [SCREENSHOT_ID(9), SCREENSHOT_ID(7), 'not-a-screenshot']) {
        await assert.rejects(captureToDraftOf(pinround, browser, foreignId), isPlainRefusal(404, NOT_FOUND_TEXT));
    }
    await assert.rejects(captureToDraftOf(pinround, browser, SCREENSHOT_ID(3)), isPlainRefusal(422, TRIES_FULL_TEXT));
    // A ticket the upload no longer finds is still a gone target, retake or not.
    const goneTicket = { kind: 'ticket', ticketKey: 'SHOP-3' };
    await assert.rejects(captureToTarget({ api: apiOf(pinround), browser, storage: browser.storage.session, tabId: SHOP_PAGE.id, target: goneTicket, typed: { project: '', reportTitle: '' }, texts: {}, retakeOf: SCREENSHOT_ID(1) }), (error) => error instanceof TargetGoneError && 'SHOP-3' === error.target.ticketKey);
    assert.deepEqual(triesOf(pinround, 'SHOP-12'), [2, 1, 5]);
    assert.deepEqual(pinround.state.draftRounds.find((draft) => 'omar' === draft.owner).chains.map((chain) => chain.tryIds.length), [1]);
    assert.equal(await closings(), 0);

    assert.equal(await captureToDraftOf(pinround, browser, SCREENSHOT_ID(2)), 'Retake added to your draft round on SHOP-12. Only you see it.');
    assert.deepEqual(triesOf(pinround, 'SHOP-12'), [2, 2, 5]);
    assert.equal(await closings(), 1);
});

test('wrapped text is unwrapped as v1.yaml says, &amp; last, and text without both markers is kept as it is', () => {
    assert.equal(unwrappedText('<untrusted_text>Fish &amp; &lt;chips&gt; &amp;lt;b&amp;gt;</untrusted_text>'), 'Fish & <chips> &lt;b&gt;');
    assert.equal(unwrappedText('<untrusted_text></untrusted_text>'), '');
    assert.equal(unwrappedText('<untrusted_text>Checkout'), '<untrusted_text>Checkout');
    assert.equal(unwrappedText('Checkout &amp; pay'), 'Checkout &amp; pay');
    assert.equal(unwrappedText('<untrusted_text>Kasse – Größe 😀\uFEFF</untrusted_text>'), 'Kasse – Größe 😀\uFEFF');
});

test('shown text writes control and direction characters out and is cut by characters, not code units', () => {
    assert.equal(shownText('Basket\r\ntotal\u001B[2J\u2028end\u202Egnp.exe', 120), 'Basket\\u000D\\u000Atotal\\u001B[2J\\u2028end\\u202Egnp.exe');
    assert.equal(shownText('\uFEFFCheckout', 120), '\uFEFFCheckout');
    assert.equal(shownText('😀'.repeat(121), 120), `${'😀'.repeat(119)}…`);
    assert.equal(shownText('😀'.repeat(120), 120), '😀'.repeat(120));
    assert.equal(shownText('no final newline', 120), 'no final newline');
});
