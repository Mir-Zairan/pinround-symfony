import assert from 'node:assert/strict';
import { test } from 'node:test';
import '../src/overlay/dock.js';
import { OVERLAY_FILES } from '../src/capture/page-pins.js';
import { fakeBrowser } from './fake-browser.mjs';
import { fakePage } from './fake-page.mjs';
import { MAX_SCREENSHOTS, REPORT_TITLE_MAX_LENGTH as CAPTURE_REPORT_TITLE_MAX_LENGTH } from '../src/capture/capture.js';
import * as waitingCaptures from '../src/capture/waiting-captures.js';

const {
    DEFAULT_PLACE, EDGE_GAP_PX, LETTER_KEYS_KEY, TEXTS, MAX_SHOTS, REPORT_TITLE_MAX_LENGTH, THUMBNAIL_DATA_URL_PREFIX, MAX_THUMBNAIL_DATA_URL_LENGTH, MAX_THUMBNAIL_SIDE_PX, THUMBNAIL_BOX_PX,
    actionOfKey, checkedPageUrl, checkedPlace, checkedState, coverCropOf, coverSpotOf, isTypingTarget, loadPlace, nearerSide, placeKeyOf, refusalOfNotebookName, refusalOfTyped, savePlace, topPercentOf,
} = globalThis.pinroundDock;
const waiting = { ...waitingCaptures, MAX_SCREENSHOTS, REPORT_TITLE_MAX_LENGTH: CAPTURE_REPORT_TITLE_MAX_LENGTH };

const SHOP_HOST = 'shop.acme-foods.example';
const MOBILE_HOST = 'm.acme-foods.example';
const VIEWPORT = { width: 1280, height: 800 };
const STATE = {
    choices: [{ value: 'new-report', label: 'A new report' }, { value: 'ticket:SHOP-12', label: 'My draft round on SHOP-12' }],
    chosen: 'ticket:SHOP-12',
    count: 3,
    canSave: false,
};

/** The shop page with the overlay's files injected and the dock open; the extension answers every request with STATE. */
async function dockedPage({ siteHost = SHOP_HOST, storage = fakeBrowser().storage.local, answer = async () => ({ state: STATE }) } = {}) {
    const page = fakePage({ siteHost, viewport: VIEWPORT, storage, sendMessage: answer });
    page.inject(...OVERLAY_FILES);
    await page.evaluate('globalThis.pinroundOverlay.openDock()');
    await settled();
    const [host, ...otherHosts] = page.document.documentElement.children.filter((child) => 'PINROUND-OVERLAY' === child.tagName);
    assert.deepEqual(otherHosts, []);
    const root = page.closedRootOf(host);
    root.querySelector('.surface').box = { left: 0, top: 0, width: VIEWPORT.width, height: VIEWPORT.height };
    const inRoot = (selector) => root.querySelector(selector);
    // A button's accessible name: its aria-label, else its text.
    const buttonNamed = (text) => root.querySelectorAll('button').find((button) => (button.getAttribute('aria-label') ?? button.textContent).startsWith(text));

    return { page, host, root, inRoot, buttonNamed, storage };
}

const settled = () => new Promise((resolve) => setTimeout(resolve, 0));
const requestsOf = (page) => page.sentMessages.map((message) => message.pinroundDock);

test('the nearer side wins a drop, and the exact middle goes right', () => {
    assert.equal(nearerSide(100, 1280), 'left');
    assert.equal(nearerSide(639, 1280), 'left');
    assert.equal(nearerSide(640, 1280), 'right');
    assert.equal(nearerSide(1200, 1280), 'right');
});

test('the height is a whole percent of the room the dock has, kept inside 0 to 100', () => {
    // 800px viewport, 8px gaps, a 320px dock: 464px of room.
    assert.equal(topPercentOf(EDGE_GAP_PX, 800, 320), 0);
    assert.equal(topPercentOf(EDGE_GAP_PX + 232, 800, 320), 50);
    assert.equal(topPercentOf(EDGE_GAP_PX + 464, 800, 320), 100);
    assert.equal(topPercentOf(-50, 800, 320), 0);
    assert.equal(topPercentOf(2000, 800, 320), 100);
    assert.equal(topPercentOf(100, 300, 320), 0, 'A dock taller than the viewport has no room to move.');
    assert.equal(topPercentOf(Number.NaN, 800, 320), 0);
});

test('a stored place this version does not know is the default place', () => {
    assert.deepEqual(checkedPlace({ side: 'left', topPct: 20.4 }), { side: 'left', topPct: 20 });
    assert.deepEqual(checkedPlace({ side: 'right', topPct: 140 }), { side: 'right', topPct: 100 });
    for (const stored of [null, 'left', { side: 'top', topPct: 10 }, { side: 'left' }, { side: 'left', topPct: '10' }, { side: 'left', topPct: Number.POSITIVE_INFINITY }]) {
        assert.deepEqual(checkedPlace(stored), DEFAULT_PLACE, JSON.stringify(stored));
    }
});

test('the side and height round-trip through storage per host, and another host keeps its own', async () => {
    const { storage } = fakeBrowser();

    assert.deepEqual(await loadPlace(storage.local, SHOP_HOST), DEFAULT_PLACE);
    await savePlace(storage.local, SHOP_HOST, { side: 'left', topPct: 15 });
    await savePlace(storage.local, MOBILE_HOST, { side: 'right', topPct: 80 });

    assert.deepEqual(await loadPlace(storage.local, SHOP_HOST), { side: 'left', topPct: 15 });
    assert.deepEqual(await loadPlace(storage.local, MOBILE_HOST), { side: 'right', topPct: 80 });
    assert.deepEqual(await loadPlace(storage.local, 'other.acme-foods.example'), DEFAULT_PLACE);
    assert.deepEqual(await storage.local.get(null), {
        [placeKeyOf(MOBILE_HOST)]: { side: 'right', topPct: 80 },
        [placeKeyOf(SHOP_HOST)]: { side: 'left', topPct: 15 },
    });
});

test('P, C and H are the dock\'s keys only when trusted, bare, and not typed into a field', () => {
    const body = { tagName: 'BODY' };
    const key = (pressed, target = body, fields = {}) => actionOfKey({ isTrusted: true, key: pressed, target, composedPath: () => [target], ...fields });

    assert.deepEqual(['p', 'P', 'c', 'C', 'h', 'H', 'x', 'Enter'].map((pressed) => key(pressed)), ['pin', 'pin', 'capture', 'capture', 'shrink', 'shrink', null, null]);
    for (const field of [{ tagName: 'INPUT' }, { tagName: 'textarea' }, { tagName: 'SELECT' }, { tagName: 'DIV', isContentEditable: true }]) {
        assert.equal(isTypingTarget(field), true, JSON.stringify(field));
        assert.equal(key('c', field), null, JSON.stringify(field));
    }
    assert.equal(isTypingTarget(body), false);
    assert.equal(isTypingTarget(null), false);
    assert.equal(key('c', body, { isTrusted: false }), null, 'The page\'s own script cannot press a dock key.');
    for (const modifier of ['ctrlKey', 'altKey', 'metaKey', 'repeat', 'isComposing']) {
        assert.equal(key('c', body, { [modifier]: true }), null, modifier);
    }
});

test('the dock lives inside the overlay\'s one closed root, where the page cannot find it', async () => {
    const { page, host, inRoot } = await dockedPage();
    await page.evaluate('globalThis.pinroundOverlay.startPinning()');

    assert.equal(page.shadowRootCount, 1, 'Pinning and the dock share one shadow root.');
    assert.equal(host.shadowRoot, null);
    assert.deepEqual(page.document.querySelectorAll('*').map((element) => element.tagName), ['HTML', 'BODY', 'PINROUND-OVERLAY']);
    for (const selector of ['.dock', '.dock-handle', 'select', 'button', '[role]']) {
        assert.equal(page.document.querySelector(selector), null, selector);
    }
    assert.deepEqual([inRoot('.dock__bar').getAttribute('role'), inRoot('.dock__bar').getAttribute('aria-orientation')], ['toolbar', null], 'One horizontal bar.');
    assert.equal(inRoot('.surface').hidden, false);
});

