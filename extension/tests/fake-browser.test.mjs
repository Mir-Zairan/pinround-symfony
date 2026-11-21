import assert from 'node:assert/strict';
import { test } from 'node:test';
import { ACTIVE_TAB_NOT_IN_EFFECT, ALL_URLS, CANNOT_ACCESS_CHROME_URL, CANNOT_ACCESS_PAGE, FAKE_EXTENSION_ID, FIRST_WINDOW_ID, NEEDS_ACTIVE_TAB, USER_DID_NOT_APPROVE, fakeBrowser } from './fake-browser.mjs';

const SHOP_PAGE = { id: 7, url: 'https://shop.acme-foods.example/cart', title: 'Acme Foods shop', active: true, windowId: FIRST_WINDOW_ID };
const OTHER_PAGE = { id: 8, url: 'https://news.acme-foods.example/', title: 'Acme Foods news', active: false, windowId: FIRST_WINDOW_ID };
const SETTINGS_PAGE = { id: 9, url: 'chrome://settings/', title: 'Settings', active: false, windowId: FIRST_WINDOW_ID };
const SHOP_ORIGIN = 'https://shop.acme-foods.example';
const ORDER_PLACED_AT = new Date('2026-03-14T09:30:00Z');

const resultOf = async (browser, injection) => (await browser.scripting.executeScript(injection))[0].result;

test('storage answers {} for a missing key, {key: value} for a kept one and everything for null', async () => {
    const { storage } = fakeBrowser();
    assert.deepEqual(await storage.local.get('pinround'), {});
    assert.equal(Object.hasOwn(await storage.local.get('pinround'), 'pinround'), false);

    await storage.local.set({ pinround: { origin: 'https://pinround.acme-foods.example' }, other: 1 });
    assert.deepEqual(await storage.local.get('pinround'), { pinround: { origin: 'https://pinround.acme-foods.example' } });
    assert.deepEqual(await storage.local.get(['pinround', 'missing']), { pinround: { origin: 'https://pinround.acme-foods.example' } });
    assert.deepEqual(await storage.local.get({ missing: 'default', other: 2 }), { missing: 'default', other: 1 });
    assert.deepEqual(await storage.local.get(null), { pinround: { origin: 'https://pinround.acme-foods.example' }, other: 1 });
    assert.deepEqual(await storage.local.get(), { pinround: { origin: 'https://pinround.acme-foods.example' }, other: 1 });
});

test('storage copies values on set and on get, so a caller cannot change what is kept by reference', async () => {
    const { storage } = fakeBrowser();
    const captures = { project: 'SHOP', items: [{ upload_id: 'u1' }] };
    await storage.session.set({ captures });
    captures.items.push({ upload_id: 'u2' });

    const firstRead = (await storage.session.get('captures')).captures;
    firstRead.items.push({ upload_id: 'u3' });

    assert.deepEqual((await storage.session.get('captures')).captures, { project: 'SHOP', items: [{ upload_id: 'u1' }] });
});

test('storage keeps values as Chrome converts them: undefined and NaN dropped, a Date or Map as {}, no toJSON, keys sorted', async () => {
    const { storage } = fakeBrowser();
    const looped = { name: 'looped' };
    looped.self = looped;

    await storage.local.set({
        placedAt: ORDER_PLACED_AT,
        gone: undefined,
        notANumber: NaN,
        lines: [undefined, 2, () => 'x'],
        totals: new Map([['jam', 2]]),
        order: { note: undefined, placedAt: ORDER_PLACED_AT, total: -0, label: { toJSON: () => 'converted' } },
        looped,
    });
    const kept = await storage.local.get(null);

    assert.equal(kept.placedAt instanceof Date, false);
    assert.deepEqual(kept, {
        placedAt: {},
        lines: [null, 2, null],
        totals: {},
        order: { placedAt: {}, total: 0, label: {} },
        looped: { name: 'looped', self: null },
    });
    assert.equal(Object.hasOwn(kept, 'gone'), false);
    assert.equal(Object.is(kept.order.total, -0), false);
    assert.deepEqual(Object.keys(kept), ['lines', 'looped', 'order', 'placedAt', 'totals']);
    assert.deepEqual(Object.keys(kept.order), ['label', 'placedAt', 'total']);
    assert.deepEqual(Object.keys(await storage.local.get(['totals', 'lines'])), ['lines', 'totals']);
});

