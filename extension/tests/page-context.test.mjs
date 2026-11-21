import assert from 'node:assert/strict';
import { test } from 'node:test';
import { PinroundApi } from '../src/shared/api.js';
import { TITLE_MAX_LENGTH, captureTexts } from '../src/capture/capture.js';
import { captureToTarget } from '../src/capture/capture-to-target.js';
import { PAGE_TEXT_READ_MAX_LENGTH, activePageTitle, cutToFit, oneLinePageText, pageTitleOf } from '../src/capture/page-context.js';
import { fakeBrowser } from './fake-browser.mjs';
import { PINROUND_ORIGIN, TOKEN, fakePinround } from './fake-pinround.mjs';

const ORDER_PAGE = {
    id: 7,
    url: 'https://omar:s3cret@shop.acme-foods.example/orders/42?token=abc123&x=1#frag',
    title: '\uFEFFBestellübersicht\r\n–\u001B[2J 注文\u2028\u2029Acme Foods\r\n',
    width: 1280,
    height: 720,
    active: true,
};

test('page text loses its control characters, BOM and direction overrides, and its white space collapses', () => {
    assert.equal(oneLinePageText(ORDER_PAGE.title), 'Bestellübersicht – [2J 注文 Acme Foods');
    assert.equal(oneLinePageText('Bestellübersicht – 注文\r\n'), 'Bestellübersicht – 注文', 'A final CRLF goes.');
    assert.equal(oneLinePageText('Bestellübersicht – 注文'), 'Bestellübersicht – 注文', 'No final newline changes nothing.');
    assert.equal(oneLinePageText('Cart\u202Egnp.exe\u2066 total\u0085\t\tdue'), 'Cartgnp.exe total due');
    assert.equal(oneLinePageText(' \r\n\uFEFF '), '');
    assert.equal(oneLinePageText('Half \uD83D'), 'Half \uFFFD', 'A lone surrogate becomes a replacement character, which JSON carries as it is.');
    assert.equal(oneLinePageText(`${' '.repeat(PAGE_TEXT_READ_MAX_LENGTH)}Never read`), '', 'Nothing past the read cap is looked at.');
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

test('the title starts from the page title on one line and within the cap; a tab out of reach gives none', () => {
    assert.equal(pageTitleOf(ORDER_PAGE), 'Bestellübersicht – [2J 注文 Acme Foods');
    assert.equal(pageTitleOf({ id: 7, title: 'Hidden', width: 1280, height: 720 }), '', 'A tab whose address the extension may not read gives no title.');
    const longTitle = pageTitleOf({ ...ORDER_PAGE, title: 'Größe '.repeat(100) });
    assert.equal(longTitle, `${'Größe '.repeat(19)}Größe…`);
    assert.equal(longTitle.length, TITLE_MAX_LENGTH);
});

test('the active tab gives its title only once its icon was clicked', async () => {
    const browser = fakeBrowser({ tabs: [ORDER_PAGE] });
    assert.equal(await activePageTitle(browser), '');

    browser.clickIcon(ORDER_PAGE.id);
    assert.equal(await activePageTitle(browser), 'Bestellübersicht – [2J 注文 Acme Foods');
    assert.equal(await activePageTitle(fakeBrowser()), '', 'No open tab, no title.');
});

test('a capture sends the page title and no summary: no address, window size or browser reaches pinround', async () => {
    const pinround = fakePinround({ tickets: [{ key: 'SHOP-12', title: 'Logo is cut off', step: 'Checking', now: 'Mia checks it', is_your_turn: true, is_assignee: true, updated_at: '2026-03-01T09:00:00+00:00' }] });
    const browser = fakeBrowser({ tabs: [ORDER_PAGE] });
    browser.clickIcon(ORDER_PAGE.id);

    await captureToTarget({
        api: new PinroundApi(PINROUND_ORIGIN, TOKEN, pinround.fetchFn),
        browser,
        storage: browser.storage.session,
        tabId: ORDER_PAGE.id,
        target: { kind: 'ticket', ticketKey: 'SHOP-12' },
        typed: { project: '', reportTitle: '' },
        texts: captureTexts(await activePageTitle(browser), ''),
    });

    const [, path, body] = pinround.requests.at(-1);
    assert.equal(path, '/api/v1/tickets/SHOP-12/draft/screenshots');
    assert.deepEqual(Object.keys(body).sort(), ['pins', 'title', 'upload_id']);
    assert.equal(body.title, 'Bestellübersicht – [2J 注文 Acme Foods');
    const sent = JSON.stringify(pinround.requests);
    for (const left of ['shop.acme-foods.example/orders', '1280', 'Chrome']) {
        assert.ok(!sent.includes(left), left);
    }
});