test('P on the page turns pinning on and off, C asks for a capture and H shrinks the dock to its handle and back', async () => {
    const { page, inRoot, buttonNamed } = await dockedPage();
    const pinButton = buttonNamed(TEXTS.pin);
    assert.equal(inRoot('.surface').hidden, true, 'The dock opens without pinning.');

    page.keydown(page.document.body, 'p');
    assert.deepEqual([inRoot('.surface').hidden, pinButton.getAttribute('aria-pressed')], [false, 'true']);
    page.keydown(page.document.body, 'P');
    assert.deepEqual([inRoot('.surface').hidden, pinButton.getAttribute('aria-pressed')], [true, 'false']);

    page.keydown(page.document.body, 'c');
    await settled();
    assert.deepEqual(requestsOf(page), ['state', 'capture']);

    page.keydown(page.document.body, 'h');
    assert.deepEqual([inRoot('.dock').hidden, inRoot('.dock-handle').hidden], [true, false]);
    page.keydown(page.document.body, 'h');
    assert.deepEqual([inRoot('.dock').hidden, inRoot('.dock-handle').hidden], [false, true]);
});

test('keys typed in the page\'s input, textarea, select or editable text are the page\'s, as are keys its script makes', async () => {
    const { page, inRoot } = await dockedPage();
    const fields = [
        page.element('input', { type: 'text' }),
        page.element('textarea'),
        page.element('select'),
        page.element('div', { isContentEditable: true }),
    ];

    for (const field of fields) {
        for (const key of ['p', 'c', 'h']) {
            page.keydown(field, key);
        }
    }
    page.keydown(page.document.body, 'c', { isTrusted: false });
    page.keydown(page.document.body, 'c', { ctrlKey: true });
    await settled();

    assert.equal(inRoot('.surface').hidden, true);
    assert.equal(inRoot('.dock').hidden, false);
    assert.deepEqual(requestsOf(page), ['state'], 'Only the dock\'s own first request went out.');
});

test('inside the overlay the same keys work on its controls but never in the note being typed', async () => {
    const { page, inRoot } = await dockedPage();
    page.keydown(page.document.body, 'p');
    page.click(inRoot('.surface'), { clientX: 0, clientY: 0 });
    const noteField = inRoot('.note-field');
    assert.equal(inRoot('.note-editor').hidden, false);

    page.keydown(noteField, 'c');
    page.keydown(noteField, 'p');
    await settled();
    assert.deepEqual([requestsOf(page), inRoot('.surface').hidden], [['state'], false]);

    page.keydown(inRoot('.surface'), 'c');
    await settled();
    assert.deepEqual(requestsOf(page), ['state', 'capture']);
});

test('a dock key pressed in the page never reaches the page\'s listeners above the root, and one typed in the page still does', async () => {
    const { page, inRoot } = await dockedPage();
    const heardByPage = [];
    page.document.addEventListener('keydown', (event) => heardByPage.push(event.target.tagName));

    page.keydown(inRoot('.dock__action'), 'h');
    page.keydown(page.document.body, 'h');

    assert.deepEqual(heardByPage, ['BODY']);
});

/** What the page-wide P, C and H did: pinning on, the requests sent after the dock's first, the dock shrunk. */
async function pageKeysPressed(page, inRoot) {
    const requestsBefore = page.sentMessages.length;
    for (const key of ['p', 'c', 'h']) {
        page.keydown(page.document.body, key);
    }
    await settled();

    return { isPinning: !inRoot('.surface').hidden, requests: requestsOf(page).slice(requestsBefore), isShrunk: inRoot('.dock').hidden };
}

const NOTHING_DONE = { isPinning: false, requests: [], isShrunk: false };

test('Letter keys is on at first, and turned off it keeps P, C and H on the page from doing anything, and keeps that for every site', async () => {
    const { storage } = fakeBrowser();
    const { page, inRoot, buttonNamed } = await dockedPage({ storage: storage.local });
    const letterKeys = buttonNamed(TEXTS.letterKeys);
    assert.equal(letterKeys.getAttribute('aria-pressed'), 'true');

    page.click(letterKeys, { isTrusted: false });
    assert.equal(letterKeys.getAttribute('aria-pressed'), 'true', 'A click the page makes turns nothing off.');

    page.click(letterKeys);
    await settled();

    assert.equal(letterKeys.getAttribute('aria-pressed'), 'false');
    assert.deepEqual(await storage.local.get(LETTER_KEYS_KEY), { [LETTER_KEYS_KEY]: false });
    assert.deepEqual(await pageKeysPressed(page, inRoot), NOTHING_DONE);

    const otherSite = await dockedPage({ siteHost: MOBILE_HOST, storage: storage.local });
    assert.equal(otherSite.buttonNamed(TEXTS.letterKeys).getAttribute('aria-pressed'), 'false', 'The dock opens with the kept setting.');
    assert.deepEqual(await pageKeysPressed(otherSite.page, otherSite.inRoot), NOTHING_DONE);
});

test('with Letter keys off, the same keys pressed inside the dock still act', async () => {
    const { storage } = fakeBrowser();
    await storage.local.set({ [LETTER_KEYS_KEY]: false });
    const { page, inRoot, buttonNamed } = await dockedPage({ storage: storage.local });
    const capture = buttonNamed(TEXTS.capture);

    page.keydown(capture, 'p');
    assert.equal(inRoot('.surface').hidden, false);
    page.keydown(inRoot('.surface'), 'c');
    await settled();
    assert.deepEqual(requestsOf(page), ['state', 'capture']);
    page.keydown(inRoot('.surface'), 'h');
    assert.equal(inRoot('.dock').hidden, true);
});

test('Letter keys turned on again makes P, C and H on the page act, and a change made in another tab is followed here', async () => {
    const { storage } = fakeBrowser();
    await storage.local.set({ [LETTER_KEYS_KEY]: false });
    const { page, inRoot, buttonNamed } = await dockedPage({ storage: storage.local });
    const letterKeys = buttonNamed(TEXTS.letterKeys);

    page.click(letterKeys);
    await settled();
    assert.deepEqual([letterKeys.getAttribute('aria-pressed'), await storage.local.get(LETTER_KEYS_KEY)], ['true', { [LETTER_KEYS_KEY]: true }]);
    assert.deepEqual(await pageKeysPressed(page, inRoot), { isPinning: true, requests: ['capture'], isShrunk: true });
    page.keydown(page.document.body, 'h');
    page.keydown(page.document.body, 'p');

    await storage.local.set({ [LETTER_KEYS_KEY]: false });
    await settled();

    assert.equal(letterKeys.getAttribute('aria-pressed'), 'false');
    assert.deepEqual(await pageKeysPressed(page, inRoot), NOTHING_DONE);
});

test('a storage that cannot read the setting keeps Letter keys off for the page, as the person may have turned them off', async () => {
    const { storage } = fakeBrowser();
    const refusing = { ...storage.local, get: async () => { throw new Error('Extension context invalidated.'); } };
    const { page, inRoot, buttonNamed } = await dockedPage({ storage: refusing });
    const letterKeys = buttonNamed(TEXTS.letterKeys);

    assert.deepEqual([letterKeys.getAttribute('aria-pressed'), letterKeys.querySelector('.dock__switch').textContent], ['false', TEXTS.switchedOff]);
    assert.deepEqual(await pageKeysPressed(page, inRoot), NOTHING_DONE);
});

test('Letter keys says On or Off in words beside the fill, hidden from readers that hear aria-pressed', async () => {
    const { storage } = fakeBrowser();
    const { page, buttonNamed } = await dockedPage({ storage: storage.local });
    const letterKeys = buttonNamed(TEXTS.letterKeys);
    const state = letterKeys.querySelector('.dock__switch');
    assert.deepEqual([state.textContent, state.getAttribute('aria-hidden')], [TEXTS.switchedOn, 'true']);

    page.click(letterKeys);
    assert.equal(state.textContent, TEXTS.switchedOff);

    await storage.local.set({ [LETTER_KEYS_KEY]: true });
    await settled();
    assert.equal(state.textContent, TEXTS.switchedOn, 'A change made in another tab shows in words too.');
});