test('storage converts the fallbacks of get like the values of set', async () => {
    const { storage } = fakeBrowser();

    assert.deepEqual(await storage.local.get({ placedAt: ORDER_PLACED_AT, gone: undefined, notANumber: NaN, project: 'SHOP' }), { placedAt: {}, project: 'SHOP' });
});

test('storage throws at once on a wrong argument type, rejects binary data, and keeps nothing of a refused set', async () => {
    const { storage } = fakeBrowser();
    await storage.local.set({ pinround: 'kept' });

    await assert.rejects(storage.local.set({ picture: new Uint8Array([1, 2]), other: 'not kept either' }), { message: 'Cannot serialize value to JSON' });
    assert.throws(() => storage.local.set('pinround'), { name: 'TypeError', message: 'Error in invocation of storage.set(object items, optional function callback): No matching signature.' });
    assert.throws(() => storage.local.get(5), { name: 'TypeError', message: 'Error in invocation of storage.get(optional [string|array|object] keys, optional function callback): No matching signature.' });
    assert.throws(() => storage.local.remove(5), { name: 'TypeError', message: 'Error in invocation of storage.remove([string|array] keys, optional function callback): No matching signature.' });
    assert.deepEqual(await storage.local.get(null), { pinround: 'kept' });
});

test('removing a missing key is fine, and local and session storage are apart', async () => {
    const { storage } = fakeBrowser();
    await storage.local.set({ pinround: 'kept', other: 'kept too' });
    await storage.session.set({ pinround: 'session only' });

    await storage.local.remove('missing');
    await storage.local.remove(['pinround', 'also missing']);

    assert.deepEqual(await storage.local.get(null), { other: 'kept too' });
    assert.deepEqual(await storage.session.get(null), { pinround: 'session only' });
});

test('captureVisibleTab answers a data URL of the active tab after the icon was clicked there, png when asked and jpeg by default', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE, OTHER_PAGE] });
    await assert.rejects(browser.tabs.captureVisibleTab({ format: 'png' }), { message: NEEDS_ACTIVE_TAB });

    browser.clickIcon(SHOP_PAGE.id);
    const png = await browser.tabs.captureVisibleTab({ format: 'png' });
    assert.equal(typeof png, 'string');
    assert.match(png, /^data:image\/png;base64,[A-Za-z0-9+/]+=*$/);
    assert.match(await browser.tabs.captureVisibleTab(), /^data:image\/jpeg;base64,/);
});

test('captureVisibleTab refuses with a host permission alone, and with activeTab granted on a tab that is not the active one', async () => {
    const hostOnly = fakeBrowser({ tabs: [SHOP_PAGE], hostOrigins: [SHOP_ORIGIN] });
    await assert.rejects(hostOnly.tabs.captureVisibleTab({ format: 'png' }), { message: NEEDS_ACTIVE_TAB });
    assert.equal(await resultOf(hostOnly, { target: { tabId: SHOP_PAGE.id }, func: () => 'reached' }), 'reached', 'The host permission itself works.');

    const grantedElsewhere = fakeBrowser({ tabs: [SHOP_PAGE, OTHER_PAGE] });
    grantedElsewhere.clickIcon(OTHER_PAGE.id);
    await grantedElsewhere.tabs.create({ url: 'https://shop.acme-foods.example/new' });
    await assert.rejects(grantedElsewhere.tabs.captureVisibleTab({ format: 'png' }), { message: NEEDS_ACTIVE_TAB });
});

test('captureVisibleTab works with <all_urls> on a web page and refuses on a chrome:// page', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE, SETTINGS_PAGE], hostOrigins: [ALL_URLS] });
    assert.match(await browser.tabs.captureVisibleTab({ format: 'png' }), /^data:image\/png;base64,/);

    await browser.tabs.create({ url: 'chrome://version/' });
    await assert.rejects(browser.tabs.captureVisibleTab({ format: 'png' }), { message: ACTIVE_TAB_NOT_IN_EFFECT });
});

