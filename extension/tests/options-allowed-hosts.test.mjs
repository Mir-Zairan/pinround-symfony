import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { ALLOWED_HOST_TEXTS, AllowedHostList } from '../src/options/allowed-host-list.js';
import { ALLOWED_HOSTS_KEY, HOST_REFUSALS, MAX_ALLOWED_HOSTS } from '../src/shared/allowed-hosts.js';
import { fakeBrowser } from './fake-browser.mjs';

const OPTIONS_HTML = readFileSync(new URL('../options.html', import.meta.url), 'utf8');

// A DOM as the extension's pages use it, built from the page's own HTML: an element hidden by itself or by an ancestor
// cannot be seen; only an enabled, seen control takes focus; a focused element that leaves the page or is hidden drops
// focus to the body; a submit button's click submits its form; textContent replaces the children; a listener added
// with a signal goes when it aborts. tests/popup-scenarios.test.mjs holds the same copy.
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

// Lets every pending promise of the fake storage settle; no clock is read.
const settle = () => new Promise((resolve) => {
    setImmediate(resolve);
});

async function optionsPageWith(storedEntries) {
    const storage = fakeBrowser().storage.local;
    if (undefined !== storedEntries) {
        await storage.set({ [ALLOWED_HOSTS_KEY]: storedEntries });
    }
    const saving = { refusal: null };
    // chrome.storage rejects a write it cannot make, such as one past its quota, and leaves the stored value as it was.
    const savingStorage = {
        get: (keys) => storage.get(keys),
        set: (items) => (null === saving.refusal ? storage.set(items) : Promise.reject(saving.refusal)),
    };
    const breakSaving = () => {
        saving.refusal = new Error('QUOTA_BYTES quota exceeded');
    };
    const document = documentOf(OPTIONS_HTML);
    const list = new AllowedHostList(document, savingStorage);
    await list.show();
    const element = (id) => document.getElementById(id);
    const shownEntries = () => element('allowed-host-list').children.map((item) => item.children[0].textContent);
    const removeButtonOf = (entry) => element('allowed-host-list').children.find((item) => entry === item.children[0].textContent).children[1];
    // The focused control by its accessible name or id, so a failure names it; the body has neither.
    const focusedName = () => document.activeElement.getAttribute('aria-label') ?? document.activeElement.id;
    const stored = async () => (await storage.get(ALLOWED_HOSTS_KEY))[ALLOWED_HOSTS_KEY];
    const add = async (typed) => {
        element('allowed-host-input').value = typed;
        element('add-allowed-host').click();
        await settle();
    };

    return { storage, element, shownEntries, removeButtonOf, focusedName, stored, add, breakSaving };
}

test('options.html holds the allowed host section with its input tied to a label and a polite status', () => {
    const document = documentOf(OPTIONS_HTML);
    const descendantsOf = (element) => element.children.flatMap((child) => [child, ...descendantsOf(child)]);
    const label = descendantsOf(document.body).find((element) => 'LABEL' === element.tagName && 'allowed-host-input' === element.getAttribute('for'));

    assert.equal(label?.textContent, 'Host to add');
    assert.equal(document.getElementById('allowed-host-status').getAttribute('aria-live'), 'polite');
    assert.equal(document.getElementById('allowed-host-input').getAttribute('aria-describedby'), 'allowed-host-status');
    assert.equal(document.getElementById('add-allowed-host').type, 'submit');
    assert.equal(document.getElementById('add-suggested-host').type, 'button');
});

test('no stored host shows the empty state and the suggestion, and no list', async () => {
    const { element } = await optionsPageWith(undefined);

    assert.equal(element('allowed-hosts-empty').checkVisibility(), true);
    assert.equal(element('allowed-hosts-empty').textContent, ALLOWED_HOST_TEXTS.empty);
    assert.doesNotMatch(ALLOWED_HOST_TEXTS.empty, /\bsites?\b/u, 'The page calls them hosts everywhere.');
    assert.equal(element('allowed-host-list').checkVisibility(), false);
    assert.equal(element('add-suggested-host').checkVisibility(), true);
    assert.equal(element('add-suggested-host').textContent, 'Add http://localhost:*');
    assert.equal(element('allowed-hosts-full').checkVisibility(), false);
});

test('a stored list that cannot be read shows the refusal, never the empty state', async () => {
    const storage = { get: async () => { throw new Error('IO error'); }, set: async () => undefined };
    const document = documentOf(OPTIONS_HTML);

    await new AllowedHostList(document, storage).show();

    assert.equal(document.getElementById('allowed-host-status').textContent, ALLOWED_HOST_TEXTS.notRead);
    assert.equal(document.getElementById('allowed-host-status').classList.contains('error'), true);
    assert.equal(document.getElementById('allowed-hosts-empty').checkVisibility(), false);
});