test('while a request runs, Capture, Save draft and the picker keep focus and stay in the Tab order, and a press on them asks nothing and says why', async () => {
    let release;
    const answers = [{ state: { ...STATE, chosen: 'new-report', count: 2, canSave: true } }];
    const { page, root, inRoot, buttonNamed } = await dockedPage({
        answer: async () => answers.shift() ?? new Promise((resolve) => {
            release = () => resolve({ state: { ...STATE, chosen: 'new-report', count: 3, canSave: true } });
        }),
    });
    const capture = buttonNamed(TEXTS.capture);
    const save = buttonNamed(TEXTS.saveDraft);
    const picker = inRoot('select');

    capture.focus();
    page.click(capture);
    await settled();
    assert.deepEqual(requestsOf(page), ['state', 'capture']);
    assert.deepEqual([capture, save, picker].map((control) => [control.disabled, control.getAttribute('aria-disabled')]), [[false, 'true'], [false, 'true'], [false, 'true']]);
    assert.equal(root.activeElement, capture, 'Focus stays on the pressed button.');

    page.click(capture);
    assert.equal(inRoot('.dock__status').textContent, TEXTS.busy);
    inRoot('.dock__status').textContent = '';
    page.click(save);
    assert.equal(inRoot('.dock__status').textContent, TEXTS.busy);
    picker.value = 'ticket:SHOP-12';
    page.dispatch(picker, 'change', { bubbles: false, composed: false });
    await settled();

    assert.deepEqual(requestsOf(page), ['state', 'capture']);
    assert.equal(picker.value, 'new-report', 'The picker shows the target still in use.');
    assert.equal(inRoot('.dock__status').textContent, TEXTS.busy);
    page.keydown(page.document.body, 'p');
    capture.focus();
    assert.equal(page.keydown(capture, 'Tab', { shiftKey: true }).defaultPrevented, false, 'Capture is a stop in the focus trap: Shift+Tab goes back from it.');

    release();
    await settled();
    await settled();
    assert.deepEqual([capture, save, picker].map((control) => control.getAttribute('aria-disabled')), ['false', 'false', 'false']);
    assert.equal(inRoot('.dock__status').textContent, '', 'The wait message goes once the request ends.');
});

test('a request\'s own message stays once it ends, even after a press while it ran', async () => {
    let release;
    const { page, inRoot, buttonNamed } = await dockedPage({
        answer: async ({ pinroundDock }) => ('state' === pinroundDock ? { state: STATE } : new Promise((resolve) => {
            release = () => resolve({ message: 'Screenshot 1 added to your draft round on SHOP-12. Only you see it.', state: STATE });
        })),
    });

    page.click(buttonNamed(TEXTS.capture));
    page.keydown(page.document.body, 'c');
    await settled();
    assert.equal(inRoot('.dock__status').textContent, TEXTS.busy, 'The key says why it did nothing, as the button does.');
    release();
    await settled();
    await settled();

    assert.equal(inRoot('.dock__status').textContent, 'Screenshot 1 added to your draft round on SHOP-12. Only you see it.');
});

test('dragging the grip snaps the dock to the nearer side and keeps the side and height for this host only', async () => {
    const { storage } = fakeBrowser();
    await savePlace(storage.local, MOBILE_HOST, { side: 'right', topPct: 80 });
    const { page, inRoot } = await dockedPage({ storage: storage.local });
    const dock = inRoot('.dock');
    const grip = inRoot('.dock__grip');
    dock.box = { left: 1100, top: 240, width: 172, height: 320 };

    page.dispatch(grip, 'pointerdown', { button: 0, pointerId: 1, clientX: 1150, clientY: 250 });
    assert.equal(dock.classList.contains('dock--dragging'), true);
    page.dispatch(grip, 'pointermove', { pointerId: 1, clientX: 600, clientY: 200 });
    page.dispatch(grip, 'pointerup', { pointerId: 1, clientX: 250, clientY: 110 });
    await settled();

    // Dropped with its left edge at 200px (centre 286px of 1280) and its top at 100px: (100 - 8) / (800 - 16 - 320).
    assert.deepEqual(await loadPlace(storage.local, SHOP_HOST), { side: 'left', topPct: 20 });
    assert.deepEqual(await loadPlace(storage.local, MOBILE_HOST), { side: 'right', topPct: 80 });
    assert.deepEqual([dock.classList.contains('dock--left'), dock.classList.contains('dock--right'), dock.classList.contains('dock--dragging')], [true, false, false]);
    assert.equal(dock.style.getPropertyValue('--top-pct'), '20');
    assert.equal(inRoot('.dock-handle').classList.contains('dock--left'), true, 'The handle shrinks to the same side.');

    dock.box = { left: 8, top: 100, width: 172, height: 320 };
    page.dispatch(grip, 'pointerdown', { button: 0, pointerId: 2, clientX: 20, clientY: 110 });
    page.dispatch(grip, 'pointerup', { pointerId: 2, clientX: 1000, clientY: 110 });
    await settled();
    assert.deepEqual(await loadPlace(storage.local, SHOP_HOST), { side: 'right', topPct: 20 });
});

test('a drag the browser cancels, or the page\'s script makes, moves nothing', async () => {
    const { storage } = fakeBrowser();
    const { page, inRoot } = await dockedPage({ storage: storage.local });
    const dock = inRoot('.dock');
    const grip = inRoot('.dock__grip');
    dock.box = { left: 1100, top: 240, width: 172, height: 320 };

    page.dispatch(grip, 'pointerdown', { button: 0, pointerId: 1, clientX: 1150, clientY: 250 });
    page.dispatch(grip, 'pointercancel', { pointerId: 1, clientX: 100, clientY: 100 });
    page.dispatch(grip, 'pointerdown', { button: 0, pointerId: 2, clientX: 1150, clientY: 250, isTrusted: false });
    page.dispatch(grip, 'pointerup', { pointerId: 2, clientX: 100, clientY: 100, isTrusted: false });
    await settled();

    assert.deepEqual(await storage.local.get(null), {});
    assert.deepEqual([dock.classList.contains('dock--right'), dock.classList.contains('dock--dragging'), dock.style.getPropertyValue('--top-pct')], [true, false, '50']);
});

test('the arrow keys on the grip move the dock without a mouse, and the height stops at its ends', async () => {
    const { storage } = fakeBrowser();
    const { page, inRoot } = await dockedPage({ storage: storage.local });
    const grip = inRoot('.dock__grip');

    page.keydown(grip, 'ArrowLeft');
    page.keydown(grip, 'ArrowUp');
    await settled();
    assert.deepEqual(await loadPlace(storage.local, SHOP_HOST), { side: 'left', topPct: 45 });

    for (let press = 0; press < 25; press += 1) {
        page.keydown(grip, 'ArrowDown');
    }
    await settled();
    assert.deepEqual(await loadPlace(storage.local, SHOP_HOST), { side: 'left', topPct: 100 });
});

test('the dock opens where this host last had it, and another host opens at the default place', async () => {
    const { storage } = fakeBrowser();
    await savePlace(storage.local, SHOP_HOST, { side: 'left', topPct: 10 });

    const shop = await dockedPage({ storage: storage.local });
    const mobile = await dockedPage({ siteHost: MOBILE_HOST, storage: storage.local });

    assert.deepEqual([shop.inRoot('.dock').classList.contains('dock--left'), shop.inRoot('.dock').style.getPropertyValue('--top-pct')], [true, '10']);
    assert.deepEqual([mobile.inRoot('.dock').classList.contains('dock--right'), mobile.inRoot('.dock').style.getPropertyValue('--top-pct')], [true, '50']);
});

test('the dock shows the target picker and the draft\'s count from the extension, and its texts only as text', async () => {
    const markup = '<img src=x onerror=alert(1)>\u001b[31m';
    const { inRoot, buttonNamed } = await dockedPage({
        answer: async () => ({ state: { ...STATE, choices: [...STATE.choices, { value: 'notebook:n1', label: `Notebook: ${markup}` }] }, message: markup, isError: true }),
    });
    const picker = inRoot('select');

    assert.deepEqual(picker.children.map((option) => [option.value, option.textContent]), [
        ['', 'Choose where to save'],
        ['new-report', 'A new report'],
        ['ticket:SHOP-12', 'My draft round on SHOP-12'],
        ['notebook:n1', `Notebook: ${markup}`],
    ]);
    assert.equal(picker.value, 'ticket:SHOP-12');
    assert.deepEqual([inRoot('.dock__count').hidden, inRoot('.dock__count').textContent], [false, '3']);
    assert.equal(buttonNamed(TEXTS.saveDraft).getAttribute('aria-disabled'), 'true', 'A draft round has nothing to save from here.');
    assert.equal(inRoot('.dock__status').textContent, markup);
    assert.equal(inRoot('.dock__status').children.length, 0, 'The message made no element.');
    assert.equal(inRoot('.dock__status').classList.contains('dock__status--error'), true);
});

test('an answer the dock cannot read, or no answer, is said as such and never as an empty dock', async () => {
    for (const answer of [async () => undefined, async () => ({ state: { choices: 'all' } }), async () => ({ message: 42 }), async () => { throw new Error('Could not establish connection. Receiving end does not exist.'); }]) {
        const { inRoot } = await dockedPage({ answer });

        assert.equal(inRoot('.dock__status').textContent, TEXTS.notAnswered, answer.toString());
        assert.equal(inRoot('.dock__status').classList.contains('dock__status--error'), true);
    }
});

