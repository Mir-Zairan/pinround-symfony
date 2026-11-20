import assert from 'node:assert/strict';
import { test } from 'node:test';
import { CANNOT_ACCESS_PAGE, fakeBrowser } from './fake-browser.mjs';

const SHOP_PAGE = { id: 7, url: 'https://shop.acme-foods.example/cart', title: 'Acme Foods shop', active: true };
const OTHER_PAGE = { id: 8, url: 'https://news.acme-foods.example/', title: 'Acme Foods news', active: false };

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

test('removing a missing key is fine, and local and session storage are apart', async () => {
    const { storage } = fakeBrowser();
    await storage.local.set({ pinround: 'kept', other: 'kept too' });
    await storage.session.set({ pinround: 'session only' });

    await storage.local.remove('missing');
    await storage.local.remove(['pinround', 'also missing']);

    assert.deepEqual(await storage.local.get(null), { other: 'kept too' });
    assert.deepEqual(await storage.session.get(null), { pinround: 'session only' });
});

test('captureVisibleTab answers a data URL of the active tab, png when asked and jpeg by default, only with access', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE, OTHER_PAGE] });
    await assert.rejects(browser.tabs.captureVisibleTab({ format: 'png' }), { message: "Either the '<all_urls>' or 'activeTab' permission is required." });

    browser.clickIcon(SHOP_PAGE.id);
    const png = await browser.tabs.captureVisibleTab({ format: 'png' });
    assert.equal(typeof png, 'string');
    assert.match(png, /^data:image\/png;base64,[A-Za-z0-9+/]+=*$/);
    assert.match(await browser.tabs.captureVisibleTab(), /^data:image\/jpeg;base64,/);
});

test('executeScript answers [{ result }] like Chrome, with the arguments and the result copied', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE], hostOrigins: ['https://shop.acme-foods.example'] });
    const step = { label: 'Pay' };

    const answer = await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: (given) => { given.label = 'changed in the page'; return { seen: given.label }; }, args: [step] });

    assert.equal(answer.length, 1);
    assert.deepEqual(answer[0].result, { seen: 'changed in the page' });
    assert.equal(answer[0].frameId, 0);
    assert.equal(step.label, 'Pay', 'The page gets a copy of the arguments.');
    assert.deepEqual(await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: async () => 'awaited' }).then((results) => results.map((injection) => injection.result)), ['awaited']);
});

test('executeScript records file injections and refuses an injection that names both or neither of func and files', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE] });
    browser.clickIcon(SHOP_PAGE.id);

    const answer = await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, files: ['src/overlay/overlay.js'] });

    assert.deepEqual(answer.map((injection) => injection.frameId), [0]);
    assert.deepEqual(browser.injectedFiles, [{ tabId: SHOP_PAGE.id, files: ['src/overlay/overlay.js'] }]);
    await assert.rejects(browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id } }), { message: "Exactly one of 'func' and 'files' must be specified." });
    await assert.rejects(browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => 1, files: ['a.js'] }), { message: "Exactly one of 'func' and 'files' must be specified." });
    assert.equal(browser.injectedFiles.length, 1, 'A refused injection records nothing.');
});

test('executeScript refuses like Chrome on a page the extension may not reach, and on a tab that is not there', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE, OTHER_PAGE], hostOrigins: ['https://shop.acme-foods.example'] });
    let ran = false;

    await assert.rejects(browser.scripting.executeScript({ target: { tabId: OTHER_PAGE.id }, func: () => { ran = true; } }), { message: CANNOT_ACCESS_PAGE });
    await assert.rejects(browser.scripting.executeScript({ target: { tabId: 99 }, func: () => { ran = true; } }), { message: 'No tab with id: 99.' });
    assert.equal(ran, false);
    assert.match(CANNOT_ACCESS_PAGE, /^Cannot access contents of the page\./);
});

test('activeTab reaches only the tab whose icon was clicked, and only until it navigates', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE, OTHER_PAGE] });
    browser.clickIcon(SHOP_PAGE.id);

    assert.deepEqual(await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => 'reached' }).then((results) => results[0].result), 'reached');
    await assert.rejects(browser.scripting.executeScript({ target: { tabId: OTHER_PAGE.id }, func: () => 'reached' }), { message: CANNOT_ACCESS_PAGE });

    browser.navigate(SHOP_PAGE.id, 'https://bank.acme-foods.example/');
    await assert.rejects(browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => 'reached' }), { message: CANNOT_ACCESS_PAGE });
});

test('a host permission keeps reaching the tab after it navigates within that origin', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE], hostOrigins: ['https://shop.acme-foods.example'] });

    browser.navigate(SHOP_PAGE.id, 'https://shop.acme-foods.example/checkout');

    assert.deepEqual(await browser.tabs.query({ active: true }), [{ ...SHOP_PAGE, url: 'https://shop.acme-foods.example/checkout' }]);
    assert.equal((await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => 'reached' }))[0].result, 'reached');
});

test('tabs.query filters on active and hides the address of a tab the extension may not reach', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE, OTHER_PAGE] });

    assert.deepEqual(await browser.tabs.query({ active: true, currentWindow: true }), [{ id: SHOP_PAGE.id, active: true }]);
    browser.clickIcon(SHOP_PAGE.id);
    assert.deepEqual(await browser.tabs.query({ active: true, currentWindow: true }), [SHOP_PAGE]);
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
