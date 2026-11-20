import assert from 'node:assert/strict';
import { test } from 'node:test';
import { PinroundApi } from '../src/shared/api.js';
import { SUMMARY_MAX_LENGTH, TITLE_MAX_LENGTH, captureTexts } from '../src/capture/capture.js';
import { captureToTarget } from '../src/capture/capture-to-target.js';
import { PAGE_TEXT_READ_MAX_LENGTH, activePageContext, addressWithoutSecrets, browserNameAndVersion, cutToFit, oneLinePageText, pageContext } from '../src/capture/page-context.js';
import { fakeBrowser } from './fake-browser.mjs';
import { PINROUND_ORIGIN, TOKEN, fakePinround } from './fake-pinround.mjs';

const CHROME = 'Google Chrome 131.0.6778.86';
const ORDER_PAGE = {
    id: 7,
    url: 'https://omar:s3cret@shop.acme-foods.example/orders/42?token=abc123&x=1#frag',
    title: '\uFEFFBestellübersicht\r\n–\u001B[2J 注文\u2028\u2029Acme Foods\r\n',
    width: 1280,
    height: 720,
    active: true,
};
const FULL_VERSION_LIST = [
    { brand: 'Not)A;Brand', version: '99.0.0.0' },
    { brand: 'Google Chrome', version: '131.0.6778.86' },
    { brand: 'Chromium', version: '131.0.6778.86' },
];

const navigatorWith = (fullVersionList, brands = []) => ({
    userAgentData: {
        brands,
        getHighEntropyValues: async (hints) => (hints.includes('fullVersionList') ? { fullVersionList } : {}),
    },
});

test('page text loses its control characters, BOM and direction overrides, and its white space collapses', () => {
    assert.equal(oneLinePageText(ORDER_PAGE.title), 'Bestellübersicht – [2J 注文 Acme Foods');
    assert.equal(oneLinePageText('Bestellübersicht – 注文\r\n'), 'Bestellübersicht – 注文', 'A final CRLF goes.');
    assert.equal(oneLinePageText('Bestellübersicht – 注文'), 'Bestellübersicht – 注文', 'No final newline changes nothing.');
    assert.equal(oneLinePageText('Cart\u202Egnp.exe\u2066 total\u0085\t\tdue'), 'Cartgnp.exe total due');
    assert.equal(oneLinePageText(' \r\n\uFEFF '), '');
    assert.equal(oneLinePageText('Half \uD83D'), 'Half \uFFFD', 'A lone surrogate becomes a replacement character, which JSON carries as it is.');
    assert.equal(oneLinePageText(`${' '.repeat(PAGE_TEXT_READ_MAX_LENGTH)}Never read`), '', 'Nothing past the read cap is looked at.');
});

test('an address keeps no credentials, query or fragment', () => {
    assert.equal(addressWithoutSecrets(ORDER_PAGE.url), 'https://shop.acme-foods.example/orders/42');
    assert.equal(addressWithoutSecrets('https://shop.acme-foods.example/cart?#'), 'https://shop.acme-foods.example/cart', 'An empty query and fragment leave no marks.');
    assert.equal(addressWithoutSecrets('https://omar@shop.acme-foods.example:8443/'), 'https://shop.acme-foods.example:8443/');
    assert.equal(addressWithoutSecrets('http://localhost:8000/basket#step-2'), 'http://localhost:8000/basket');
    assert.equal(addressWithoutSecrets('file:///home/omar/report.html?print=1'), 'file:///home/omar/report.html');
    assert.equal(addressWithoutSecrets('not an address'), null);
});

test('a cut keeps whole characters, ends in an ellipsis, and fits the popup field and pinround at the exact cap', () => {
    assert.equal(cutToFit('注'.repeat(120), 120), '注'.repeat(120), 'Exactly the cap stays whole.');
    assert.equal(cutToFit('注'.repeat(121), 120), `${'注'.repeat(119)}…`);
    assert.equal(cutToFit('😀'.repeat(60), 120), '😀'.repeat(60), 'Sixty emoji fill 120 code units, the input\'s maxlength.');
    const cutEmoji = cutToFit('😀'.repeat(61), 120);
    assert.equal(cutEmoji, `${'😀'.repeat(59)}…`);
    assert.ok(cutEmoji.isWellFormed(), 'No surrogate is cut in half.');
    const family = '👨‍👩‍👧';
    const cutFamilies = cutToFit(family.repeat(20), 120);
    assert.equal(cutFamilies, `${family.repeat(14)}…`, 'A joined emoji is kept whole or left out whole.');
    assert.equal(cutToFit('Größe  ', 4), 'Grö…');
    assert.equal(cutToFit('a bc', 3), 'a…', 'No space is left before the ellipsis.');
    assert.equal(cutToFit('', 3), '');
});