const WAITING = { shots: [{ pinSpots: [{ x_pct: 25, y_pct: 40 }, { x_pct: 75, y_pct: 60 }] }, { pinSpots: [] }], needsTitle: true, needsProject: false };
const NEW_REPORT_STATE = { ...STATE, chosen: 'new-report', count: 2, canSave: true, newReport: WAITING };

/** The dock on a new report with two captures waiting; save-draft answers wait until released. */
async function dockWithCapturesWaiting(newReport = WAITING) {
    const saves = [];
    const docked = await dockedPage({
        answer: async ({ pinroundDock }) => ('state' === pinroundDock ? { state: { ...NEW_REPORT_STATE, newReport } } : new Promise((resolve) => saves.push(resolve))),
    });

    return { ...docked, saves };
}

const panelOf = (inRoot) => ({
    isOpen: !inRoot('.dock-save').hidden,
    heading: inRoot('.dock-save__heading').textContent,
    captions: inRoot('.dock-save__shots').querySelectorAll('figcaption').map((caption) => caption.textContent),
    hasTitle: !inRoot('.dock-save__title').parentNode.hidden,
    hasProject: !inRoot('.dock-save__project').parentNode.hidden,
    error: inRoot('.dock-save__error').textContent,
});

test('Save draft opens the panel with the captures, their pins as dots, and the title field for a new report, and asks nothing yet', async () => {
    const { page, root, inRoot, buttonNamed } = await dockWithCapturesWaiting();
    const save = buttonNamed(TEXTS.saveDraft);
    assert.deepEqual([save.getAttribute('aria-label'), save.getAttribute('aria-disabled'), inRoot('.dock__count').textContent], ['Save draft, 2 in it', 'false', '2']);
    assert.equal(panelOf(inRoot).isOpen, false);

    page.click(save, { isTrusted: false });
    assert.equal(panelOf(inRoot).isOpen, false, 'A click the page makes opens nothing.');
    page.click(save);

    assert.deepEqual(panelOf(inRoot), { isOpen: true, heading: 'Save 2 captures', captions: ['Shot 1 · 2 pins', 'Shot 2 · 0 pins'], hasTitle: true, hasProject: false, error: '' });
    assert.deepEqual(inRoot('.dock-save__thumb').querySelectorAll('span').map((dot) => [dot.style.left, dot.style.top]), [['25%', '40%'], ['75%', '60%']]);
    assert.equal(inRoot('.dock-save').getAttribute('role'), 'dialog');
    assert.equal(inRoot('.dock-save__goes').textContent, 'Goes to a new report draft. Nobody is told yet. You send it from pinround.');
    assert.equal(root.activeElement, inRoot('.dock-save__title'), 'Focus goes to the title.');
    assert.equal(save.getAttribute('aria-expanded'), 'true');
    assert.deepEqual(requestsOf(page), ['state']);
});

test('the panel refuses an empty or too long title inline with the plain message, marks the field and sends nothing', async () => {
    const { page, root, inRoot, buttonNamed } = await dockWithCapturesWaiting();
    page.click(buttonNamed(TEXTS.saveDraft));
    const title = inRoot('.dock-save__title');

    for (const typed of ['', '   ', '\u{1F600}'.repeat(201)]) {
        title.value = typed;
        page.click(inRoot('.dock-save__send'));

        assert.deepEqual([panelOf(inRoot).error, title.getAttribute('aria-invalid'), root.activeElement], [TEXTS.titleRefused, 'true', title], JSON.stringify(typed.slice(0, 4)));
    }
    assert.deepEqual(requestsOf(page), ['state']);
    assert.equal(panelOf(inRoot).isOpen, true);
});

test('typed rules: a title of 200 characters and a project key of 20 pass, one more of either is refused, and only the fields shown are asked', () => {
    const asked = { needsTitle: true, needsProject: true };
    assert.equal(refusalOfTyped({ reportTitle: '\u{1F600}'.repeat(200), project: 'S'.repeat(20) }, asked), null);
    assert.equal(refusalOfTyped({ reportTitle: '\u{1F600}'.repeat(201), project: 'SHOP' }, asked), TEXTS.titleRefused);
    assert.equal(refusalOfTyped({ reportTitle: 'Total is wrong', project: 'S'.repeat(21) }, asked), TEXTS.projectRefused);
    assert.equal(refusalOfTyped({ reportTitle: 'Total is wrong', project: ' ' }, asked), TEXTS.projectRefused);
    assert.equal(refusalOfTyped({ reportTitle: '', project: '' }, { needsTitle: false, needsProject: false }), null, 'A draft already made needs neither.');
});

test('the panel saves with the typed title, waits on one request, then closes and says the draft is saved', async () => {
    const { page, root, inRoot, buttonNamed, saves } = await dockWithCapturesWaiting();
    const save = buttonNamed(TEXTS.saveDraft);
    page.click(save);
    page.typeInto(inRoot('.dock-save__title'), 'Total is wrong');

    page.keydown(inRoot('.dock-save__title'), 'Enter');
    page.click(inRoot('.dock-save__send'));
    await settled();

    assert.deepEqual(page.sentMessages.slice(1), [{ pinroundDock: 'save-draft', value: { reportTitle: 'Total is wrong', project: '' } }], 'The second press waits on the first.');
    assert.equal(inRoot('.dock-save__send').getAttribute('aria-disabled'), 'true');
    saves[0]({ message: 'Draft saved with 2 captures. Open it in pinround to send it.', state: { ...STATE, chosen: 'new-report', count: 0, canSave: false, newReport: { ...WAITING, shots: [] } } });
    await settled();
    await settled();

    assert.equal(panelOf(inRoot).isOpen, false);
    assert.deepEqual([inRoot('.dock__status').textContent, inRoot('.dock__status').classList.contains('dock__status--error')], ['Draft saved with 2 captures. Open it in pinround to send it.', false]);
    assert.deepEqual([save.getAttribute('aria-disabled'), save.getAttribute('aria-label')], ['true', 'Save draft, 0 in it']);
    assert.equal(root.activeElement, save, 'Focus goes back to Save draft.');
    assert.equal(inRoot('.dock-save__title').value, '', 'The next report starts with an empty title.');
});

test('a save pinround refuses keeps the panel open with the refusal in it and the title as typed', async () => {
    const { page, inRoot, buttonNamed, saves } = await dockWithCapturesWaiting();
    page.click(buttonNamed(TEXTS.saveDraft));
    page.typeInto(inRoot('.dock-save__title'), 'Total is wrong');
    page.click(inRoot('.dock-save__send'));
    await settled();

    saves[0]({ message: 'The screenshot did not reach pinround. Check your connection and press Send it again.', isError: true, state: NEW_REPORT_STATE });
    await settled();
    await settled();

    assert.deepEqual([panelOf(inRoot).isOpen, panelOf(inRoot).error, inRoot('.dock-save__title').value], [true, 'The screenshot did not reach pinround. Check your connection and press Send it again.', 'Total is wrong']);
    assert.equal(inRoot('.dock__status').textContent, '');
});

test('with no project key known the panel asks for it too, and a draft already made asks for neither', async () => {
    const asksProject = await dockWithCapturesWaiting({ ...WAITING, needsProject: true });
    asksProject.page.click(asksProject.buttonNamed(TEXTS.saveDraft));
    asksProject.page.typeInto(asksProject.inRoot('.dock-save__title'), 'Total is wrong');
    asksProject.page.click(asksProject.inRoot('.dock-save__send'));
    assert.deepEqual([panelOf(asksProject.inRoot).hasProject, panelOf(asksProject.inRoot).error], [true, TEXTS.projectRefused]);
    asksProject.page.typeInto(asksProject.inRoot('.dock-save__project'), 'shop');
    asksProject.page.click(asksProject.inRoot('.dock-save__send'));
    await settled();
    assert.deepEqual(asksProject.page.sentMessages.at(-1), { pinroundDock: 'save-draft', value: { reportTitle: 'Total is wrong', project: 'shop' } });

    const draftMade = await dockWithCapturesWaiting({ ...WAITING, needsTitle: false });
    draftMade.page.click(draftMade.buttonNamed(TEXTS.saveDraft));
    assert.deepEqual([panelOf(draftMade.inRoot).hasTitle, panelOf(draftMade.inRoot).hasProject], [false, false]);
    assert.equal(draftMade.root.activeElement, draftMade.inRoot('.dock-save__send'));
});

