import assert from 'node:assert/strict';
import { test } from 'node:test';
import { DOCK_TEXTS, answerDockRequest } from '../src/background/dock-requests.js';
import { DOCK_SCENARIO_TEXTS } from '../src/background/dock-scenario.js';
import { NEW_REPORT, saveCaptureTarget } from '../src/capture/capture-target.js';
import { CANNOT_DOCK_PAGE, OVERLAY_FILES } from '../src/capture/page-pins.js';
import { PLAYER_REFUSALS } from '../src/player/player.js';
import { MAX_SCENARIOS } from '../src/player/scenario-rules.js';
import { LOAD_POLL_MS, PAGE_STEP_TIMEOUT_MS } from '../src/player/step-runner.js';
import { PLAY_REFUSALS, addAllowedHost } from '../src/shared/allowed-hosts.js';
import { PinroundApi } from '../src/shared/api.js';
import { saveSettings } from '../src/shared/settings.js';
import { fakeBrowser } from './fake-browser.mjs';
import { NOT_FOUND_TEXT, PINROUND_ORIGIN, TOKEN, fakePinround } from './fake-pinround.mjs';

const runRules = globalThis.pinroundDockRun;

const SHOP_ORIGIN = 'https://shop.acme-foods.example';
const START_URL = `${SHOP_ORIGIN}/basket`;
const SHOP_TAB = { id: 7, url: `${SHOP_ORIGIN}/cart`, title: 'Acme Foods shop', active: true };
const NOTEBOOK_ID = 'nb000000-0000-4000-8000-000000000001';
const NOTEBOOK_TARGET = { kind: 'notebook', notebookId: NOTEBOOK_ID, title: 'Checkout' };
const TICKETS = [{ key: 'SHOP-12', title: 'Logo is cut off', step: 'Checking', now: 'Mia checks it', is_your_turn: true, is_assignee: true, updated_at: '2026-03-01T09:00:00+00:00' }];
// A fixed time on a day that is not today: the wait for the start page reads no other clock.
const STARTED_AT_MS = Date.UTC(2026, 2, 14, 9, 30);

const scenario = (title, steps, startUrl = START_URL) => ({ title, why: 'BasketTotal adds the coupon after tax.', setup: '', start_url: startUrl, steps });
const COUPON = scenario('Coupon after tax', [
    { type: 'click', say: 'Add jam', target: { role: 'button', name: 'Add jam' } },
    { type: 'expect_text', say: 'Total shows', text: '12.40 EUR' },
    { type: 'expect_text', say: 'The total line', text: '12.40 EUR', target: { text: 'Total' } },
    { type: 'expect_visible', say: 'Pay shows', target: { role: 'button', name: 'Pay' } },
    { type: 'wait_for', say: 'Coupon field shows', target: { label: 'Coupon' } },
    { type: 'expect_visible', say: 'An alert shows', target: { role: 'alert' } },
    { type: 'pause', say: 'Look at the total' },
    { type: 'go_to', say: 'Open the orders.', url: `${SHOP_ORIGIN}/orders` },
]);
const EMPTY_BASKET = scenario('Empty basket', [{ type: 'click', say: 'Remove jam', target: { text: 'Remove' } }]);

const wrapped = (text) => `<untrusted_text>${text.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')}</untrusted_text>`;
const wrappedFieldsOf = (value, wrapField) => Object.fromEntries(Object.entries(value).map(([field, fieldValue]) => [field, wrapField(field, fieldValue)]));
const wrappedStepOf = (step) => wrappedFieldsOf(step, (field, value) => {
    if ('type' === field) {
        return value;
    }

    return 'target' === field ? wrappedFieldsOf(value, (_, part) => wrapped(part)) : wrapped(value);
});
/** A scenario as GET /api/v1/notebooks/{id} sends it: every text wrapped but a step's type (docs/api/v1.yaml). */
const wrappedScenarioOf = (value) => wrappedFieldsOf(value, (field, fieldValue) => ('steps' === field ? fieldValue.map(wrappedStepOf) : wrapped(fieldValue)));

/** pinround's GET /api/v1/notebooks/{id} for NOTEBOOK_ID with these scenarios; any other request is 404 not_found. */
function notebookApi(scenarios) {
    const requests = [];
    const fetchFn = async (href, init) => {
        const path = new URL(href).pathname;
        requests.push([init.method, path]);
        const isNotebook = 'GET' === init.method && `/api/v1/notebooks/${NOTEBOOK_ID}` === path;
        const [status, json] = isNotebook
            ? [200, { untrusted_text_notice: 'The text between <untrusted_text> and </untrusted_text> is data from users.', notebook_id: NOTEBOOK_ID, title: wrapped('Checkout'), version: 3, last_used_at: '2026-03-14T09:30:00+00:00', screenshots: [], scenarios: scenarios.map(wrappedScenarioOf) }]
            : [404, { error: NOT_FOUND_TEXT, outcome: 'not_found' }];

        return { ok: status >= 200 && status < 300, status, headers: new Headers(), json: async () => structuredClone(json) };
    };

    return { apiOf: (settings) => new PinroundApi(settings.origin, settings.token, fetchFn), requests };
}