test('the context is the page title and "Page: … · Window w×h · browser", with nothing secret from the address', () => {
    assert.deepEqual(pageContext(ORDER_PAGE, CHROME), {
        title: 'Bestellübersicht – [2J 注文 Acme Foods',
        summary: 'Page: https://shop.acme-foods.example/orders/42 · Window 1280×720 · Google Chrome 131.0.6778.86',
    });
    assert.deepEqual(pageContext({ ...ORDER_PAGE, width: undefined }, ''), { title: 'Bestellübersicht – [2J 注文 Acme Foods', summary: 'Page: https://shop.acme-foods.example/orders/42' });
    assert.deepEqual(pageContext({ id: 7, title: 'Hidden', width: 1280, height: 720 }, CHROME), { title: '', summary: '' }, 'A tab whose address the extension may not read gives no context.');
    assert.deepEqual(pageContext({ ...ORDER_PAGE, url: 'not an address' }, CHROME), { title: 'Bestellübersicht – [2J 注文 Acme Foods', summary: '' });
});

test('a long title and a long address are cut to the caps, and the window and browser stay at the end of the summary', () => {
    const tail = ' · Window 1280×720 · Google Chrome 131.0.6778.86';
    const context = pageContext({ ...ORDER_PAGE, url: `https://shop.acme-foods.example/${'注'.repeat(2000)}?token=abc`, title: 'Größe '.repeat(100) }, CHROME);

    assert.equal(context.title, `${'Größe '.repeat(19)}Größe…`);
    assert.equal(context.title.length, TITLE_MAX_LENGTH);
    assert.ok(context.summary.length <= SUMMARY_MAX_LENGTH);
    assert.ok(context.summary.startsWith('Page: https://shop.acme-foods.example/%E6%B3%A8'));
    assert.ok(context.summary.endsWith(`…${tail}`), context.summary);
    assert.ok(!context.summary.includes('token'));
    assert.deepEqual(captureTexts(context.title, context.summary), context, 'pinround\'s caps take what the popup fills in.');
});

test('the browser is named by its own brand and full version, never by the made-up brand or Chromium when there is another', async () => {
    assert.equal(await browserNameAndVersion(navigatorWith(FULL_VERSION_LIST)), CHROME);
    assert.equal(await browserNameAndVersion(navigatorWith([{ brand: 'Not_A Brand', version: '8.0.0.0' }, { brand: 'Chromium', version: '131.0.6778.86' }])), 'Chromium 131.0.6778.86');
    const refusing = { userAgentData: { brands: [{ brand: 'Not A(Brand', version: '99' }, { brand: 'Microsoft Edge', version: '131' }], getHighEntropyValues: async () => { throw new Error('NotAllowedError'); } } };
    assert.equal(await browserNameAndVersion(refusing), 'Microsoft Edge 131', 'Without the full version the major one from brands is used.');
    assert.equal(await browserNameAndVersion(navigatorWith([], [{ brand: 'Google Chrome', version: '131' }])), 'Google Chrome 131', 'An empty full version list falls back to brands.');
    assert.equal(await browserNameAndVersion({}), '', 'A browser that says nothing is left out, never guessed.');
    assert.equal(await browserNameAndVersion(navigatorWith([{ brand: 'Evil\r\nBrand\u001B', version: '1' }])), 'Evil Brand 1');
});

test('the active tab gives its context only once its icon was clicked', async () => {
    const browser = fakeBrowser({ tabs: [ORDER_PAGE] });
    assert.deepEqual(await activePageContext(browser, navigatorWith(FULL_VERSION_LIST)), { title: '', summary: '' });

    browser.clickIcon(ORDER_PAGE.id);
    assert.deepEqual(await activePageContext(browser, navigatorWith(FULL_VERSION_LIST)), pageContext(ORDER_PAGE, CHROME));
    assert.deepEqual(await activePageContext(fakeBrowser(), navigatorWith(FULL_VERSION_LIST)), { title: '', summary: '' }, 'No open tab, no context.');
});

test('a capture of a page whose address has ?token=… sends the address without the query', async () => {
    const pinround = fakePinround({ tickets: [{ key: 'SHOP-12', title: 'Logo is cut off', step: 'Checking', now: 'Mia checks it', is_your_turn: true, is_assignee: true, updated_at: '2026-03-01T09:00:00+00:00' }] });
    const browser = fakeBrowser({ tabs: [ORDER_PAGE] });
    browser.clickIcon(ORDER_PAGE.id);
    const context = await activePageContext(browser, navigatorWith(FULL_VERSION_LIST));

    await captureToTarget({
        api: new PinroundApi(PINROUND_ORIGIN, TOKEN, pinround.fetchFn),
        browser,
        storage: browser.storage.session,
        tabId: ORDER_PAGE.id,
        target: { kind: 'ticket', ticketKey: 'SHOP-12' },
        typed: { project: '', reportTitle: '' },
        texts: captureTexts(context.title, context.summary),
    });

    const [, path, body] = pinround.requests.at(-1);
    assert.equal(path, '/api/v1/tickets/SHOP-12/draft/screenshots');
    assert.deepEqual([body.title, body.summary], ['Bestellübersicht – [2J 注文 Acme Foods', `Page: https://shop.acme-foods.example/orders/42 · Window 1280×720 · ${CHROME}`]);
    assert.ok(!JSON.stringify(pinround.requests).includes('abc123'), 'The token in the query reaches no request.');
});
