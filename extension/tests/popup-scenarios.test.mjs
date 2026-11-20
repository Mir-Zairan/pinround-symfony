import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, test } from 'node:test';
import { NOTEBOOK_PLAYER_TEXTS } from '../src/player/notebook-player.js';
import { ScenarioPanel } from '../src/popup/scenario-panel.js';
import { focusFirstVisible } from '../src/popup/visible-focus.js';
import { ALLOWED_HOSTS_KEY, isConfirmedSite, siteOf } from '../src/shared/allowed-hosts.js';
import { fakeBrowser } from './fake-browser.mjs';
import { PINROUND_ORIGIN } from './fake-pinround.mjs';

const POPUP_HTML = readFileSync(new URL('../popup.html', import.meta.url), 'utf8');

// A DOM as the extension's pages use it, built from the page's own HTML: an element hidden by itself or by an ancestor
// cannot be seen; only an enabled, seen control takes focus; a focused element that leaves the page or is hidden drops
// focus to the body; a submit button's click submits its form; textContent replaces the children; a listener added
// with a signal goes when it aborts. tests/options-allowed-hosts.test.mjs holds the same copy.
const FOCUSABLE_TAGS = new Set(['button', 'input', 'select', 'textarea']);
const VOID_TAGS = new Set(['input', 'meta', 'link', 'br', 'img']);

class FakeElement {
    #document;
    #text = '';
    #attributes = new Map();
    #listeners = [];
    #classes = new Set();

