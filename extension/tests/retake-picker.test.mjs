import assert from 'node:assert/strict';
import { test } from 'node:test';
import { PinroundApi } from '../src/shared/api.js';
import { RetakePicker } from '../src/popup/retake-picker.js';
import { PINROUND_ORIGIN, TOKEN, fakePinround } from './fake-pinround.mjs';

const TICKETS = [{ key: 'SHOP-7' }, { key: 'SHOP-12' }];
const SCREENSHOT_ID = (number) => `sc000000-0000-4000-8000-${String(number).padStart(12, '0')}`;
const DRAFT_ROUNDS = [
    { ticketKey: 'SHOP-12', screenshots: [{ screenshot_id: SCREENSHOT_ID(1), title: '<b>Basket</b>\r\n', try_count: 2 }, { screenshot_id: SCREENSHOT_ID(2), title: null }] },
    { ticketKey: 'SHOP-7', screenshots: [{ screenshot_id: SCREENSHOT_ID(7), title: 'Total' }] },
];
const SHOP_12 = { kind: 'ticket', ticketKey: 'SHOP-12' };
const SHOP_7 = { kind: 'ticket', ticketKey: 'SHOP-7' };
const NOTEBOOK = { kind: 'notebook', notebookId: 'nb000000-0000-4000-8000-000000000001', title: 'Checkout' };

/** A <select> as the browser has it: setting a value no option has selects nothing, and the value reads ''. */
class FakeSelect {
    options = [];
    #selected = null;

    replaceChildren(...options) {
        this.options = options;
        this.#selected = options[0] ?? null;
    }

    get value() {
        return this.#selected?.value ?? '';
    }

    set value(value) {
        this.#selected = this.options.find((option) => value === option.value) ?? null;
    }
}

function popupOf(pinround) {
    const elements = { 'retake-fields': { hidden: true }, 'retake-of': new FakeSelect() };
    // An option keeps its label as text only, as new Option(label, value) does.
    const picker = new RetakePicker((id) => elements[id], new PinroundApi(PINROUND_ORIGIN, TOKEN, pinround.fetchFn), (label, value) => ({ text: label, value }));

    return { picker, fields: elements['retake-fields'], select: elements['retake-of'] };
}

const shownOptionsOf = (select) => select.options.map((option) => [option.text, option.value]);

test('a draft round target shows New screenshot, chosen, then one retake per screenshot of my draft, labels as text', async () => {
    const pinround = fakePinround({ tickets: TICKETS, draftRounds: DRAFT_ROUNDS });
    const { picker, fields, select } = popupOf(pinround);

    assert.equal(await picker.showFor(SHOP_12), null);

    assert.equal(fields.hidden, false);
    assert.deepEqual(shownOptionsOf(select), [['New screenshot', ''], ['Retake of screenshot 1 · <b>Basket</b>\\u000D\\u000A', SCREENSHOT_ID(1)], ['Retake of screenshot 2', SCREENSHOT_ID(2)]]);
    assert.equal(select.value, '');
    assert.equal(picker.retakeOfFor(SHOP_12), null);
});

test('a chosen retake is sent only for the ticket the list is of', async () => {
    const { picker, select } = popupOf(fakePinround({ tickets: TICKETS, draftRounds: DRAFT_ROUNDS }));
    await picker.showFor(SHOP_12);

    select.value = SCREENSHOT_ID(2);

    assert.equal(picker.retakeOfFor(SHOP_12), SCREENSHOT_ID(2));
    assert.equal(picker.retakeOfFor(SHOP_7), null);
    assert.equal(picker.retakeOfFor(NOTEBOOK), null);
    assert.equal(picker.retakeOfFor({ kind: 'new-report' }), null);
});

test('any target but a draft round, or none, hides the picker with only New screenshot and asks pinround nothing', async () => {
    const pinround = fakePinround({ tickets: TICKETS, draftRounds: DRAFT_ROUNDS });
    const { picker, fields, select } = popupOf(pinround);
    await picker.showFor(SHOP_12);
    select.value = SCREENSHOT_ID(1);
    const requestsBefore = pinround.requests.length;

    for (const target of [NOTEBOOK, { kind: 'new-report' }, undefined]) {
        assert.equal(await picker.showFor(target), null);
        assert.equal(fields.hidden, true);
        assert.deepEqual(shownOptionsOf(select), [['New screenshot', '']]);
        assert.equal(picker.retakeOfFor(SHOP_12), null);
    }
    assert.equal(pinround.requests.length, requestsBefore);
});

test('a list that fails leaves New screenshot only, answers why, and sends no retake', async () => {
    const pinround = fakePinround({ tickets: TICKETS, draftRounds: DRAFT_ROUNDS });
    pinround.failOnServer('/api/v1/tickets/SHOP-12/draft/screenshots');
    const { picker, fields, select } = popupOf(pinround);

    assert.equal(await picker.showFor(SHOP_12), 'Your screenshots to retake could not be loaded. Something went wrong on our side.');

    assert.equal(fields.hidden, false);
    assert.deepEqual(shownOptionsOf(select), [['New screenshot', '']]);
    assert.equal(picker.retakeOfFor(SHOP_12), null);
});

test('a reload keeps the kept retake while the list still offers it, and falls back to New screenshot otherwise', async () => {
    const pinround = fakePinround({ tickets: TICKETS, draftRounds: DRAFT_ROUNDS });
    const { picker, select } = popupOf(pinround);

    await picker.showFor(SHOP_12, SCREENSHOT_ID(2));
    assert.equal(picker.retakeOfFor(SHOP_12), SCREENSHOT_ID(2));

    pinround.state.draftRounds[0].chains.pop();
    await picker.showFor(SHOP_12, SCREENSHOT_ID(2));
    assert.equal(select.value, '');
    assert.equal(picker.retakeOfFor(SHOP_12), null);
});

test('a list that answers after the person chose another target is dropped', async () => {
    const pinround = fakePinround({ tickets: TICKETS, draftRounds: DRAFT_ROUNDS });
    const { picker, fields, select } = popupOf(pinround);

    const forShop12 = picker.showFor(SHOP_12);
    const forNotebook = picker.showFor(NOTEBOOK);
    assert.deepEqual(await Promise.all([forShop12, forNotebook]), [null, null]);

    assert.equal(fields.hidden, true);
    assert.deepEqual(shownOptionsOf(select), [['New screenshot', '']]);
    assert.equal(picker.retakeOfFor(SHOP_12), null);

    const forShop7 = picker.showFor(SHOP_7);
    await picker.showFor(SHOP_12);
    await forShop7;
    assert.deepEqual(shownOptionsOf(select).map(([, value]) => value), ['', SCREENSHOT_ID(1), SCREENSHOT_ID(2)]);
    select.value = SCREENSHOT_ID(1);
    assert.equal(picker.retakeOfFor(SHOP_12), SCREENSHOT_ID(1));
});