test('Keep testing and Escape close the panel and give focus back to Save draft, and Escape there ends no pinning', async () => {
    const { page, root, inRoot, buttonNamed } = await dockWithCapturesWaiting();
    const save = buttonNamed(TEXTS.saveDraft);
    page.click(save);
    page.click(inRoot('.dock-save__keep'));
    assert.deepEqual([panelOf(inRoot).isOpen, root.activeElement, save.getAttribute('aria-expanded')], [false, save, 'false']);

    page.keydown(page.document.body, 'p');
    page.click(inRoot('.surface'), { clientX: 100, clientY: 100 });
    page.keydown(inRoot('.note-field'), 'Escape');
    page.click(save);
    page.keydown(inRoot('.dock-save__title'), 'Escape');

    assert.deepEqual([panelOf(inRoot).isOpen, root.activeElement, inRoot('.surface').hidden, root.querySelectorAll('.pin').length], [false, save, false, 1]);
});

test('Save draft for a draft round or notebook is pressed for nothing and says why in its hint', async () => {
    const { page, inRoot, buttonNamed } = await dockedPage();
    const save = buttonNamed(TEXTS.saveDraft);

    page.click(save);

    assert.deepEqual([save.getAttribute('aria-disabled'), save.title, panelOf(inRoot).isOpen], ['true', TEXTS.saveDraftCannot, false]);
    assert.deepEqual(requestsOf(page), ['state']);
});

test('a state with a waiting report the dock cannot read is no state', () => {
    assert.deepEqual(checkedState({ ...NEW_REPORT_STATE, newReport: undefined }).newReport, null);
    for (const newReport of [{ ...WAITING, shots: 'all' }, { ...WAITING, needsTitle: 'yes' }, { ...WAITING, shots: [{ pinSpots: [{ x_pct: 120, y_pct: 10 }] }] }, { ...WAITING, shots: Array.from({ length: 11 }, () => ({ pinSpots: [] })) }]) {
        assert.equal(checkedState({ ...NEW_REPORT_STATE, newReport }), null, JSON.stringify(newReport).slice(0, 60));
    }
});

const THUMBNAIL = { dataUrl: `${THUMBNAIL_DATA_URL_PREFIX}/9j/4AAQ`, width: 213, height: 120 };

test('the dock\'s caps and thumbnail rules are the ones capture.js and waiting-captures.js keep', () => {
    assert.deepEqual(
        [MAX_SHOTS, REPORT_TITLE_MAX_LENGTH, THUMBNAIL_DATA_URL_PREFIX, MAX_THUMBNAIL_DATA_URL_LENGTH, MAX_THUMBNAIL_SIDE_PX, { ...THUMBNAIL_BOX_PX }],
        [waiting.MAX_SCREENSHOTS, waiting.REPORT_TITLE_MAX_LENGTH, waiting.THUMBNAIL_DATA_URL_PREFIX, waiting.MAX_THUMBNAIL_DATA_URL_LENGTH, waiting.MAX_THUMBNAIL_SIDE_PX, { ...waiting.THUMBNAIL_BOX_PX }],
    );
});

test('a wide picture covers the box with its middle, a tall one with its middle band, and pins move with the crop', () => {
    // A 1920x1080 window in the panel's 184x120 box: the full height, the middle 1656 of the width.
    assert.deepEqual(coverCropOf(1920, 1080, 184, 120), { x: 132, y: 0, width: 1656, height: 1080 });
    const tall = coverCropOf(360, 640, 184, 120);
    assert.deepEqual([tall.x, tall.width, Math.round(tall.y * 10) / 10, Math.round(tall.height * 10) / 10], [0, 360, 202.6, 234.8]);

    const moved = coverSpotOf({ x_pct: 25, y_pct: 40 }, 1920, 1080, 184, 120);
    assert.deepEqual([Math.round(moved.x_pct * 100) / 100, moved.y_pct], [21.01, 40]);
    assert.deepEqual(coverSpotOf({ x_pct: 2, y_pct: 99 }, 1920, 1080, 184, 120), { x_pct: 0, y_pct: 99 }, 'A pin the crop cuts off stays at the edge.');
    assert.deepEqual(coverSpotOf({ x_pct: 50, y_pct: 50 }, 184, 120, 184, 120), { x_pct: 50, y_pct: 50 }, 'A picture of the box\'s shape is not cropped.');
});

test('each shot with a thumbnail shows it under its pins, its dots where the crop puts them; one without shows a plain box', async () => {
    const { page, inRoot, buttonNamed } = await dockWithCapturesWaiting({ ...WAITING, shots: [{ ...WAITING.shots[0], thumbnail: { ...THUMBNAIL, width: 1920, height: 1080 } }, { ...WAITING.shots[1], thumbnail: null }] });
    page.click(buttonNamed(TEXTS.saveDraft));

    const [withPicture, withoutPicture] = inRoot('.dock-save__shots').querySelectorAll('.dock-save__thumb');
    const [picture, ...otherPictures] = withPicture.querySelectorAll('canvas');
    assert.deepEqual(otherPictures, []);
    assert.deepEqual([picture.className, picture.width, picture.height], ['dock-save__picture', 184, 120]);
    assert.deepEqual(withPicture.children.map((child) => child.tagName), ['CANVAS', 'SPAN', 'SPAN'], 'The dots come after the picture, so they show on it.');
    const dots = withPicture.querySelectorAll('span').map((dot) => [Math.round(parseFloat(dot.style.left) * 100) / 100, dot.style.top]);
    assert.deepEqual(dots, [[21.01, '40%'], [78.99, '60%']]);
    assert.deepEqual(withoutPicture.querySelectorAll('canvas'), []);
    assert.deepEqual(panelOf(inRoot).captions, ['Shot 1 · 2 pins', 'Shot 2 · 0 pins']);
});

test('a thumbnail the dock cannot read is none: the shot and its pins still show', () => {
    for (const thumbnail of [{ ...THUMBNAIL, dataUrl: 'data:image/png;base64,iVBORw0KGgo=' }, { ...THUMBNAIL, dataUrl: 'https://evil.example/x.jpg' }, { ...THUMBNAIL, dataUrl: THUMBNAIL_DATA_URL_PREFIX + 'A'.repeat(MAX_THUMBNAIL_DATA_URL_LENGTH) }, { ...THUMBNAIL, width: 0 }, { ...THUMBNAIL, height: 2001 }, { ...THUMBNAIL, width: 1.5 }, 'picture', undefined]) {
        const state = checkedState({ ...NEW_REPORT_STATE, newReport: { ...WAITING, shots: [{ ...WAITING.shots[0], thumbnail }] } });
        assert.deepEqual(state.newReport.shots, [{ pinSpots: WAITING.shots[0].pinSpots, thumbnail: null }], JSON.stringify(thumbnail)?.slice(0, 60));
    }
    assert.deepEqual(checkedState({ ...NEW_REPORT_STATE, newReport: { ...WAITING, shots: [{ pinSpots: [], thumbnail: THUMBNAIL }] } }).newReport.shots, [{ pinSpots: [], thumbnail: THUMBNAIL }]);
});

test('the shrunk puck shows the captures waiting and opens the dock again; a drag of it moves it and opens nothing', async () => {
    const { storage } = fakeBrowser();
    const { page, inRoot } = await dockedPage({ storage: storage.local, answer: async () => ({ state: NEW_REPORT_STATE }) });
    const puck = inRoot('.dock-handle');
    page.keydown(page.document.body, 'h');
    assert.deepEqual([puck.hidden, puck.getAttribute('aria-label'), puck.querySelector('.dock__count').hidden, puck.querySelector('.dock__count').textContent], [false, 'Open the pinround dock, 2 captures', false, '2']);

    puck.box = { left: 1226, top: 377, width: 46, height: 46 };
    page.dispatch(puck, 'pointerdown', { button: 0, pointerId: 1, clientX: 1249, clientY: 400 });
    page.dispatch(puck, 'pointermove', { pointerId: 1, clientX: 100, clientY: 100 });
    page.dispatch(puck, 'pointerup', { pointerId: 1, clientX: 100, clientY: 100 });
    page.click(puck);
    await settled();
    assert.deepEqual([inRoot('.dock').hidden, (await loadPlace(storage.local, SHOP_HOST)).side], [true, 'left'], 'The drag moved the puck to the left and the click after it opened nothing.');

    page.dispatch(puck, 'pointerdown', { button: 0, pointerId: 2, clientX: 30, clientY: 100 });
    page.dispatch(puck, 'pointerup', { pointerId: 2, clientX: 31, clientY: 101 });
    page.click(puck);
    assert.equal(inRoot('.dock').hidden, false, 'A press that barely moved is a click.');
});

