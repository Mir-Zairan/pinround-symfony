import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { afterEach, test } from 'node:test';
import { browser } from '../src/shared/browser.js';
import { fakeBrowser } from './fake-browser.mjs';

const SOURCE = new URL('../src/', import.meta.url);

function shapeOf(api) {
    return Object.fromEntries(Object.entries(api).filter(([, member]) => 'function' === typeof member || 'object' === typeof member).map(([name, member]) => [name, 'function' === typeof member ? 'function' : shapeOf(member)]));
}

afterEach(() => {
    delete globalThis.chrome;
});

test('the fake offers exactly what the adapter offers, so a test cannot use a call the extension does not have', () => {
    const { storage, tabs, runtime, scripting } = fakeBrowser();

    assert.deepEqual(shapeOf({ storage, tabs, runtime, scripting }), shapeOf(browser));
    assert.deepEqual(shapeOf(browser), {
        storage: { local: { get: 'function', set: 'function', remove: 'function' }, session: { get: 'function', set: 'function', remove: 'function' } },
        tabs: { query: 'function', captureVisibleTab: 'function', create: 'function', get: 'function', update: 'function' },
        runtime: { openOptionsPage: 'function' },
        scripting: { executeScript: 'function' },
    });
});

test('the adapter hands each call to the chrome API with its arguments and answers what chrome answered', async () => {
    const calls = [];
    const answering = (name) => async (...args) => { calls.push([name, ...args]); return `${name} answer`; };
    globalThis.chrome = {
        storage: {
            local: { get: answering('local.get'), set: answering('local.set'), remove: answering('local.remove') },
            session: { get: answering('session.get'), set: answering('session.set'), remove: answering('session.remove') },
        },
        tabs: {
            query: answering('tabs.query'),
            captureVisibleTab: answering('tabs.captureVisibleTab'),
            create: answering('tabs.create'),
            get: answering('tabs.get'),
            update: answering('tabs.update'),
        },
        runtime: { openOptionsPage: answering('runtime.openOptionsPage') },
        scripting: { executeScript: answering('scripting.executeScript') },
    };
    const injection = { target: { tabId: 7 }, files: ['src/overlay/overlay.js'] };

    assert.equal(await browser.storage.local.get('pinround'), 'local.get answer');
    assert.equal(await browser.storage.session.set({ captures: 1 }), 'session.set answer');
    assert.equal(await browser.storage.session.remove('captures'), 'session.remove answer');
    assert.equal(await browser.tabs.captureVisibleTab({ format: 'png' }), 'tabs.captureVisibleTab answer');
    assert.equal(await browser.tabs.create({ url: 'https://pinround.acme-foods.example/' }), 'tabs.create answer');
    assert.equal(await browser.tabs.query({ active: true }), 'tabs.query answer');
    assert.equal(await browser.tabs.get(7), 'tabs.get answer');
    assert.equal(await browser.tabs.update(7, { url: 'https://shop.acme-foods.example/orders' }), 'tabs.update answer');
    assert.equal(await browser.runtime.openOptionsPage(), 'runtime.openOptionsPage answer');
    assert.equal(await browser.scripting.executeScript(injection), 'scripting.executeScript answer');

    assert.deepEqual(calls, [
        ['local.get', 'pinround'],
        ['session.set', { captures: 1 }],
        ['session.remove', 'captures'],
        ['tabs.captureVisibleTab', { format: 'png' }],
        ['tabs.create', { url: 'https://pinround.acme-foods.example/' }],
        ['tabs.query', { active: true }],
        ['tabs.get', 7],
        ['tabs.update', 7, { url: 'https://shop.acme-foods.example/orders' }],
        ['runtime.openOptionsPage'],
        ['scripting.executeScript', injection],
    ]);
});

test('no extension source but the adapter names the chrome API', () => {
    const sources = readdirSync(SOURCE, { recursive: true }).filter((file) => file.endsWith('.js'));
    assert.ok(sources.length > 1, 'The source folder has the adapter and the code that uses it.');

    const callers = sources.filter((file) => /\bchrome\s*[.[]/.test(readFileSync(new URL(file, SOURCE), 'utf8')));

    assert.deepEqual(callers, ['shared/browser.js']);
});