test('each window has its own active tab: captureVisibleTab takes the named window\'s, query\'s currentWindow and getLastFocused follow the window the person shows a tab in', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE, { ...SETTINGS_PAGE, windowId: 2, active: true }], hostOrigins: [ALL_URLS] });
    assert.deepEqual(await browser.windows.getLastFocused(), { id: FIRST_WINDOW_ID, focused: true });
    assert.deepEqual((await browser.tabs.query({ active: true, currentWindow: true })).map((tab) => tab.id), [SHOP_PAGE.id]);
    assert.deepEqual((await browser.tabs.query({ active: true })).map((tab) => tab.id), [SHOP_PAGE.id, SETTINGS_PAGE.id]);
    assert.match(await browser.tabs.captureVisibleTab(FIRST_WINDOW_ID, { format: 'png' }), /^data:image\/png;base64,/);
    await assert.rejects(browser.tabs.captureVisibleTab(2, { format: 'png' }), { message: ACTIVE_TAB_NOT_IN_EFFECT });
    await assert.rejects(browser.tabs.captureVisibleTab(5, { format: 'png' }), { message: 'No window with id: 5.' });

    browser.showTab(SETTINGS_PAGE.id);

    assert.deepEqual(await browser.windows.getLastFocused(), { id: 2, focused: true });
    assert.deepEqual((await browser.tabs.query({ active: true, currentWindow: true })).map((tab) => tab.id), [SETTINGS_PAGE.id]);
    assert.equal((await browser.tabs.get(SHOP_PAGE.id)).active, true, 'Showing a tab in another window leaves this window\'s active tab.');
    await assert.rejects(browser.tabs.captureVisibleTab({ format: 'png' }), { message: ACTIVE_TAB_NOT_IN_EFFECT });
    const opened = (await browser.tabs.create({ url: 'https://news.acme-foods.example/' }));
    assert.equal(opened.windowId, 2);
    assert.equal((await browser.tabs.get(SETTINGS_PAGE.id)).active, false);
});

test('executeScript answers [{ result }] like Chrome, with the arguments and the result copied', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE], hostOrigins: [SHOP_ORIGIN] });
    const step = { label: 'Pay' };

    const answer = await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: (given) => { given.label = 'changed in the page'; return { seen: given.label }; }, args: [step] });

    assert.equal(answer.length, 1);
    assert.deepEqual(answer[0].result, { seen: 'changed in the page' });
    assert.equal(answer[0].frameId, 0);
    assert.equal(step.label, 'Pay', 'The page gets a copy of the arguments.');
    assert.equal(await resultOf(browser, { target: { tabId: SHOP_PAGE.id }, func: async () => 'awaited' }), 'awaited');
});

test('executeScript runs func from its source in the page, where the caller\'s variables do not exist', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE], hostOrigins: [SHOP_ORIGIN] });
    const outside = { touched: false };

    const answer = await resultOf(browser, { target: { tabId: SHOP_PAGE.id }, func: () => { outside.touched = true; return 'ran'; } });

    assert.equal(answer, null, 'A ReferenceError in the page answers null, as in Chrome.');
    assert.equal(outside.touched, false);
    assert.equal(await resultOf(browser, { target: { tabId: SHOP_PAGE.id }, func: (amount, currency) => `${amount} ${currency}`, args: [12.4, 'EUR'] }), '12.4 EUR');
    assert.deepEqual(await resultOf(browser, { target: { tabId: SHOP_PAGE.id }, func: () => [typeof window, window === globalThis] }), ['object', true]);
});

test('executeScript answers null when func throws, rejects, or returns nothing Chrome can carry', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE], hostOrigins: [SHOP_ORIGIN] });
    const run = (func) => resultOf(browser, { target: { tabId: SHOP_PAGE.id }, func });

    assert.equal(await run(() => { throw new Error('broken page'); }), null);
    assert.equal(await run(async () => { throw new Error('broken later'); }), null);
    assert.equal(await run(() => undefined), null);
    assert.equal(await run(() => NaN), null);
    assert.equal(await run(() => () => 'a function'), null);
    assert.equal(await run(() => 0), 0, 'A falsy result is still the result.');
});