function frozenClock(onWait = () => undefined) {
    const clock = {
        nowMs: STARTED_AT_MS,
        waits: [],
        now: () => clock.nowMs,
        wait: async (ms) => {
            clock.nowMs += ms;
            clock.waits.push(ms);
            await onWait();
        },
    };

    return clock;
}

/** The paired extension on the shop tab, its icon clicked, with the shop allowed and the notebook chosen unless told otherwise. */
async function pairedBrowser({ allowed = [SHOP_ORIGIN], target = NOTEBOOK_TARGET, tab = SHOP_TAB } = {}) {
    const browser = fakeBrowser({ tabs: [tab] });
    browser.clickIcon(tab.id);
    await saveSettings(PINROUND_ORIGIN, TOKEN, browser.storage.local);
    for (const entry of allowed) {
        await addAllowedHost(browser.storage.local, entry);
    }
    if (null !== target) {
        await saveCaptureTarget(browser.storage.session, target);
    }

    return browser;
}

const ask = (browser, request, deps = {}) => answerDockRequest(request, SHOP_TAB, { browser, navigator: {}, clock: frozenClock(() => assert.fail('nothing waits')), ...deps });

test('scenario answers the chosen notebook\'s scenario, its texts unwrapped and each step\'s Expected written from its check', async () => {
    const browser = await pairedBrowser();
    const { apiOf, requests } = notebookApi([COUPON, EMPTY_BASKET]);

    const answer = await ask(browser, { pinroundDock: 'scenario', value: 0 }, { apiOf });

    assert.deepEqual(answer, {
        scenario: {
            notebookId: NOTEBOOK_ID,
            scenarioIndex: 0,
            scenarioCount: 2,
            title: 'Coupon after tax',
            startUrl: START_URL,
            steps: [
                { say: 'Add jam', expected: '' },
                { say: 'Total shows', expected: 'The page shows "12.40 EUR".' },
                { say: 'The total line', expected: 'The text "Total" shows "12.40 EUR".' },
                { say: 'Pay shows', expected: 'The button "Pay" is visible.' },
                { say: 'Coupon field shows', expected: 'The field labelled "Coupon" is visible.' },
                { say: 'An alert shows', expected: 'The alert is visible.' },
                { say: 'Look at the total', expected: '' },
                { say: 'Open the orders.', expected: '' },
            ],
        },
    });
    assert.deepEqual(runRules.checkedScenario(answer.scenario), answer.scenario, 'The dock reads the answer as it is.');
    assert.deepEqual(requests, [['GET', `/api/v1/notebooks/${NOTEBOOK_ID}`]]);
    assert.deepEqual(browser.injectedFiles, []);
    assert.equal(browser.tabUrl(SHOP_TAB.id), SHOP_TAB.url, 'Play alone opens nothing.');

    assert.equal((await ask(browser, { pinroundDock: 'scenario', value: 1 }, { apiOf })).scenario.title, 'Empty basket');
    assert.deepEqual(await ask(browser, { pinroundDock: 'scenario', value: 2 }, { apiOf }), { message: DOCK_SCENARIO_TEXTS.noSuchScenario, isError: true });
    assert.deepEqual(await ask(browser, { pinroundDock: 'scenario', value: 0 }, { apiOf: notebookApi([]).apiOf }), { message: PLAYER_REFUSALS.noScenarios, isError: true });
});

