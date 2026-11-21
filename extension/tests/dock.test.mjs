import assert from 'node:assert/strict';
import { test } from 'node:test';
import '../src/overlay/dock.js';
import { OVERLAY_FILES } from '../src/capture/page-pins.js';
import { fakeBrowser } from './fake-browser.mjs';
import { fakePage } from './fake-page.mjs';

const { DEFAULT_PLACE, EDGE_GAP_PX, LETTER_KEYS_KEY, TEXTS, actionOfKey, checkedPlace, isTypingTarget, loadPlace, nearerSide, placeKeyOf, savePlace, topPercentOf } = globalThis.pinroundDock;

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
    const buttonNamed = (text) => root.querySelectorAll('button').find((button) => button.textContent.startsWith(text));

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
    assert.equal(inRoot('.dock').getAttribute('role'), 'toolbar');
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
    const noteField = inRoot('textarea');
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
        ['', 'Choose where to put it'],
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

test('Save draft is pressed only for a draft it can save, and a second press while one runs is ignored', async () => {
    let release;
    const answers = [{ state: { ...STATE, chosen: 'new-report', count: 2, canSave: true } }];
    const { page, buttonNamed } = await dockedPage({
        answer: async () => answers.shift() ?? new Promise((resolve) => {
            release = () => resolve({ message: 'Report draft opened in pinround. Check it there and press Send.', state: { ...STATE, chosen: 'new-report', count: 0, canSave: false } });
        }),
    });
    const save = buttonNamed(TEXTS.saveDraft);
    assert.equal(save.getAttribute('aria-disabled'), 'false');
    assert.equal(save.getAttribute('aria-label'), 'Save draft, 2 in it');

    page.click(save);
    page.keydown(page.document.body, 'c');
    await settled();
    assert.deepEqual(requestsOf(page), ['state', 'save-draft']);
    assert.equal(save.getAttribute('aria-disabled'), 'true');

    release();
    await settled();
    await settled();
    assert.deepEqual([save.getAttribute('aria-disabled'), buttonNamed(TEXTS.capture).getAttribute('aria-disabled')], ['true', 'false']);
    page.click(save);
    await settled();
    assert.deepEqual(requestsOf(page), ['state', 'save-draft'], 'A Save draft with nothing to save asks nothing.');
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
    page.keydown(inRoot('textarea'), 'Escape');
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

/** A person's click on the pinning surface at `point` (CSS px), the note typed in the editor it opens, then Escape. */
function dropPinWithNote(page, inRoot, [clientX, clientY], note) {
    page.click(inRoot('.surface'), { clientX, clientY });
    page.typeInto(inRoot('textarea'), note);
    page.keydown(inRoot('textarea'), 'Escape');
}

const pinNamesOf = (root) => root.querySelectorAll('.pin').map((pin) => pin.getAttribute('aria-label'));
const removeNamesOf = (root) => root.querySelectorAll('.pin-remove').map((remove) => remove.getAttribute('aria-label'));
/** The pins the overlay hands a capture, as plain data from the page's realm. */
const capturedPinsOf = async (page) => JSON.parse(JSON.stringify(await page.evaluate('globalThis.pinroundOverlay.hideForCapture()')));

test('each pin has a Remove beside it that removes that pin only, the rest renumber, and a click the page makes removes nothing', async () => {
    const { page, root, inRoot } = await dockedPage();
    page.keydown(page.document.body, 'p');
    dropPinWithNote(page, inRoot, [320, 200], 'Price is wrong');
    dropPinWithNote(page, inRoot, [640, 400], 'Logo is cut off');
    dropPinWithNote(page, inRoot, [960, 600], 'Total is wrong');
    assert.deepEqual(removeNamesOf(root), ['Remove pin 1', 'Remove pin 2', 'Remove pin 3']);
    // Remove sits on the side facing the middle, so a pin at the page's left edge keeps it on screen; the middle goes right.
    assert.deepEqual(root.querySelectorAll('.pin-remove').map((remove) => [remove.textContent, remove.tabIndex, remove.style.left, remove.style.top, remove.classList.contains('pin-remove--right')]), [
        ['×', -1, '25%', '25%', true],
        ['×', -1, '50%', '50%', true],
        ['×', -1, '75%', '75%', false],
    ]);

    page.click(root.querySelectorAll('.pin-remove')[1], { isTrusted: false });
    assert.deepEqual(pinNamesOf(root), ['Pin 1: Price is wrong', 'Pin 2: Logo is cut off', 'Pin 3: Total is wrong']);

    page.click(root.querySelectorAll('.pin-remove')[1]);

    assert.deepEqual(pinNamesOf(root), ['Pin 1: Price is wrong', 'Pin 2: Total is wrong']);
    assert.deepEqual(removeNamesOf(root), ['Remove pin 1', 'Remove pin 2']);
    assert.deepEqual(await capturedPinsOf(page), [{ x_pct: 25, y_pct: 25, note: 'Price is wrong' }, { x_pct: 75, y_pct: 75, note: 'Total is wrong' }]);
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
    page.click(root.querySelectorAll('.pin-remove')[0]);
    assert.equal(clear.textContent, 'Clear 2 pins');
    page.click(inRoot('.surface'), { clientX: 100, clientY: 100 });
    assert.deepEqual([clear.textContent, inRoot('.note-editor').hidden], ['Clear 3 pins', false]);

    page.click(clear, { isTrusted: false });
    assert.equal(pinNamesOf(root).length, 3, 'A click the page makes clears nothing.');

    clear.focus();
    page.click(clear);

    assert.deepEqual([pinNamesOf(root), removeNamesOf(root), clear.hidden, inRoot('.note-editor').hidden], [[], [], true, true]);
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
    root.querySelector('.pin-remove').focus();
    page.click(root.querySelector('.pin-remove'));

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