test('executeScript arguments and results cross as Chrome converts them, not as JSON or structured clone', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE], hostOrigins: [SHOP_ORIGIN] });

    const pageSaw = await resultOf(browser, {
        target: { tabId: SHOP_PAGE.id },
        func: (order) => ({ json: JSON.stringify(order), placedAtIsDate: order.placedAt instanceof Date }),
        args: [{ placedAt: ORDER_PLACED_AT, note: undefined, lines: [undefined, NaN, 3], label: { toJSON: () => 'converted' } }],
    });
    const result = await resultOf(browser, {
        target: { tabId: SHOP_PAGE.id },
        func: () => { const loop = { at: new Date(0), gone: undefined, bytes: new Uint8Array([1]), lines: [undefined, 2] }; loop.self = loop; return loop; },
    });

    assert.deepEqual(pageSaw, { json: '{"label":{},"lines":[null,null,3],"placedAt":{}}', placedAtIsDate: false });
    assert.deepEqual(result, { at: {}, bytes: {}, lines: [null, 2], self: null });
});

test('executeScript refuses arguments Chrome cannot send before it runs anything: a wrong shape throws at once, binary data rejects', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE], hostOrigins: [SHOP_ORIGIN] });
    const injectionOf = (args) => ({ target: { tabId: SHOP_PAGE.id }, func: () => { globalThis.ran = true; }, args });
    const argsError = "Error in invocation of scripting.executeScript(scripting.ScriptInjection injection, optional function callback): Error at parameter 'injection': Error at property 'args'";

    assert.throws(() => browser.scripting.executeScript(injectionOf(['SHOP', undefined])), { name: 'TypeError', message: `${argsError}: Error at index 1: Value is unserializable.` });
    assert.throws(() => browser.scripting.executeScript(injectionOf([() => 'x'])), { name: 'TypeError', message: `${argsError}: Error at index 0: Value is unserializable.` });
    assert.throws(() => browser.scripting.executeScript(injectionOf('SHOP')), { name: 'TypeError', message: `${argsError}: Invalid type: expected array, found string.` });
    await assert.rejects(browser.scripting.executeScript(injectionOf([{ picture: new Uint8Array([1]) }])), { message: 'Unserializable argument passed.' });
    assert.equal(await resultOf(browser, { target: { tabId: SHOP_PAGE.id }, func: () => globalThis.ran ?? 'never ran' }), 'never ran');
});

test('the page keeps its globals between injections until the tab navigates, and a navigation is a new document', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE], hostOrigins: [SHOP_ORIGIN] });
    const [first] = await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => { globalThis.kept = 'from the first injection'; } });
    const [second] = await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => globalThis.kept });

    browser.navigate(SHOP_PAGE.id, 'https://shop.acme-foods.example/checkout');
    const [afterNavigation] = await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => globalThis.kept ?? 'gone' });

    assert.equal(second.result, 'from the first injection');
    assert.equal(second.documentId, first.documentId);
    assert.equal(afterNavigation.result, 'gone');
    assert.notEqual(afterNavigation.documentId, first.documentId);
});

test('executeScript records file injections, refuses a file the extension does not have, and one that names both or neither of func and files', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE] });
    browser.clickIcon(SHOP_PAGE.id);

    const answer = await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, files: ['src/popup/report-captures.js'] });

    assert.deepEqual(answer.map((injection) => [injection.frameId, injection.result]), [[0, null]]);
    assert.deepEqual(browser.injectedFiles, [{ tabId: SHOP_PAGE.id, files: ['src/popup/report-captures.js'] }]);
    await assert.rejects(browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, files: ['src/popup/report-captures.js', 'src/missing.js'] }), { message: "Could not load file: 'src/missing.js'." });
    await assert.rejects(browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id } }), { message: "Exactly one of 'func' and 'files' must be specified" });
    await assert.rejects(browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => 1, files: ['src/popup/report-captures.js'] }), { message: "Exactly one of 'func' and 'files' must be specified" });
    assert.equal(browser.injectedFiles.length, 1, 'A refused injection records nothing.');
});