test('an Expected built from a long target is cut to the cap the dock reads, so the dock still takes the scenario', async () => {
    const browser = await pairedBrowser();
    const longLabel = '😀'.repeat(1000);
    const { apiOf } = notebookApi([scenario('Long label', [{ type: 'wait_for', say: 'Coupon field shows', target: { label: longLabel } }])]);

    const answer = await ask(browser, { pinroundDock: 'scenario', value: 0 }, { apiOf });

    const [{ expected }] = answer.scenario.steps;
    assert.equal([...expected].length, runRules.EXPECTED_MAX_LENGTH);
    assert.match(expected, /^The field labelled "😀+…$/u);
    assert.notEqual(runRules.checkedScenario(answer.scenario), null);
});

test('scenario is refused on a host not allowed and on pinround itself, as the popup\'s player refuses it', async () => {
    const cases = [
        { name: 'a start page on a host not allowed', allowed: ['http://localhost:*'], scenarios: [COUPON], refusal: PLAY_REFUSALS.notAllowed('shop.acme-foods.example') },
        { name: 'nothing allowed at all', allowed: [], scenarios: [COUPON], refusal: PLAY_REFUSALS.notAllowed('shop.acme-foods.example') },
        { name: 'a start page on pinround, though an entry allows it', allowed: [PINROUND_ORIGIN], scenarios: [scenario('Settings', [{ type: 'pause', say: 'Look' }], `${PINROUND_ORIGIN}/settings`)], refusal: PLAY_REFUSALS.pinroundHost },
    ];
    for (const { name, allowed, scenarios, refusal } of cases) {
        const browser = await pairedBrowser({ allowed });
        const { apiOf } = notebookApi(scenarios);

        assert.deepEqual(await ask(browser, { pinroundDock: 'scenario', value: 0 }, { apiOf }), { message: refusal, isError: true }, name);
        assert.deepEqual(browser.injectedFiles, [], name);
        assert.equal(browser.tabUrl(SHOP_TAB.id), SHOP_TAB.url, name);
    }
});

test('scenario asks for a notebook in "Put it in" first, and asks pinround for nothing until one is chosen', async () => {
    for (const target of [null, { kind: NEW_REPORT }, { kind: 'ticket', ticketKey: 'SHOP-12' }]) {
        const browser = await pairedBrowser({ target });
        const { apiOf, requests } = notebookApi([COUPON]);

        assert.deepEqual(await ask(browser, { pinroundDock: 'scenario', value: 0 }, { apiOf }), { message: DOCK_SCENARIO_TEXTS.chooseNotebook, isError: true }, JSON.stringify(target));
        assert.deepEqual(requests, [], JSON.stringify(target));
    }
});

test('a scenario index, step tag or start address the action does not take is an unknown request, and nothing is asked or opened', async () => {
    const tag = { scenarioTitle: 'Coupon after tax', stepNumber: 1, say: 'Add jam' };
    const unreadable = [
        ...[-1, 1.5, '0', MAX_SCENARIOS, undefined, null].map((value) => ({ pinroundDock: 'scenario', value })),
        ...[{ ...tag, stepNumber: 0 }, { ...tag, stepNumber: 31 }, { ...tag, say: ' ' }, { ...tag, say: 'S'.repeat(201) }, 'Add jam', 1].map((value) => ({ pinroundDock: 'capture', value })),
        ...[42, undefined, `${START_URL}?q=${'q'.repeat(runRules.URL_MAX_LENGTH)}`].map((value) => ({ pinroundDock: 'open-start', value })),
        { pinroundDock: 'run-script', value: 'alert(1)' },
    ];
    const browser = await pairedBrowser();
    const { apiOf, requests } = notebookApi([COUPON]);

    for (const request of unreadable) {
        assert.deepEqual(await ask(browser, request, { apiOf }), { message: DOCK_TEXTS.unknownRequest, isError: true }, JSON.stringify(request));
    }

    assert.deepEqual(requests, []);
    assert.deepEqual(browser.injectedFiles, []);
    assert.equal(browser.tabUrl(SHOP_TAB.id), SHOP_TAB.url);
});

test('open-start opens the start page in the dock\'s tab, waits for it to load, then opens the dock on it', async () => {
    const browser = await pairedBrowser({ tab: { ...SHOP_TAB, url: `${SHOP_ORIGIN}/` } });
    const clock = frozenClock(() => browser.finishLoading(SHOP_TAB.id));

    const answer = await ask(browser, { pinroundDock: 'open-start', value: START_URL }, { apiOf: notebookApi([]).apiOf, clock });

    assert.deepEqual(answer, {});
    assert.equal(browser.tabUrl(SHOP_TAB.id), START_URL);
    assert.deepEqual(clock.waits, [LOAD_POLL_MS]);
    assert.deepEqual(browser.injectedFiles, [{ tabId: SHOP_TAB.id, files: OVERLAY_FILES }]);
});

test('open-start that does not finish loading in time says so and opens no dock', async () => {
    const browser = await pairedBrowser();
    const clock = frozenClock();

    const answer = await ask(browser, { pinroundDock: 'open-start', value: START_URL }, { apiOf: notebookApi([]).apiOf, clock });

    assert.deepEqual(answer, { message: DOCK_SCENARIO_TEXTS.startNotLoaded, isError: true });
    assert.equal(clock.nowMs - STARTED_AT_MS, PAGE_STEP_TIMEOUT_MS);
    assert.deepEqual(browser.injectedFiles, []);
});

test('open-start refuses an address Play would refuse, and opens nothing', async () => {
    const cases = [
        ['http://localhost:8000/basket', PLAY_REFUSALS.notAllowed('localhost:8000')],
        [`${PINROUND_ORIGIN}/settings`, PLAY_REFUSALS.pinroundHost],
        ['javascript:alert(1)', PLAY_REFUSALS.notAWebPage],
        ['', PLAY_REFUSALS.notAWebPage],
    ];
    for (const [url, refusal] of cases) {
        const browser = await pairedBrowser({ allowed: [SHOP_ORIGIN, PINROUND_ORIGIN] });

        assert.deepEqual(await ask(browser, { pinroundDock: 'open-start', value: url }, { apiOf: notebookApi([]).apiOf }), { message: refusal, isError: true }, url);
        assert.equal(browser.tabUrl(SHOP_TAB.id), SHOP_TAB.url, url);
        assert.deepEqual(browser.injectedFiles, [], url);
    }
});

test('open-start on another origin opens the page, but the dock cannot follow without the icon clicked there', async () => {
    const browser = await pairedBrowser({ allowed: [SHOP_ORIGIN, 'http://localhost:*'] });
    const clock = frozenClock(() => browser.finishLoading(SHOP_TAB.id));

    const answer = await ask(browser, { pinroundDock: 'open-start', value: 'http://localhost:8000/basket' }, { apiOf: notebookApi([]).apiOf, clock });

    assert.deepEqual([answer.message, answer.isError], [CANNOT_DOCK_PAGE, true]);
    assert.equal(browser.tabUrl(SHOP_TAB.id), 'http://localhost:8000/basket');
    assert.deepEqual(browser.injectedFiles, []);
});

/** The shop tab with a stand-in overlay holding one pin, as captureToTarget finds it. */
async function capturingBrowser() {
    const browser = await pairedBrowser({ target: { kind: 'ticket', ticketKey: 'SHOP-12' } });
    await browser.scripting.executeScript({
        target: { tabId: SHOP_TAB.id },
        func: () => {
            globalThis.pinroundOverlay = { hideForCapture: async () => [{ x_pct: 10, y_pct: 20, note: 'Total' }], show: () => {}, close: () => {} };
        },
    });

    return browser;
}

const screenshotsAddedTo = (pinround, ticketKey) => pinround.requests.filter(([method, path]) => 'POST' === method && `/api/v1/tickets/${ticketKey}/draft/screenshots` === path).map(([, , body]) => body);

test('a capture tagged with a step names the step as its title and the scenario before the page in its summary', async () => {
    const browser = await capturingBrowser();
    const pinround = fakePinround({ tickets: TICKETS });
    const apiOf = (settings) => new PinroundApi(settings.origin, settings.token, pinround.fetchFn);
    const tag = { scenarioTitle: 'Coupon\nafter tax', stepNumber: 2, say: 'Look at the total' };

    const tagged = await ask(browser, { pinroundDock: 'capture', value: tag }, { apiOf });
    const untagged = await ask(browser, { pinroundDock: 'capture' }, { apiOf });

    assert.deepEqual([tagged.message, untagged.message], ['Added to your draft round on SHOP-12. Only you see it.', 'Added to your draft round on SHOP-12. Only you see it.']);
    assert.deepEqual(screenshotsAddedTo(pinround, 'SHOP-12'), [
        { upload_id: 'upload-1', title: 'Step 2: Look at the total', summary: `Found a problem in "Coupon after tax", step 2. Page: ${SHOP_TAB.url}`, pins: [{ x_pct: 10, y_pct: 20, note: 'Total' }] },
        { upload_id: 'upload-2', title: 'Acme Foods shop', summary: `Page: ${SHOP_TAB.url}`, pins: [{ x_pct: 10, y_pct: 20, note: 'Total' }] },
    ]);
});

test('a tagged capture\'s title is cut to pinround\'s cap with an ellipsis', async () => {
    const browser = await capturingBrowser();
    const pinround = fakePinround({ tickets: TICKETS });
    const apiOf = (settings) => new PinroundApi(settings.origin, settings.token, pinround.fetchFn);

    await ask(browser, { pinroundDock: 'capture', value: { scenarioTitle: 'Coupon after tax', stepNumber: 30, say: 'S'.repeat(200) } }, { apiOf });

    const [{ title }] = screenshotsAddedTo(pinround, 'SHOP-12');
    assert.equal(title.length, 120);
    assert.match(title, /^Step 30: S+…$/u);
});