    constructor(document, tagName) {
        this.#document = document;
        this.tagName = tagName.toUpperCase();
        this.children = [];
        this.parent = null;
        this.hidden = false;
        this.disabled = false;
        this.value = '';
        this.type = 'button' === tagName ? 'submit' : '';
        this.classList = {
            toggle: (name, force) => (force ? this.#classes.add(name) : this.#classes.delete(name)),
            contains: (name) => this.#classes.has(name),
        };
    }

    get id() {
        return this.#attributes.get('id') ?? '';
    }

    set className(names) {
        this.#classes = new Set(names.split(' ').filter((name) => '' !== name));
    }

    get textContent() {
        return this.#text + this.children.map((child) => child.textContent).join('');
    }

    set textContent(text) {
        this.replaceChildren();
        this.#text = String(text);
    }

    appendText(text) {
        this.#text += text;
    }

    setAttribute(name, value) {
        this.#attributes.set(name, String(value));
    }

    getAttribute(name) {
        return this.#attributes.get(name) ?? null;
    }

    removeAttribute(name) {
        this.#attributes.delete(name);
    }

    append(...nodes) {
        for (const node of nodes) {
            node.parent?.children.splice(node.parent.children.indexOf(node), 1);
            node.parent = this;
            this.children.push(node);
        }
    }

    replaceChildren(...nodes) {
        for (const child of this.children) {
            child.parent = null;
        }
        this.children = [];
        this.append(...nodes);
    }

    contains(node) {
        for (let ancestor = node; null !== ancestor && undefined !== ancestor; ancestor = ancestor.parent) {
            if (ancestor === this) {
                return true;
            }
        }

        return false;
    }

    checkVisibility() {
        return this.#document.body.contains(this) && [...this.#selfAndAncestors()].every((element) => !element.hidden);
    }

    focus() {
        if (FOCUSABLE_TAGS.has(this.tagName.toLowerCase()) && !this.disabled && this.checkVisibility()) {
            this.#document.focused = this;
        }
    }

    addEventListener(type, listener, options = {}) {
        const entry = { type, listener };
        this.#listeners.push(entry);
        options.signal?.addEventListener('abort', () => this.#listeners.splice(this.#listeners.indexOf(entry), 1));
    }

    dispatch(type) {
        const event = { type, target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
        for (const { listener } of this.#listeners.filter((entry) => entry.type === type)) {
            listener(event);
        }

        return event;
    }

    click() {
        if (this.disabled) {
            return;
        }
        this.dispatch('click');
        if ('BUTTON' === this.tagName && 'submit' === this.type) {
            [...this.#selfAndAncestors()].find((element) => 'FORM' === element.tagName)?.dispatch('submit');
        }
    }

    * #selfAndAncestors() {
        for (let element = this; null !== element; element = element.parent) {
            yield element;
        }
    }
}

class FakeDocument {
    focused = null;

    constructor() {
        this.body = new FakeElement(this, 'body');
    }

    get activeElement() {
        return null !== this.focused && this.focused.checkVisibility() ? this.focused : this.body;
    }

    createElement(tagName) {
        return new FakeElement(this, tagName);
    }

    getElementById(id) {
        const found = (element) => (id === element.id ? element : element.children.map(found).find((match) => null !== match) ?? null);

        return found(this.body);
    }
}

/** The page's <body> as a FakeDocument; the extension's own pages are well formed, so tags and text are enough. */
function documentOf(html) {
    const document = new FakeDocument();
    const open = [document.body];
    const body = /<body>([\s\S]*)<\/body>/u.exec(html)[1];
    for (const [, closing, tagName, attributeText, text] of body.matchAll(/<(\/?)([a-z0-9]+)([^>]*)>|([^<]+)/gu)) {
        const parent = open.at(-1);
        if (undefined !== text) {
            parent.appendText(text.trim());
        } else if ('/' === closing) {
            open.pop();
        } else {
            const element = document.createElement(tagName);
            for (const [, name, value = ''] of attributeText.matchAll(/([a-z-]+)(?:="([^"]*)")?/gu)) {
                if ('hidden' === name || 'disabled' === name) {
                    element[name] = true;
                } else if ('type' === name || 'value' === name) {
                    element[name] = value;
                } else if ('class' === name) {
                    element.className = value;
                }
                element.setAttribute(name, value);
            }
            parent.append(element);
            if (!VOID_TAGS.has(tagName)) {
                open.push(element);
            }
        }
    }

    return document;
}

// Lets every pending promise of the fakes settle; no clock is read.
const settle = () => new Promise((resolve) => {
    setImmediate(resolve);
});

const START_URL = 'http://shop.acme-foods.test/basket';
const SHOP_TAB = { id: 7, url: START_URL, title: 'Acme Foods shop', active: true };
const CHECKOUT = { kind: 'notebook', notebookId: 'nb000000-0000-4000-8000-000000000001', title: 'Checkout' };
const COUPONS = { kind: 'notebook', notebookId: 'nb000000-0000-4000-8000-000000000002', title: 'Coupons' };
const SHOP_7 = { kind: 'ticket', ticketKey: 'SHOP-7' };

const wrapped = (text) => `<untrusted_text>${text.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')}</untrusted_text>`;
const scenario = (title) => ({
    title: wrapped(title),
    why: wrapped('BasketTotal adds the coupon after tax.'),
    setup: wrapped(''),
    start_url: wrapped(START_URL),
    steps: [{ type: 'click', say: wrapped(`Open ${title}`), target: { role: wrapped('button'), name: wrapped(title) } }],
});

/** GET /api/v1/notebooks/{id} as pinround answers it for the person's notebooks, each text in its markers. */
function notebookApi(scenariosByNotebookId) {
    return {
        async call(method, path) {
            const notebookId = /^\/api\/v1\/notebooks\/([^/]+)$/u.exec(path)?.[1];
            if ('GET' !== method || !Object.hasOwn(scenariosByNotebookId, notebookId)) {
                throw new Error(`The test double does not answer ${method} ${path}.`);
            }

            return { notebook_id: notebookId, scenarios: scenariosByNotebookId[notebookId] };
        },
    };
}

// The popup window the panel listens on for pagehide; no test here closes it.
beforeEach(() => {
    globalThis.addEventListener = () => undefined;
});

afterEach(() => {
    delete globalThis.addEventListener;
    delete globalThis.document;
});

async function popupWith({ allowedHosts = ['http://shop.acme-foods.test'] } = {}) {
    const document = documentOf(POPUP_HTML);
    globalThis.document = document;
    const browser = fakeBrowser({ tabs: [SHOP_TAB] });
    browser.clickIcon(SHOP_TAB.id);
    await browser.storage.local.set({ [ALLOWED_HOSTS_KEY]: allowedHosts });
    const element = (id) => document.getElementById(id);
    // The popup shows the form once it is paired, before it shows any scenario.
    element('capture-form').hidden = false;
    const statuses = [];
    const panel = new ScenarioPanel(element, (message, isError = false) => statuses.push({ message, isError }), {
        api: notebookApi({
            [CHECKOUT.notebookId]: [scenario('Apply a coupon'), scenario('Remove a coupon'), scenario('Pay')],
            [COUPONS.notebookId]: [scenario('Expired coupon')],
        }),
        browser,
        storage: browser.storage.local,
        pinroundOrigin: PINROUND_ORIGIN,
        activeTabId: async () => SHOP_TAB.id,
        captureToNotebook: async () => undefined,
    }, ['capture', 'target']);
    const focusedId = () => document.activeElement.id;

    return { document, element, panel, statuses, focusedId, browser };
}

test('focusFirstVisible focuses the first candidate that can be seen, skipping hidden and missing ones', () => {
    const document = documentOf(POPUP_HTML);
    const element = (id) => document.getElementById(id);
    element('capture-form').hidden = false;
    element('report-draft').hidden = true;

    const focused = focusFirstVisible([element('start-over'), null, element('capture'), element('target')]);

    assert.equal(focused, element('capture'));
    assert.equal(document.activeElement.id, 'capture');
});

test('focusFirstVisible keeps a pressed control that is still seen, and focuses nothing when none is', () => {
    const document = documentOf(POPUP_HTML);
    const element = (id) => document.getElementById(id);
    element('capture-form').hidden = false;
    element('pin-page').focus();

    assert.equal(focusFirstVisible([element('pin-page'), element('capture')]), element('pin-page'));
    assert.equal(document.activeElement.id, 'pin-page');

    element('capture-form').hidden = true;
    assert.equal(focusFirstVisible([element('pin-page'), element('capture'), element('target')]), null);
    assert.equal(document.activeElement, document.body);
});

test('a notebook shows its scenarios; a ticket and no choice hide them', async () => {
    const { element, panel } = await popupWith();

    await panel.showFor(SHOP_7);
    assert.equal(element('scenarios').checkVisibility(), false);

    await panel.showFor(CHECKOUT);
    assert.equal(element('scenarios').checkVisibility(), true);
    assert.equal(element('scenario-player').checkVisibility(), true);
    const titles = element('scenario-list').children.map((item) => item.textContent);
    assert.deepEqual(titles, ['Apply a coupon', 'Remove a coupon', 'Pay']);
    assert.equal(element('scenario-position').textContent, 'Scenario 1 of 3');

    await panel.showFor(undefined);
    assert.equal(element('scenarios').checkVisibility(), false);
});

test('hiding the scenarios under the keyboard moves focus to "Capture this page", or the picker when that is hidden', async () => {
    const { element, panel, focusedId } = await popupWith();
    await panel.showFor(CHECKOUT);
    element('player-next').focus();
    assert.equal(focusedId(), 'player-next');

    await panel.showFor(SHOP_7);
    assert.equal(focusedId(), 'capture');

    await panel.showFor(CHECKOUT);
    element('player-back').focus();
    element('capture').hidden = true;
    await panel.showFor(undefined);
    assert.equal(focusedId(), 'target');
});

test('hiding the scenarios leaves focus alone when it is outside them', async () => {
    const { element, panel, focusedId } = await popupWith();
    await panel.showFor(CHECKOUT);
    element('target').focus();

    await panel.showFor(SHOP_7);

    assert.equal(focusedId(), 'target');
});

test('another notebook chosen while the host question waits closes it as Cancel, and the new player shows no question', async () => {
    const { element, panel, statuses, browser } = await popupWith();
    await panel.showFor(CHECKOUT);
    element('player-play').click();
    await settle();
    assert.equal(element('host-question').checkVisibility(), true);
    assert.equal(element('host-question-text').textContent, NOTEBOOK_PLAYER_TEXTS.hostQuestion('shop.acme-foods.test'));

    await panel.showFor(COUPONS);
    await settle();

    assert.equal(element('scenario-player').checkVisibility(), true);
    assert.equal(element('scenario-title').textContent, 'Expired coupon');
    assert.equal(element('host-question').hidden, true);
    assert.equal(element('player-play').disabled, false);
    assert.equal(await isConfirmedSite(browser.storage.local, siteOf(START_URL)), false, 'The question was answered as Cancel.');
    assert.deepEqual(browser.injectedFiles, []);
    assert.equal(element('player-say').textContent, '');
    assert.deepEqual(statuses, [{ message: '', isError: false }], 'The old run, cleared on Play, shows no line of its own.');
});

test('Play anyway hides the question, keeps focus on Stop, and the run goes on', async () => {
    const { element, panel, browser, focusedId } = await popupWith();
    await panel.showFor(CHECKOUT);
    element('player-play').focus();
    element('player-play').click();
    await settle();
    assert.equal(focusedId(), 'host-question-cancel');

    element('host-question-play').click();

    assert.equal(element('host-question').hidden, true);
    assert.equal(focusedId(), 'player-stop-run');
    assert.equal(element('player-stop-run').checkVisibility(), true);
    await settle();
    assert.equal(await isConfirmedSite(browser.storage.local, siteOf(START_URL)), true);
    assert.equal(element('player-say').textContent, 'Open Apply a coupon');
    assert.notDeepEqual(browser.injectedFiles.filter((injection) => SHOP_TAB.id === injection.tabId), []);
});

test('Cancel hides the question, runs no step, and gives back Play with focus on a control that is seen', async () => {
    const { element, panel, statuses, browser, focusedId } = await popupWith();
    await panel.showFor(CHECKOUT);
    element('player-play').focus();
    element('player-play').click();
    await settle();

    element('host-question-cancel').click();
    await settle();

    assert.equal(element('host-question').hidden, true);
    assert.equal(element('player-play').disabled, false);
    assert.equal(focusedId(), 'player-play');
    assert.equal(element('player-play').checkVisibility(), true);
    assert.equal(await isConfirmedSite(browser.storage.local, siteOf(START_URL)), false);
    assert.deepEqual(browser.injectedFiles, []);
    assert.equal(element('player-say').textContent, '');
    assert.deepEqual(statuses.at(-1), { message: NOTEBOOK_PLAYER_TEXTS.notPlayed, isError: true });
});