test('executeScript finds files from the extension\'s root after one leading slash, and refuses what Chrome does with its message', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE] });
    browser.clickIcon(SHOP_PAGE.id);
    const notFound = ['src/overlay/missing.js', '//src/overlay/pin-rules.js', 'src/popup/../overlay/pin-rules.js', '../pin-rules.js', 'src/overlay/', 'SRC/overlay/pin-rules.js', 'src/overlay/pin-rules.js/', 'src/overlay/missing.JS'];
    const noScript = ['src/overlay', 'styles.css'];

    await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, files: ['/src/overlay/pin-rules.js'] });

    assert.deepEqual(browser.injectedFiles, [{ tabId: SHOP_PAGE.id, files: ['/src/overlay/pin-rules.js'] }]);
    for (const file of notFound) {
        await assert.rejects(browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, files: [file] }), { message: `Could not load file: '${file}'.` }, file);
    }
    for (const file of noScript) {
        await assert.rejects(browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, files: [file] }), { message: `Could not load file '${file}' for content script, content scripts can only be loaded from supported JavaScript files such as .js files.` }, file);
    }
    assert.equal(browser.injectedFiles.length, 1);
});

test('executeScript refuses like Chrome on a page the extension may not reach, on a chrome:// page, and on a tab that is not there', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE, OTHER_PAGE, SETTINGS_PAGE], hostOrigins: [SHOP_ORIGIN] });
    const everywhere = fakeBrowser({ tabs: [SETTINGS_PAGE], hostOrigins: [ALL_URLS] });

    await assert.rejects(browser.scripting.executeScript({ target: { tabId: OTHER_PAGE.id }, func: () => 'reached' }), { message: CANNOT_ACCESS_PAGE });
    await assert.rejects(browser.scripting.executeScript({ target: { tabId: SETTINGS_PAGE.id }, func: () => 'reached' }), { message: CANNOT_ACCESS_CHROME_URL });
    await assert.rejects(everywhere.scripting.executeScript({ target: { tabId: SETTINGS_PAGE.id }, func: () => 'reached' }), { message: CANNOT_ACCESS_CHROME_URL });
    await assert.rejects(browser.scripting.executeScript({ target: { tabId: 99 }, func: () => 'reached' }), { message: 'No tab with id: 99' });
    assert.match(CANNOT_ACCESS_PAGE, /^Cannot access contents of the page\./);
});

test('<all_urls> reaches every web page but not a chrome:// page, whose address stays hidden', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE, OTHER_PAGE, SETTINGS_PAGE], hostOrigins: [ALL_URLS] });

    assert.equal(await resultOf(browser, { target: { tabId: OTHER_PAGE.id }, func: () => 'reached' }), 'reached');
    assert.deepEqual((await browser.tabs.query({})).map((tab) => tab.url), [SHOP_PAGE.url, OTHER_PAGE.url, undefined]);
    assert.throws(() => browser.clickIcon(SETTINGS_PAGE.id), /does not model activeTab on a chrome:\/\/ page/);
});

test('activeTab reaches only the tab whose icon was clicked, and keeps it through a navigation within the origin', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE, OTHER_PAGE] });
    browser.clickIcon(SHOP_PAGE.id);

    assert.equal(await resultOf(browser, { target: { tabId: SHOP_PAGE.id }, func: () => 'reached' }), 'reached');
    await assert.rejects(browser.scripting.executeScript({ target: { tabId: OTHER_PAGE.id }, func: () => 'reached' }), { message: CANNOT_ACCESS_PAGE });

    browser.navigate(SHOP_PAGE.id, 'https://shop.acme-foods.example/checkout');
    assert.equal(await resultOf(browser, { target: { tabId: SHOP_PAGE.id }, func: () => 'still reached' }), 'still reached');
    assert.match(await browser.tabs.captureVisibleTab({ format: 'png' }), /^data:image\/png;base64,/);
});