test('the shrunk puck names one waiting capture in the singular', async () => {
    const { page, inRoot } = await dockedPage({ answer: async () => ({ state: { ...NEW_REPORT_STATE, count: 1 } }) });
    page.keydown(page.document.body, 'h');
    const puck = inRoot('.dock-handle');

    assert.deepEqual([puck.getAttribute('aria-label'), puck.querySelector('.dock__count').textContent], ['Open the pinround dock, 1 capture', '1']);
});

test('Pin, Capture, Play and Save draft carry their names in aria-label, so they keep them where a narrow screen hides the words', async () => {
    const { root } = await dockedPage();
    const labels = root.querySelectorAll('button').map((button) => button.getAttribute('aria-label'));

    assert.deepEqual(labels.filter((label) => ['Pin', 'Capture', 'Play', 'Save draft, 3 in it'].includes(label)), ['Pin', 'Capture', 'Play', 'Save draft, 3 in it']);
});

test('picking a target asks the extension to remember it', async () => {
    const { page, inRoot } = await dockedPage();
    const picker = inRoot('select');
    picker.value = 'new-report';

    page.dispatch(picker, 'change', { bubbles: false, composed: false });
    await settled();

    assert.deepEqual(page.sentMessages.slice(1), [{ pinroundDock: 'choose', value: 'new-report' }]);
});

test('with the dock open, Escape and a saved capture drop the pins and end pinning but keep the dock', async () => {
    const { page, host, root, inRoot } = await dockedPage();
    page.keydown(page.document.body, 'p');
    page.click(inRoot('.surface'), { clientX: 0, clientY: 0 });
    page.keydown(inRoot('.note-field'), 'Escape');
    assert.equal(root.querySelectorAll('.pin').length, 1);

    page.keydown(inRoot('.surface'), 'Escape');

    assert.equal(root.querySelectorAll('.pin').length, 0);
    assert.deepEqual([host.isConnected, inRoot('.surface').hidden, inRoot('.dock').hidden], [true, true, false]);
    assert.equal(root.activeElement.textContent, 'PinP', 'Focus moves from the hidden surface to the Pin button.');

    page.keydown(page.document.body, 'p');
    page.click(inRoot('.surface'), { clientX: 0, clientY: 0 });
    page.evaluate('globalThis.pinroundOverlay.close()');
    assert.deepEqual([root.querySelectorAll('.pin').length, host.isConnected, inRoot('.surface').hidden], [0, true, true]);
    assert.equal(page.evaluate('typeof globalThis.pinroundOverlay'), 'object');
});

test('without the dock, pinning works as before: it starts on, and Escape removes the overlay with its pins', async () => {
    const page = fakePage({ storage: fakeBrowser().storage.local });
    page.inject(...OVERLAY_FILES);
    assert.equal(page.document.querySelector('pinround-overlay'), null, 'Injecting the files alone shows nothing.');
    page.evaluate('globalThis.pinroundOverlay.startPinning()');
    const host = page.document.querySelector('pinround-overlay');
    const root = page.closedRootOf(host);
    assert.equal(root.querySelector('.surface').hidden, false);

    page.keydown(page.document.body, 'c');
    page.keydown(root.querySelector('.surface'), 'p');
    await settled();
    assert.deepEqual(page.sentMessages, [], 'With no dock the keys are no shortcut.');
    assert.equal(root.querySelector('.surface').hidden, false);

    page.keydown(root.querySelector('.surface'), 'Escape');
    assert.equal(host.isConnected, false);
    assert.equal(page.evaluate('typeof globalThis.pinroundOverlay'), 'undefined');
});

test('a capture takes the whole overlay out of the picture and puts it back as it was, focus on the surface only while pinning', async () => {
    const { page, host, root, inRoot } = await dockedPage();

    await page.evaluate('globalThis.pinroundOverlay.hideForCapture()');
    assert.equal(host.isConnected, false, 'The dock is not in the picture.');
    page.evaluate('globalThis.pinroundOverlay.show()');
    assert.deepEqual([host.isConnected, root.activeElement], [true, null]);

    page.keydown(page.document.body, 'p');
    page.document.body.focus();
    await page.evaluate('globalThis.pinroundOverlay.hideForCapture()');
    page.evaluate('globalThis.pinroundOverlay.show()');
    assert.equal(root.activeElement, inRoot('.surface'));
});

/** A person's click on the pinning surface at `point` (CSS px), the note typed in the form it opens, then Escape. */
function dropPinWithNote(page, inRoot, [clientX, clientY], note) {
    page.click(inRoot('.surface'), { clientX, clientY });
    page.typeInto(inRoot('.note-field'), note);
    page.keydown(inRoot('.note-field'), 'Escape');
}

/** A person's click on the pin with this number, then on Remove in the note form it opens. */
function removePinByForm(page, root, inRoot, pinNumber) {
    page.click(root.querySelectorAll('.pin')[pinNumber - 1]);
    page.click(inRoot('.note-editor__remove'));
}

const pinNamesOf = (root) => root.querySelectorAll('.pin').map((pin) => pin.getAttribute('aria-label'));
/** The pins the overlay hands a capture, as plain data from the page's realm. */
const capturedPinsOf = async (page) => JSON.parse(JSON.stringify(await page.evaluate('globalThis.pinroundOverlay.hideForCapture()')));

test('a click on a pin chooses it with a ring and opens its note form under it, with Remove for that pin only; a click the page makes removes nothing', async () => {
    const { page, root, inRoot } = await dockedPage();
    page.keydown(page.document.body, 'p');
    dropPinWithNote(page, inRoot, [320, 200], 'Price is wrong');
    dropPinWithNote(page, inRoot, [640, 400], 'Logo is cut off');
    dropPinWithNote(page, inRoot, [960, 600], 'Total is wrong');
    assert.deepEqual(root.querySelectorAll('.pin__note').map((label) => [label.textContent, label.hidden]), [['Price is wrong', false], ['Logo is cut off', false], ['Total is wrong', false]], 'A pin with a note shows it beside it.');
    assert.deepEqual(root.querySelectorAll('.pin').map((pin) => pin.classList.contains('pin--selected')), [false, false, false]);

    const second = root.querySelectorAll('.pin')[1];
    page.click(second);
    assert.deepEqual([second.classList.contains('pin--selected'), second.querySelector('.pin__note').hidden, inRoot('.note-editor').hidden], [true, true, false]);
    assert.deepEqual([inRoot('.note-field').value, inRoot('.note-field').getAttribute('aria-label'), inRoot('.note-field').placeholder], ['Logo is cut off', 'Note for pin 2', 'What is wrong here?']);
    assert.deepEqual([inRoot('.note-editor').style.getPropertyValue('--pin-x'), inRoot('.note-editor').style.top, inRoot('.note-editor').classList.contains('note-editor--above')], ['50%', '50%', false], 'The form sits just below its pin; the style keeps it inside the viewport.');
    assert.equal(root.activeElement, inRoot('.note-field'));
    assert.equal(inRoot('.note-editor__remove').getAttribute('aria-label'), 'Remove pin 2');

    page.click(inRoot('.note-editor__remove'), { isTrusted: false });
    assert.equal(pinNamesOf(root).length, 3);

    page.click(inRoot('.note-editor__remove'));

    assert.deepEqual(pinNamesOf(root), ['Pin 1: Price is wrong', 'Pin 2: Total is wrong']);
    assert.equal(inRoot('.note-editor').hidden, true);
    assert.deepEqual(await capturedPinsOf(page), [{ x_pct: 25, y_pct: 25, note: 'Price is wrong' }, { x_pct: 75, y_pct: 75, note: 'Total is wrong' }]);
});

test('Save keeps the note and drops the ring, and a pin near the bottom opens its form above it', async () => {
    const { page, root, inRoot } = await dockedPage();
    page.keydown(page.document.body, 'p');
    page.click(inRoot('.surface'), { clientX: 640, clientY: 720 });
    assert.equal(inRoot('.note-editor').classList.contains('note-editor--above'), true);
    page.typeInto(inRoot('.note-field'), 'Footer overlaps');
    page.click(inRoot('.note-editor__save'));

    assert.deepEqual([pinNamesOf(root), inRoot('.note-editor').hidden, inRoot('.pin').classList.contains('pin--selected')], [['Pin 1: Footer overlaps'], true, false]);
});