test('the stored hosts show in their order with a Remove named after each, an unreadable one left out', async () => {
    const { shownEntries, removeButtonOf, element } = await optionsPageWith(['https://shop.acme-foods.test', 'not a host', 'http://localhost:*', 'http://127.0.0.1:8000']);

    assert.deepEqual(shownEntries(), ['https://shop.acme-foods.test', 'http://localhost:*', 'http://127.0.0.1:8000']);
    const remove = removeButtonOf('http://localhost:*');
    assert.equal(remove.textContent, 'Remove');
    assert.equal(remove.type, 'button');
    assert.equal(remove.getAttribute('aria-label'), 'Remove http://localhost:*');
    assert.equal(element('allowed-hosts-empty').checkVisibility(), false);
    assert.equal(element('add-suggested-host').checkVisibility(), false);
});

test('Add keeps the typed host as allowed-hosts.js writes it, clears the input and says so', async () => {
    const { add, stored, shownEntries, element } = await optionsPageWith(['https://shop.acme-foods.test']);

    await add('  HTTP://LocalHost:8000/\r\n');

    assert.deepEqual(await stored(), ['https://shop.acme-foods.test', 'http://localhost:8000']);
    assert.deepEqual(shownEntries(), ['https://shop.acme-foods.test', 'http://localhost:8000']);
    assert.equal(element('allowed-host-input').value, '');
    assert.equal(element('allowed-host-status').textContent, 'Added http://localhost:8000.');
    assert.equal(element('allowed-host-status').classList.contains('error'), false);
    assert.equal(element('allowed-host-input').getAttribute('aria-invalid'), null);
});

test('a refused host shows its plain refusal, never the typed text, keeps the input and stores nothing', async () => {
    const refusals = [
        ['ftp://shop.acme-foods.test', HOST_REFUSALS.scheme],
        ['http://shop\u001b[31m.test ', HOST_REFUSALS.shape],
        ['﻿http://bücher.acme-foods.test', HOST_REFUSALS.notAscii],
        ['https://shop.acme-foods.test/', HOST_REFUSALS.alreadyListed],
    ];
    assert.notEqual(refusals.length, 0);
    for (const [typed, refusal] of refusals) {
        const { add, stored, shownEntries, element } = await optionsPageWith(['https://shop.acme-foods.test']);

        await add(typed);

        assert.equal(element('allowed-host-status').textContent, refusal, typed);
        assert.equal(element('allowed-host-status').classList.contains('error'), true, typed);
        assert.equal(element('allowed-host-input').getAttribute('aria-invalid'), 'true', typed);
        assert.equal(element('allowed-host-input').value, typed, typed);
        assert.deepEqual(await stored(), ['https://shop.acme-foods.test'], typed);
        assert.deepEqual(shownEntries(), ['https://shop.acme-foods.test'], typed);
    }
});

test(`a list of ${MAX_ALLOWED_HOSTS} hosts says it is full, and Add is refused with nothing stored`, async () => {
    const full = Array.from({ length: MAX_ALLOWED_HOSTS }, (_, index) => `http://host-${MAX_ALLOWED_HOSTS - index}.acme-foods.test`);
    const { add, stored, element } = await optionsPageWith(full);

    assert.equal(element('allowed-hosts-full').checkVisibility(), true);
    assert.equal(element('allowed-hosts-full').textContent, HOST_REFUSALS.listFull);

    await add('http://localhost:8000');

    assert.equal(element('allowed-host-status').textContent, HOST_REFUSALS.listFull);
    assert.deepEqual(await stored(), full);
});

test('a list one short of full does not say it is full', async () => {
    const { element } = await optionsPageWith(Array.from({ length: MAX_ALLOWED_HOSTS - 1 }, (_, index) => `http://host-${index}.acme-foods.test`));

    assert.equal(element('allowed-hosts-full').checkVisibility(), false);
});

test('the suggestion adds http://localhost:* with one press, hides itself and leaves focus in the input', async () => {
    const { focusedName, stored, element } = await optionsPageWith(['https://shop.acme-foods.test']);
    element('add-suggested-host').focus();

    element('add-suggested-host').click();
    await settle();

    assert.deepEqual(await stored(), ['https://shop.acme-foods.test', 'http://localhost:*']);
    assert.equal(element('add-suggested-host').checkVisibility(), false);
    assert.equal(focusedName(), 'allowed-host-input');
    assert.equal(element('allowed-host-status').textContent, 'Added http://localhost:*.');
});

test('Remove takes the host out and moves focus to the next Remove, or to the input after the last one', async () => {
    const { focusedName, stored, shownEntries, removeButtonOf, element } = await optionsPageWith(['http://localhost:*', 'https://shop.acme-foods.test', 'http://127.0.0.1:8000']);
    removeButtonOf('https://shop.acme-foods.test').focus();

    removeButtonOf('https://shop.acme-foods.test').click();
    await settle();

    assert.deepEqual(await stored(), ['http://localhost:*', 'http://127.0.0.1:8000']);
    assert.deepEqual(shownEntries(), ['http://localhost:*', 'http://127.0.0.1:8000']);
    assert.equal(element('allowed-host-status').textContent, 'Removed https://shop.acme-foods.test.');
    assert.equal(focusedName(), 'Remove http://127.0.0.1:8000');

    removeButtonOf('http://127.0.0.1:8000').click();
    await settle();

    assert.deepEqual(await stored(), ['http://localhost:*']);
    assert.equal(focusedName(), 'allowed-host-input');
});