test('activeTab ends when the tab navigates to another origin, also after it comes back', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE] });
    browser.clickIcon(SHOP_PAGE.id);

    browser.navigate(SHOP_PAGE.id, 'https://bank.acme-foods.example/');
    await assert.rejects(browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => 'reached' }), { message: CANNOT_ACCESS_PAGE });
    await assert.rejects(browser.tabs.captureVisibleTab({ format: 'png' }), { message: NEEDS_ACTIVE_TAB });

    browser.navigate(SHOP_PAGE.id, SHOP_PAGE.url);
    await assert.rejects(browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => 'reached' }), { message: CANNOT_ACCESS_PAGE });
});

test('a host permission keeps reaching the tab after it navigates within that origin', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE], hostOrigins: [SHOP_ORIGIN] });

    browser.navigate(SHOP_PAGE.id, 'https://shop.acme-foods.example/checkout');

    assert.deepEqual(await browser.tabs.query({ active: true }), [{ ...SHOP_PAGE, url: 'https://shop.acme-foods.example/checkout', status: 'loading' }]);
    assert.equal(await resultOf(browser, { target: { tabId: SHOP_PAGE.id }, func: () => 'reached' }), 'reached');
});

test('tabs.query filters on active and hides the address of a tab the extension may not reach', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE, OTHER_PAGE] });

    assert.deepEqual(await browser.tabs.query({ active: true, currentWindow: true }), [{ id: SHOP_PAGE.id, active: true, status: 'complete', windowId: FIRST_WINDOW_ID }]);
    browser.clickIcon(SHOP_PAGE.id);
    assert.deepEqual(await browser.tabs.query({ active: true, currentWindow: true }), [{ ...SHOP_PAGE, status: 'complete' }]);
    assert.deepEqual((await browser.tabs.query({})).map((tab) => tab.id), [SHOP_PAGE.id, OTHER_PAGE.id]);
    await assert.rejects(browser.tabs.query({ url: SHOP_PAGE.url }), /does not model the query key url/);
});

test('tabs.create opens an active tab and runtime.openOptionsPage is counted', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE] });

    const created = await browser.tabs.create({ url: 'https://pinround.acme-foods.example/reports/1' });
    await browser.runtime.openOptionsPage();

    assert.equal(Object.hasOwn(created, 'url'), false, 'Without access to the page Chrome leaves the address out.');
    assert.notEqual(created.id, SHOP_PAGE.id);
    assert.deepEqual((await browser.tabs.query({ active: true })).map((tab) => tab.id), [created.id]);
    assert.equal(browser.tabUrl(created.id), 'https://pinround.acme-foods.example/reports/1');
    assert.equal(browser.optionsPageOpenings, 1);
});

test('tabs.get answers the tab with its status, hides an address out of reach, rejects a missing tab and throws on an id that is no integer', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE, { ...OTHER_PAGE, status: 'loading' }], hostOrigins: [SHOP_ORIGIN] });

    assert.deepEqual(await browser.tabs.get(SHOP_PAGE.id), { ...SHOP_PAGE, status: 'complete' });
    assert.deepEqual(await browser.tabs.get(OTHER_PAGE.id), { id: OTHER_PAGE.id, active: false, status: 'loading', windowId: FIRST_WINDOW_ID });
    await assert.rejects(browser.tabs.get(99), { message: 'No tab with id: 99.' });
    assert.throws(() => browser.tabs.get('7'), { name: 'TypeError', message: 'Error in invocation of tabs.get(integer tabId, optional function callback): No matching signature.' });
});

test('a page\'s own navigation loads until finishLoading, and a func still running in the left document answers null with its id', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE], hostOrigins: [SHOP_ORIGIN] });
    const pending = browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => new Promise(() => { globalThis.waiting = true; }) });
    const [{ documentId: leftDocumentId, result: waiting }] = await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => globalThis.waiting });
    assert.equal(waiting, true);

    browser.navigate(SHOP_PAGE.id, 'https://shop.acme-foods.example/receipt');

    assert.deepEqual(await pending, [{ documentId: leftDocumentId, frameId: 0, result: null }]);
    assert.deepEqual(await browser.tabs.get(SHOP_PAGE.id), { ...SHOP_PAGE, url: 'https://shop.acme-foods.example/receipt', status: 'loading' });
    browser.finishLoading(SHOP_PAGE.id);
    assert.equal((await browser.tabs.get(SHOP_PAGE.id)).status, 'complete');
    const [afterwards] = await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => globalThis.waiting ?? 'a new document' });
    assert.deepEqual([afterwards.result, afterwards.documentId === leftDocumentId], ['a new document', false]);
});