test('Delete or Backspace on a focused pin removes it, and the same keys typed in the page\'s own field remove nothing', async () => {
    const { page, root, inRoot } = await dockedPage();
    const pageField = page.element('input', { type: 'text' });
    page.keydown(page.document.body, 'p');
    dropPinWithNote(page, inRoot, [320, 200], 'Price is wrong');
    dropPinWithNote(page, inRoot, [640, 400], 'Logo is cut off');

    pageField.focus();
    page.keydown(pageField, 'Delete');
    page.keydown(pageField, 'Backspace');
    const [first, second] = root.querySelectorAll('.pin');
    page.keydown(second, 'Delete', { isTrusted: false });
    assert.deepEqual(pinNamesOf(root), ['Pin 1: Price is wrong', 'Pin 2: Logo is cut off']);

    second.focus();
    page.keydown(second, 'Delete');
    assert.deepEqual(pinNamesOf(root), ['Pin 1: Price is wrong']);
    assert.equal(root.activeElement, first, 'Focus goes to the pin before it.');

    page.keydown(first, 'Backspace');
    assert.deepEqual(pinNamesOf(root), []);
    assert.equal(root.activeElement, inRoot('.surface'), 'While pinning, the last pin gone leaves focus on the surface.');
});

test('"Clear N pins" counts the pins on the page, removes them all with an open note, keeps pinning on, and hides at none', async () => {
    const { page, root, inRoot } = await dockedPage();
    const clear = inRoot('.dock__clear');
    assert.equal(clear.hidden, true, 'No pins, no Clear.');
    page.keydown(page.document.body, 'p');

    dropPinWithNote(page, inRoot, [320, 200], 'Price is wrong');
    assert.deepEqual([clear.hidden, clear.textContent], [false, 'Clear 1 pin']);
    dropPinWithNote(page, inRoot, [640, 400], 'Logo is cut off');
    dropPinWithNote(page, inRoot, [960, 600], 'Total is wrong');
    assert.equal(clear.textContent, 'Clear 3 pins');
    removePinByForm(page, root, inRoot, 1);
    assert.equal(clear.textContent, 'Clear 2 pins');
    page.click(inRoot('.surface'), { clientX: 100, clientY: 100 });
    assert.deepEqual([clear.textContent, inRoot('.note-editor').hidden], ['Clear 3 pins', false]);

    page.click(clear, { isTrusted: false });
    assert.equal(pinNamesOf(root).length, 3, 'A click the page makes clears nothing.');

    clear.focus();
    page.click(clear);

    assert.deepEqual([pinNamesOf(root), clear.hidden, inRoot('.note-editor').hidden], [[], true, true]);
    assert.equal(inRoot('.surface').hidden, false, 'Pinning stays on.');
    assert.equal(root.activeElement, inRoot('.surface'));
    assert.deepEqual(await capturedPinsOf(page), []);
});

test('pins dropped before the dock opened are counted on its Clear button', async () => {
    const page = fakePage({ siteHost: SHOP_HOST, viewport: VIEWPORT, storage: fakeBrowser().storage.local, sendMessage: async () => ({ state: STATE }) });
    page.inject(...OVERLAY_FILES);
    page.evaluate('globalThis.pinroundOverlay.startPinning()');
    const root = page.closedRootOf(page.document.querySelector('pinround-overlay'));
    const inRoot = (selector) => root.querySelector(selector);
    inRoot('.surface').box = { left: 0, top: 0, width: VIEWPORT.width, height: VIEWPORT.height };
    dropPinWithNote(page, inRoot, [320, 200], 'Price is wrong');
    dropPinWithNote(page, inRoot, [640, 400], 'Logo is cut off');

    await page.evaluate('globalThis.pinroundOverlay.openDock()');

    assert.deepEqual([inRoot('.dock__clear').hidden, inRoot('.dock__clear').textContent], [false, 'Clear 2 pins']);
});

test('with pinning off, the last pin removed by Delete or by Remove leaves focus on the Pin button, never on the hidden surface', async () => {
    const { page, root, inRoot, buttonNamed } = await dockedPage();
    page.keydown(page.document.body, 'p');
    dropPinWithNote(page, inRoot, [320, 200], 'Price is wrong');
    dropPinWithNote(page, inRoot, [640, 400], 'Logo is cut off');
    page.keydown(inRoot('.surface'), 'p');
    assert.equal(inRoot('.surface').hidden, true);

    const [first, second] = root.querySelectorAll('.pin');
    second.focus();
    page.keydown(second, 'Delete');
    assert.equal(root.activeElement, first);

    // A click on a button focuses it in Chrome.
    page.click(root.querySelector('.pin'));
    inRoot('.note-editor__remove').focus();
    page.click(inRoot('.note-editor__remove'));

    assert.deepEqual(pinNamesOf(root), []);
    assert.equal(root.activeElement, buttonNamed(TEXTS.pin));
    assert.equal(inRoot('.dock__clear').hidden, true);

    page.keydown(page.document.body, 'p');
    dropPinWithNote(page, inRoot, [320, 200], 'Price is wrong');
    page.keydown(inRoot('.surface'), 'p');
    inRoot('.dock__clear').focus();
    page.click(inRoot('.dock__clear'));
    assert.equal(root.activeElement, buttonNamed(TEXTS.pin), 'Clear while not pinning leaves focus on Pin too.');
});

test('the focus trap lets Tab and Shift+Tab move on from Clear as from any dock button in the middle', async () => {
    const { page, inRoot } = await dockedPage();
    page.keydown(page.document.body, 'p');
    dropPinWithNote(page, inRoot, [320, 200], 'Price is wrong');
    const clear = inRoot('.dock__clear');
    clear.focus();

    assert.equal(page.keydown(clear, 'Tab', { shiftKey: true }).defaultPrevented, false, 'Shift+Tab goes back to Pin, not round to the end.');
    assert.equal(page.keydown(clear, 'Tab').defaultPrevented, false);
});

const PINROUND = 'https://pinround.acme-foods.example';
const DRAFT_PAGE = `${PINROUND}/report-drafts/rd000000-0000-4000-8000-000000000001`;
const CHOICES_WITH_PAGES = [
    { value: 'new-report', label: 'New report', url: null },
    { value: 'report:rd1', label: 'Report draft · Total is wrong', url: DRAFT_PAGE },
    { value: 'ticket:SHOP-12', label: 'My draft round · SHOP-12', url: `${PINROUND}/t/SHOP-12` },
    { value: 'new-notebook', label: 'New notebook…', url: null },
];
const openLinkOf = (inRoot) => ({ isShown: !inRoot('.dock__open').hidden, href: inRoot('.dock__open').getAttribute('href') });

test('Open links the chosen place\'s page in pinround in a new tab, without the tested page as referrer, and hides for a new report or an address that is no web page', async () => {
    const states = [];
    const { page, inRoot } = await dockedPage({ answer: async () => ({ state: states.shift() }) });
    const show = async (state) => {
        states.push(state);
        page.dispatch(inRoot('select'), 'change', { bubbles: false, composed: false });
        await settled();
    };

    await show({ ...STATE, choices: CHOICES_WITH_PAGES, chosen: 'report:rd1' });
    assert.deepEqual(openLinkOf(inRoot), { isShown: true, href: DRAFT_PAGE });
    assert.deepEqual([inRoot('.dock__open').target, inRoot('.dock__open').rel, inRoot('.dock__open').getAttribute('aria-label')], ['_blank', 'noopener noreferrer', TEXTS.openTargetName]);

    await show({ ...STATE, choices: CHOICES_WITH_PAGES, chosen: 'ticket:SHOP-12' });
    assert.deepEqual(openLinkOf(inRoot), { isShown: true, href: `${PINROUND}/t/SHOP-12` });

    await show({ ...STATE, choices: CHOICES_WITH_PAGES, chosen: 'new-report' });
    assert.deepEqual(openLinkOf(inRoot), { isShown: false, href: null });

    await show({ ...STATE, choices: [{ value: 'ticket:SHOP-12', label: 'My draft round · SHOP-12', url: 'javascript:alert(1)' }], chosen: 'ticket:SHOP-12' });
    assert.deepEqual(openLinkOf(inRoot), { isShown: false, href: null });
});

test('a page address is linked only when it is an http or https URL', () => {
    assert.equal(checkedPageUrl(DRAFT_PAGE), DRAFT_PAGE);
    for (const url of ['javascript:alert(1)', 'data:text/html,x', 'not a url', 42, null, undefined]) {
        assert.equal(checkedPageUrl(url), null, String(url));
    }
});