test('removing the last host shows the empty state again and offers the suggestion again', async () => {
    const { removeButtonOf, element } = await optionsPageWith(['http://localhost:*']);

    removeButtonOf('http://localhost:*').click();
    await settle();

    assert.equal(element('allowed-hosts-empty').checkVisibility(), true);
    assert.equal(element('add-suggested-host').checkVisibility(), true);
});

test('two presses at once both land: each reads the list the one before wrote', async () => {
    const { stored, element } = await optionsPageWith(undefined);

    element('allowed-host-input').value = 'http://localhost:8000';
    element('add-allowed-host').click();
    element('add-suggested-host').click();
    await settle();

    assert.deepEqual(await stored(), ['http://localhost:8000', 'http://localhost:*']);
});

test('Remove pressed on two hosts back to back moves focus from the list the first left, to the host after both', async () => {
    const { focusedName, stored, shownEntries, removeButtonOf } = await optionsPageWith(['http://localhost:*', 'https://shop.acme-foods.test', 'http://127.0.0.1:8000']);
    removeButtonOf('http://localhost:*').focus();

    removeButtonOf('http://localhost:*').click();
    removeButtonOf('https://shop.acme-foods.test').click();
    await settle();

    assert.deepEqual(await stored(), ['http://127.0.0.1:8000']);
    assert.deepEqual(shownEntries(), ['http://127.0.0.1:8000']);
    assert.equal(focusedName(), 'Remove http://127.0.0.1:8000');
});

test('Add that storage cannot save says so as an error, keeps the typed text and the list, and marks no input invalid', async () => {
    const { add, stored, shownEntries, element, breakSaving } = await optionsPageWith(['https://shop.acme-foods.test']);
    breakSaving();

    await add('http://localhost:8000');

    assert.equal(element('allowed-host-status').textContent, ALLOWED_HOST_TEXTS.notSaved);
    assert.equal(element('allowed-host-status').classList.contains('error'), true);
    assert.equal(element('allowed-host-input').value, 'http://localhost:8000');
    assert.equal(element('allowed-host-input').getAttribute('aria-invalid'), null);
    assert.deepEqual(shownEntries(), ['https://shop.acme-foods.test']);
    assert.deepEqual(await stored(), ['https://shop.acme-foods.test']);
});

test('Remove that storage cannot save says so as an error and keeps the host, and marks no input invalid', async () => {
    const { stored, shownEntries, removeButtonOf, element, breakSaving } = await optionsPageWith(['https://shop.acme-foods.test', 'http://localhost:*']);
    breakSaving();

    removeButtonOf('https://shop.acme-foods.test').click();
    await settle();

    assert.equal(element('allowed-host-status').textContent, ALLOWED_HOST_TEXTS.notSaved);
    assert.equal(element('allowed-host-status').classList.contains('error'), true);
    assert.equal(element('allowed-host-input').getAttribute('aria-invalid'), null);
    assert.deepEqual(shownEntries(), ['https://shop.acme-foods.test', 'http://localhost:*']);
    assert.deepEqual(await stored(), ['https://shop.acme-foods.test', 'http://localhost:*']);
});

test('a list that cannot be read marks no input invalid', async () => {
    const storage = { get: async () => { throw new Error('IO error'); }, set: async () => undefined };
    const document = documentOf(OPTIONS_HTML);

    await new AllowedHostList(document, storage).show();

    assert.equal(document.getElementById('allowed-host-status').textContent, ALLOWED_HOST_TEXTS.notRead);
    assert.equal(document.getElementById('allowed-host-input').getAttribute('aria-invalid'), null);
});

test('the suggestion refused by a full list clears the mark a refused typed host left, and keeps that text', async () => {
    const full = Array.from({ length: MAX_ALLOWED_HOSTS }, (_, index) => `http://host-${index}.acme-foods.test`);
    const { add, stored, element } = await optionsPageWith(full);
    await add('ftp://shop.acme-foods.test');
    assert.equal(element('allowed-host-input').getAttribute('aria-invalid'), 'true');

    element('add-suggested-host').click();
    await settle();

    assert.equal(element('allowed-host-status').textContent, HOST_REFUSALS.listFull);
    assert.equal(element('allowed-host-status').classList.contains('error'), true);
    assert.equal(element('allowed-host-input').getAttribute('aria-invalid'), null);
    assert.equal(element('allowed-host-input').value, 'ftp://shop.acme-foods.test');
    assert.deepEqual(await stored(), full);
});

test('a typed host refused, then one added, leaves the input no longer marked invalid', async () => {
    const { add, stored, element } = await optionsPageWith(['https://shop.acme-foods.test']);
    await add('ftp://shop.acme-foods.test');
    assert.equal(element('allowed-host-input').getAttribute('aria-invalid'), 'true');

    await add('http://localhost:8000');

    assert.equal(element('allowed-host-input').getAttribute('aria-invalid'), null);
    assert.deepEqual(await stored(), ['https://shop.acme-foods.test', 'http://localhost:8000']);
});