test('tabs.update navigates: it answers the loading tab with the old url and the new one pending, and the old document stays until the new page commits', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE], hostOrigins: [SHOP_ORIGIN] });
    await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => { globalThis.kept = 'old document'; } });

    const answered = await browser.tabs.update(SHOP_PAGE.id, { url: 'https://shop.acme-foods.example/orders' });

    assert.deepEqual(answered, { ...SHOP_PAGE, status: 'loading', pendingUrl: 'https://shop.acme-foods.example/orders' });
    assert.equal(await resultOf(browser, { target: { tabId: SHOP_PAGE.id }, func: () => globalThis.kept ?? 'gone' }), 'old document');
    browser.finishLoading(SHOP_PAGE.id);
    assert.deepEqual(await browser.tabs.get(SHOP_PAGE.id), { ...SHOP_PAGE, url: 'https://shop.acme-foods.example/orders', status: 'complete' });
    assert.equal(await resultOf(browser, { target: { tabId: SHOP_PAGE.id }, func: () => globalThis.kept ?? 'gone' }), 'gone');
});

test('tabs.update to another origin keeps activeTab on the old page while it loads, then ends it and hides the address; a missing tab rejects, an id that is no integer throws', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE] });
    browser.clickIcon(SHOP_PAGE.id);

    const answered = await browser.tabs.update(SHOP_PAGE.id, { url: 'https://bank.acme-foods.example/' });

    assert.deepEqual(answered, { ...SHOP_PAGE, status: 'loading', pendingUrl: 'https://bank.acme-foods.example/' });
    assert.equal(await resultOf(browser, { target: { tabId: SHOP_PAGE.id }, func: () => 'reached' }), 'reached');
    browser.finishLoading(SHOP_PAGE.id);
    assert.deepEqual(await browser.tabs.get(SHOP_PAGE.id), { id: SHOP_PAGE.id, active: true, status: 'complete', windowId: FIRST_WINDOW_ID });
    await assert.rejects(browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => 'reached' }), { message: CANNOT_ACCESS_PAGE });
    await assert.rejects(browser.tabs.update(99, { url: SHOP_PAGE.url }), { message: 'No tab with id: 99.' });
    assert.throws(() => browser.tabs.update('7', { url: SHOP_PAGE.url }), {
        name: 'TypeError',
        message: 'Error in invocation of tabs.update(optional integer tabId, object updateProperties, optional function callback): No matching signature.',
    });
});

test('identity answers the chromiumapp.org address its window reaches, and rejects a closed window or one that never got there', async () => {
    const redirectUri = `https://${FAKE_EXTENSION_ID}.chromiumapp.org/`;
    const reachedOf = { 'https://pinround.acme-foods.example/allow': `${redirectUri}?code=c1&state=s1`, 'https://pinround.acme-foods.example/elsewhere': 'https://pinround.acme-foods.example/done' };
    const browser = fakeBrowser({ webAuthFlow: async (url) => reachedOf[url] ?? null });

    assert.equal(browser.identity.getRedirectURL(), redirectUri);
    assert.equal(browser.identity.getRedirectURL('done'), `${redirectUri}done`);
    assert.equal(await browser.identity.launchWebAuthFlow({ url: 'https://pinround.acme-foods.example/allow', interactive: true }), `${redirectUri}?code=c1&state=s1`);
    await assert.rejects(browser.identity.launchWebAuthFlow({ url: 'https://pinround.acme-foods.example/elsewhere', interactive: true }), { message: USER_DID_NOT_APPROVE });
    await assert.rejects(browser.identity.launchWebAuthFlow({ url: 'https://pinround.acme-foods.example/closed' }), { message: USER_DID_NOT_APPROVE });
    assert.throws(() => browser.identity.launchWebAuthFlow({ interactive: true }), { name: 'TypeError' });
    assert.deepEqual(browser.webAuthFlows.map((flow) => flow.interactive), [true, true, false]);
});