test('a saved draft offers "Open the draft in pinround" under the message until the next message; a refused save offers nothing', async () => {
    const { page, inRoot, buttonNamed, saves } = await dockWithCapturesWaiting();
    page.click(buttonNamed(TEXTS.saveDraft));
    page.typeInto(inRoot('.dock-save__title'), 'Total is wrong');
    page.click(inRoot('.dock-save__send'));
    await settled();
    assert.equal(inRoot('.dock__saved').hidden, true);

    saves[0]({ message: 'Draft "Total is wrong" saved with 2 captures.', savedUrl: DRAFT_PAGE, state: { ...STATE, choices: CHOICES_WITH_PAGES, chosen: 'new-report', count: 0, canSave: false, newReport: { ...WAITING, shots: [] } } });
    await settled();
    await settled();

    const link = inRoot('.dock__saved').querySelector('a');
    assert.deepEqual([inRoot('.dock__saved').hidden, link.getAttribute('href'), link.textContent, link.target, link.rel], [false, DRAFT_PAGE, TEXTS.openSaved, '_blank', 'noopener noreferrer']);

    page.keydown(page.document.body, 'c');
    await settled();
    saves[1]({ message: 'Captured. 1 waiting.', state: NEW_REPORT_STATE });
    await settled();
    await settled();
    assert.deepEqual([inRoot('.dock__status').textContent, inRoot('.dock__saved').hidden], ['Captured. 1 waiting.', true], 'The next message replaces it.');
});

test('a save answer whose page address is no web page offers no link', async () => {
    const { page, inRoot, buttonNamed, saves } = await dockWithCapturesWaiting();
    page.click(buttonNamed(TEXTS.saveDraft));
    page.typeInto(inRoot('.dock-save__title'), 'Total is wrong');
    page.click(inRoot('.dock-save__send'));
    await settled();

    saves[0]({ message: 'Draft saved.', savedUrl: 'javascript:alert(1)', state: { ...STATE, chosen: 'new-report', count: 0, canSave: false, newReport: { ...WAITING, shots: [] } } });
    await settled();
    await settled();

    assert.equal(inRoot('.dock__saved').hidden, true);
});

test('the title field names the new report draft; once the draft is made, its name shows as text only and nothing asks for it', async () => {
    const naming = await dockWithCapturesWaiting();
    naming.page.click(naming.buttonNamed(TEXTS.saveDraft));
    assert.equal(naming.inRoot('.dock-save__title').parentNode.children[0].textContent, 'Name of the report draft: what is wrong, in one line');
    assert.equal(naming.inRoot('.dock-save__draft').hidden, true);

    const made = await dockWithCapturesWaiting({ ...WAITING, needsTitle: false, draftTitle: 'Total <b>is</b> wrong' });
    made.page.click(made.buttonNamed(TEXTS.saveDraft));
    assert.deepEqual([panelOf(made.inRoot).hasTitle, made.inRoot('.dock-save__draft').hidden, made.inRoot('.dock-save__draft').textContent], [false, false, 'Adds to the report draft "Total <b>is</b> wrong".']);
    assert.equal(made.inRoot('.dock-save__draft').children.length, 0, 'The title made no element.');
    assert.equal(made.inRoot('.dock-save__goes').textContent, TEXTS.goesToMade);
    made.page.click(made.inRoot('.dock-save__send'));
    await settled();
    assert.deepEqual(made.page.sentMessages.at(-1), { pinroundDock: 'save-draft', value: { reportTitle: '', project: '' } });
});

test('a waiting report whose draft name is no text is no state', () => {
    assert.equal(checkedState({ ...NEW_REPORT_STATE, newReport: { ...WAITING, draftTitle: 42 } }), null);
    assert.equal(checkedState({ ...NEW_REPORT_STATE, newReport: { ...WAITING, draftTitle: 'Total' } }).newReport.draftTitle, 'Total');
    assert.equal(checkedState(NEW_REPORT_STATE).newReport.draftTitle, null);
});

/** The dock on SHOP-12 with "New notebook…" offered; make-notebook answers wait until released. */
async function dockOfferingNewNotebook() {
    const makes = [];
    const docked = await dockedPage({
        answer: async ({ pinroundDock }) => ('make-notebook' === pinroundDock ? new Promise((resolve) => makes.push(resolve)) : { state: { ...STATE, choices: CHOICES_WITH_PAGES } }),
    });
    const pickNewNotebook = () => {
        const picker = docked.inRoot('select');
        picker.value = 'new-notebook';
        docked.page.dispatch(picker, 'change', { bubbles: false, composed: false });
    };

    return { ...docked, makes, pickNewNotebook };
}

test('"New notebook…" in Save to opens the name field in the dock, keeps the place chosen before and asks the extension nothing yet', async () => {
    const { page, root, inRoot, pickNewNotebook } = await dockOfferingNewNotebook();

    pickNewNotebook();
    await settled();

    assert.deepEqual([inRoot('.dock-notebook').hidden, inRoot('.dock-notebook').getAttribute('role'), root.activeElement], [false, 'dialog', inRoot('.dock-notebook__title')]);
    assert.equal(inRoot('select').value, 'ticket:SHOP-12');
    assert.deepEqual(requestsOf(page), ['state']);
});

test('the notebook name is refused inline when blank or over 120 characters, and sent as typed otherwise', async () => {
    const { page, root, inRoot, pickNewNotebook } = await dockOfferingNewNotebook();
    pickNewNotebook();
    const field = inRoot('.dock-notebook__title');

    for (const typed of ['', '  ', '\u{1F600}'.repeat(121)]) {
        field.value = typed;
        page.click(inRoot('.dock-notebook__make'));
        assert.deepEqual([inRoot('.dock-notebook').querySelector('.dock-save__error').textContent, field.getAttribute('aria-invalid'), root.activeElement], [TEXTS.notebookNameRefused, 'true', field], JSON.stringify(typed.slice(0, 4)));
    }
    assert.deepEqual(requestsOf(page), ['state']);
    assert.equal(refusalOfNotebookName('\u{1F600}'.repeat(120)), null);

    field.value = '';
    page.typeInto(field, 'Coupons');
    page.keydown(field, 'Enter');
    await settled();
    assert.deepEqual(page.sentMessages.at(-1), { pinroundDock: 'make-notebook', value: 'Coupons' });
});

test('a made notebook closes the panel, empties the name and says so; a refused one keeps the panel with pinround\'s reason', async () => {
    const { page, inRoot, makes, pickNewNotebook } = await dockOfferingNewNotebook();
    pickNewNotebook();
    page.typeInto(inRoot('.dock-notebook__title'), 'Coupons');
    page.click(inRoot('.dock-notebook__make'));
    await settled();

    makes[0]({ message: 'Your pinround token cannot write to notebooks.', isError: true, state: { ...STATE, choices: CHOICES_WITH_PAGES } });
    await settled();
    await settled();
    assert.deepEqual([inRoot('.dock-notebook').hidden, inRoot('.dock-notebook').querySelector('.dock-save__error').textContent, inRoot('.dock-notebook__title').value], [false, 'Your pinround token cannot write to notebooks.', 'Coupons']);

    page.click(inRoot('.dock-notebook__make'));
    await settled();
    const made = [...CHOICES_WITH_PAGES.slice(0, 3), { value: 'notebook:nb9', label: 'Notebook · Coupons', url: `${PINROUND}/notebooks/nb9` }, CHOICES_WITH_PAGES[3]];
    makes[1]({ message: 'Notebook "Coupons" made. Captures go into it now.', state: { ...STATE, choices: made, chosen: 'notebook:nb9' } });
    await settled();
    await settled();

    assert.deepEqual([inRoot('.dock-notebook').hidden, inRoot('.dock-notebook__title').value, inRoot('.dock__status').textContent], [true, '', 'Notebook "Coupons" made. Captures go into it now.']);
    assert.equal(inRoot('select').value, 'notebook:nb9');
    assert.deepEqual(openLinkOf(inRoot), { isShown: true, href: `${PINROUND}/notebooks/nb9` });
});

test('Cancel and Escape close the New notebook panel and give focus back to Save to', async () => {
    const { page, root, inRoot, pickNewNotebook } = await dockOfferingNewNotebook();
    pickNewNotebook();
    page.click(inRoot('.dock-notebook__cancel'));
    assert.deepEqual([inRoot('.dock-notebook').hidden, root.activeElement], [true, inRoot('select')]);

    pickNewNotebook();
    page.keydown(inRoot('.dock-notebook__title'), 'Escape');
    assert.deepEqual([inRoot('.dock-notebook').hidden, root.activeElement], [true, inRoot('select')]);
});
