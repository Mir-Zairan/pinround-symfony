import assert from 'node:assert/strict';
import { test } from 'node:test';
import { NOTEBOOK_PLAYER_TEXTS, NotebookPlayer, loadNotebookScenarios } from '../src/player/notebook-player.js';
import { PLAYER_REFUSALS } from '../src/player/player.js';
import { MAX_SCENARIOS, REFUSALS } from '../src/player/scenario-rules.js';
import { PLAY_REFUSALS, addAllowedHost, confirmSite, isConfirmedSite, siteOf } from '../src/shared/allowed-hosts.js';
import { ApiError, PinroundApi } from '../src/shared/api.js';
import { fakeBrowser } from './fake-browser.mjs';

const PINROUND_ORIGIN = 'https://pinround.acme-foods.example';
const TOKEN = 'pr_abcdefghijklmnopqrstuvwxyz012345';
const NOT_FOUND_TEXT = 'Not found. It does not exist, or you may not see it.';
const NOTEBOOK_ID = '0b6f3c1e-8f0a-4c5d-9e2b-7a1d3f5c9b20';
const START_URL = 'http://localhost:8000/basket';
const ORDERS_URL = 'http://localhost:8000/orders';
const SHOP_TAB = { id: 7, url: START_URL, title: 'Acme Foods shop', active: true };
// A fixed time on a day that is not today: the player's runner reads no other clock.
const STARTED_AT_MS = Date.UTC(2026, 2, 14, 9, 30);

const click = (say) => ({ type: 'click', say, target: { role: 'button', name: say } });
const pause = (say) => ({ type: 'pause', say });
const expectText = (say, text) => ({ type: 'expect_text', say, text });
const scenario = (title, steps) => ({ title, why: 'BasketTotal adds the coupon after tax.', setup: '', start_url: START_URL, steps });

const THREE_SCENARIOS = [
    scenario('Coupon after tax', [click('Add jam'), pause('Look at the total'), expectText('Total shows', '12.40 EUR'), click('Pay')]),
    scenario('Empty basket', [click('Remove jam'), click('Remove bread')]),
    scenario('Two coupons', [click('Apply coupon')]),
];

const FAILED_TOTAL = { stopped: true, message: 'The page does not show the expected text.', expected: '12.40 EUR', found: 'Total 11.90 EUR' };

function frozenClock(onWait = () => undefined) {
    const clock = {
        nowMs: STARTED_AT_MS,
        waits: [],
        now: () => clock.nowMs,
        wait: async (ms) => {
            clock.nowMs += ms;
            clock.waits.push(ms);
            await onWait(clock.waits.length);
        },
    };

    return clock;
}

/** A stand-in page player in the tab's current document: it answers each step by its say line and counts clears. */
async function givePagePlayer(fake, answersBySay = {}) {
    await fake.scripting.executeScript({
        target: { tabId: SHOP_TAB.id },
        args: [JSON.stringify(answersBySay)],
        func: (source) => {
            const answers = JSON.parse(source);
            globalThis.stepsRun = [];
            globalThis.highlightsCleared = 0;
            globalThis.pinroundPagePlayer = {
                runStep(step) {
                    globalThis.stepsRun.push(step.say);

                    return answers[step.say] ?? { ok: true };
                },
                clearHighlight() {
                    globalThis.highlightsCleared += 1;
                },
            };
        },
    });
}

const pageOf = async (fake) => (await fake.scripting.executeScript({
    target: { tabId: SHOP_TAB.id },
    func: () => ({ stepsRun: globalThis.stepsRun, highlightsCleared: globalThis.highlightsCleared }),
}))[0].result;

/** The fake with every injection and tabs.update recorded, as the player calls them. */
function watched(fake) {
    const calls = { injections: [], updates: [] };
    const browser = {
        ...fake,
        tabs: {
            ...fake.tabs,
            update(tabId, properties) {
                calls.updates.push(properties?.url);

                return fake.tabs.update(tabId, properties);
            },
        },
        scripting: {
            executeScript(injection) {
                calls.injections.push(undefined !== injection.files ? 'player files' : (0 < (injection.args ?? []).length ? 'step' : 'clear highlight'));

                return fake.scripting.executeScript(injection);
            },
        },
    };

    return { browser, calls };
}

async function playerOf({
    scenarios = THREE_SCENARIOS,
    allowed = ['http://localhost:*'],
    tab = SHOP_TAB,
    pinroundOrigin = PINROUND_ORIGIN,
    answersBySay = {},
    playAnyway = () => true,
    onStepStart = () => undefined,
    clock = frozenClock(),
    isIconClicked = true,
    hostOrigins = [],
    activeTabId = async () => tab.id,
} = {}) {
    const fake = fakeBrowser({ tabs: [tab], hostOrigins });
    if (isIconClicked) {
        fake.clickIcon(tab.id);
        await givePagePlayer(fake, answersBySay);
    }
    const storage = fake.storage.local;
    for (const entry of allowed) {
        await addAllowedHost(storage, entry);
    }
    const { browser, calls } = watched(fake);
    const asked = [];
    const captures = [];
    const holder = {};
    const made = NotebookPlayer.of(scenarios, {
        browser,
        storage,
        pinroundOrigin,
        activeTabId,
        askToPlay: async (host) => {
            asked.push(host);

            return playAnyway(host);
        },
        captureToNotebook: async (tabId, texts) => {
            captures.push({ tabId, texts });
        },
        onStepStart: (step) => onStepStart(step, holder.player),
        clock,
    });
    assert.equal(made.refusal, undefined);
    holder.player = made.player;

    return { fake, player: made.player, calls, asked, captures, storage };
}

const positionOf = (player) => ({ scenario: player.scenarioIndex, say: player.say, lastStop: player.lastStop, isDone: player.isDone, isRunning: player.isRunning });

test('play asks once for the host, runs to the pause keeping the highlight, then to the end, where the highlight goes', async () => {
    const { fake, player, asked, storage } = await playerOf();

    assert.deepEqual(await player.play(), { ended: 'paused' });
    assert.deepEqual(asked, ['localhost:8000']);
    assert.equal(await isConfirmedSite(storage, siteOf(START_URL)), true);
    assert.deepEqual(await pageOf(fake), { stepsRun: ['Add jam'], highlightsCleared: 0 });
    assert.equal(player.say, 'Look at the total');

    assert.deepEqual(await player.play(), { ended: 'done' });
    assert.deepEqual(asked, ['localhost:8000']);
    assert.deepEqual(await pageOf(fake), { stepsRun: ['Add jam', 'Total shows', 'Pay'], highlightsCleared: 1 });
    assert.equal(player.isDone, true);

    assert.deepEqual(await player.play(), { ended: 'paused' }, 'Play at the end plays the scenario again from its start.');
    assert.deepEqual(asked, ['localhost:8000']);
    assert.deepEqual((await pageOf(fake)).stepsRun, ['Add jam', 'Total shows', 'Pay', 'Add jam']);
});

test('step runs one step at a time and keeps the highlight until the scenario is done', async () => {
    const { fake, player } = await playerOf();
    await player.next();

    assert.deepEqual(await player.step(), { ended: 'stepped' });
    assert.deepEqual(await pageOf(fake), { stepsRun: ['Remove jam'], highlightsCleared: 0 });
    assert.equal(player.say, 'Remove bread');

    assert.deepEqual(await player.step(), { ended: 'done' });
    assert.deepEqual(await pageOf(fake), { stepsRun: ['Remove jam', 'Remove bread'], highlightsCleared: 1 });
});

test('a failed expect_text stops the run with both texts, runs no later step, and clears the highlight', async () => {
    const { fake, player } = await playerOf({ answersBySay: { 'Total shows': FAILED_TOTAL } });
    await player.play();

    assert.deepEqual(await player.play(), { ended: 'stopped' });

    assert.deepEqual(player.lastStop, { stepIndex: 2, message: FAILED_TOTAL.message, expected: '12.40 EUR', found: 'Total 11.90 EUR' });
    assert.deepEqual(await pageOf(fake), { stepsRun: ['Add jam', 'Total shows'], highlightsCleared: 1 });
    assert.equal(player.say, 'Total shows');
});

test('stop ends the run before its next step, clears the highlight, and play goes on from that step', async () => {
    const { fake, player } = await playerOf({
        onStepStart: (step, notebookPlayer) => {
            if ('Remove jam' === step.say) {
                notebookPlayer.stop();
            }
        },
    });
    await player.next();

    assert.deepEqual(await player.play(), { ended: 'stopped' });
    assert.deepEqual(player.lastStop, { stepIndex: 1, message: NOTEBOOK_PLAYER_TEXTS.stopped });
    assert.deepEqual(await pageOf(fake), { stepsRun: ['Remove jam'], highlightsCleared: 1 });

    player.stop();
    assert.deepEqual(await player.play(), { ended: 'done' }, 'A stop pressed with no run going stops nothing later.');
    assert.deepEqual((await pageOf(fake)).stepsRun, ['Remove jam', 'Remove bread']);
});

test('next and back clear the highlight before they move, and are refused while a run goes', async () => {
    const movesDuringRun = [];
    const { fake, player } = await playerOf({
        onStepStart: (step, notebookPlayer) => {
            movesDuringRun.push(notebookPlayer.next(), notebookPlayer.back());
        },
    });
    assert.deepEqual(await player.play(), { ended: 'paused' });
    assert.deepEqual(await Promise.all(movesDuringRun), [{ refused: PLAYER_REFUSALS.busy }, { refused: PLAYER_REFUSALS.busy }]);
    assert.equal(player.scenarioIndex, 0);
    assert.equal((await pageOf(fake)).highlightsCleared, 0);

    assert.deepEqual(await player.next(), { moved: true });
    assert.equal(player.scenarioIndex, 1);
    assert.equal((await pageOf(fake)).highlightsCleared, 1);

    assert.deepEqual(await player.back(), { moved: true });
    assert.equal(player.scenarioIndex, 0);
    assert.equal((await pageOf(fake)).highlightsCleared, 2);

    assert.deepEqual(await player.back(), { refused: PLAYER_REFUSALS.firstScenario });
    assert.equal(player.scenarioIndex, 0);
});

test('close clears the highlight a paused run left on the page', async () => {
    const { fake, player } = await playerOf();
    assert.deepEqual(await player.play(), { ended: 'paused' });
    assert.equal((await pageOf(fake)).highlightsCleared, 0);

    await player.close();

    assert.equal((await pageOf(fake)).highlightsCleared, 1);
});

test('close during a run stops it before its next step', async () => {
    const { fake, player } = await playerOf({
        onStepStart: (step, notebookPlayer) => {
            if ('Remove jam' === step.say) {
                notebookPlayer.close();
            }
        },
    });
    await player.next();

    assert.deepEqual(await player.play(), { ended: 'stopped' });
    assert.deepEqual((await pageOf(fake)).stepsRun, ['Remove jam']);
});

const REFUSED_RUNS = [
    { name: 'a start_url on a host not allowed', options: { allowed: ['http://localhost:3000'], tab: { ...SHOP_TAB, url: 'http://localhost:3000/' } }, refusal: PLAY_REFUSALS.notAllowed('localhost:8000'), asked: [] },
    { name: 'a start_url on pinround, allowed or not', options: { pinroundOrigin: 'http://localhost:8000', tab: { ...SHOP_TAB, url: 'http://localhost:3000/' } }, refusal: PLAY_REFUSALS.pinroundHost, asked: [] },
    { name: 'nothing allowed at all', options: { allowed: [] }, refusal: PLAY_REFUSALS.notAllowed('localhost:8000'), asked: [] },
    { name: 'a tab on a host not allowed', options: { tab: { ...SHOP_TAB, url: 'https://shop.acme-foods.test/basket' } }, refusal: PLAY_REFUSALS.notAllowed('shop.acme-foods.test'), asked: [] },
    {
        name: 'a tab on pinround, though an entry allows it',
        options: { allowed: ['http://localhost:*', PINROUND_ORIGIN], tab: { ...SHOP_TAB, url: `${PINROUND_ORIGIN}/notebooks` } },
        refusal: PLAY_REFUSALS.pinroundHost,
        asked: [],
    },
    {
        name: 'a tab loading a page on a host not allowed',
        options: { tab: { ...SHOP_TAB, status: 'loading', pendingUrl: 'https://shop.acme-foods.test/' } },
        refusal: PLAY_REFUSALS.notAllowed('shop.acme-foods.test'),
        asked: [],
    },
    { name: 'a tab whose address the extension may not see', options: { isIconClicked: false }, refusal: PLAY_REFUSALS.notAWebPage, asked: [] },
    { name: 'a tab closed after the popup found it', options: { activeTabId: async () => SHOP_TAB.id + 1 }, refusal: NOTEBOOK_PLAYER_TEXTS.tabClosed, asked: [] },
    { name: 'a host the person did not say "Play anyway" for', options: { playAnyway: () => false }, refusal: NOTEBOOK_PLAYER_TEXTS.notPlayed, asked: ['localhost:8000', 'localhost:8000'] },
    {
        name: 'a second host, the tab\'s, the person said no to',
        options: { tab: { ...SHOP_TAB, url: 'http://localhost:3000/' }, playAnyway: (host) => 'localhost:8000' === host },
        refusal: NOTEBOOK_PLAYER_TEXTS.notPlayed,
        asked: ['localhost:8000', 'localhost:3000', 'localhost:3000'],
    },
    {
        name: 'a page the tab is loading on an allowed host the person did not say "Play anyway" for',
        options: { tab: { ...SHOP_TAB, status: 'loading', pendingUrl: 'http://localhost:3000/' }, playAnyway: (host) => 'localhost:8000' === host },
        refusal: NOTEBOOK_PLAYER_TEXTS.notPlayed,
        asked: ['localhost:8000', 'localhost:3000', 'localhost:3000'],
    },
];

for (const { name, options, refusal, asked: expectedQuestions } of REFUSED_RUNS) {
    test(`play and step are refused for ${name}: no step runs, nothing is injected or opened, and nothing moves`, async () => {
        const { fake, player, calls, asked, captures, storage } = await playerOf(options);
        const before = positionOf(player);

        assert.deepEqual(await player.play(), { refused: refusal });
        assert.deepEqual(await player.step(), { refused: refusal });

        assert.deepEqual(positionOf(player), before);
        assert.deepEqual(before, { scenario: 0, say: 'Add jam', lastStop: null, isDone: false, isRunning: false });
        assert.deepEqual(calls.injections, []);
        assert.deepEqual(calls.updates, []);
        assert.deepEqual(captures, []);
        assert.deepEqual(asked, expectedQuestions, 'asked over both runs');
        if (false !== options.isIconClicked) {
            assert.deepEqual((await pageOf(fake)).stepsRun, []);
        }
        assert.equal(await isConfirmedSite(storage, siteOf('http://localhost:3000/')), false);
        assert.equal(await player.next().then(() => calls.injections.length), 0, 'No run started, so there is no highlight to clear.');
    });
}

test('a step whose page has moved to a host not allowed stops before anything is injected into it', async () => {
    let injectionsBeforeStep = null;
    const { fake, player, calls } = await playerOf({
        onStepStart: (step) => {
            if ('Remove bread' === step.say) {
                fake.navigate(SHOP_TAB.id, 'https://shop.acme-foods.test/');
                fake.finishLoading(SHOP_TAB.id);
                injectionsBeforeStep = calls.injections.length;
            }
        },
    });
    await player.next();

    assert.deepEqual(await player.play(), { ended: 'stopped' });

    // Leaving the origin ends activeTab, so tabs.get no longer shows the address: no web page the player may act on.
    assert.deepEqual(player.lastStop, { stepIndex: 1, message: PLAY_REFUSALS.notAWebPage });
    assert.notEqual(injectionsBeforeStep, null);
    assert.deepEqual(calls.injections.slice(injectionsBeforeStep).filter((injection) => 'clear highlight' !== injection), []);
});

/**
 * player.next() moves to the second scenario, 'Empty basket'; when its 'Remove bread' starts, the tab moves to
 * OTHER_SHOP_URL as if 'Remove jam' had taken it there. localhost:* allows that host, so only the yes can stop the step.
 */
const OTHER_SHOP_URL = 'http://localhost:3000/basket';
async function playerMovedToOtherShop({ isOtherShopConfirmed }) {
    const moved = { injectionsBefore: null };
    const made = await playerOf({
        hostOrigins: ['http://localhost:3000'],
        onStepStart: (step) => {
            if ('Remove bread' === step.say) {
                made.fake.navigate(SHOP_TAB.id, OTHER_SHOP_URL);
                made.fake.finishLoading(SHOP_TAB.id);
                moved.injectionsBefore = made.calls.injections.length;
                moved.pagePlayerGiven = givePagePlayer(made.fake);
            }
        },
    });
    if (isOtherShopConfirmed) {
        await confirmSite(made.storage, siteOf(OTHER_SHOP_URL));
    }
    await made.player.next();

    return { ...made, moved };
}

test('a step whose page a click took to an allowed host nobody said yes to stops there, names it and runs nothing', async () => {
    const { player, calls, asked, moved, fake, storage } = await playerMovedToOtherShop({ isOtherShopConfirmed: false });

    assert.deepEqual(await player.play(), { ended: 'stopped' });

    assert.deepEqual(asked, ['localhost:8000'], 'A run cannot stop to ask about the host it landed on.');
    assert.deepEqual(player.lastStop, { stepIndex: 1, message: 'Stopped: this page is on localhost:3000, which you have not said yes to.' });
    assert.equal(player.isRunning, false);
    await moved.pagePlayerGiven;
    assert.deepEqual(calls.injections.slice(moved.injectionsBefore).filter((injection) => 'clear highlight' !== injection), []);
    assert.deepEqual((await pageOf(fake)).stepsRun, []);
    assert.equal(await isConfirmedSite(storage, siteOf(OTHER_SHOP_URL)), false);
});

test('a step whose page a click took to an allowed host the person said yes to before runs there', async () => {
    const { player, moved, fake } = await playerMovedToOtherShop({ isOtherShopConfirmed: true });

    assert.deepEqual(await player.play(), { ended: 'done' });

    await moved.pagePlayerGiven;
    assert.deepEqual((await pageOf(fake)).stepsRun, ['Remove bread']);
});

test('go_to opens its address through tabs.update, and the next step runs once the new page has loaded', async () => {
    const fakeHolder = {};
    const clock = frozenClock(async () => {
        fakeHolder.fake.finishLoading(SHOP_TAB.id);
        await givePagePlayer(fakeHolder.fake);
    });
    const goToOrders = scenario('Orders', [{ type: 'go_to', say: 'Open the orders.', url: ORDERS_URL }, expectText('Orders show', 'Orders')]);
    const { fake, player, calls } = await playerOf({ scenarios: [goToOrders], clock });
    fakeHolder.fake = fake;

    assert.deepEqual(await player.play(), { ended: 'done' });

    assert.deepEqual(calls.updates, [ORDERS_URL]);
    assert.equal(fake.tabUrl(SHOP_TAB.id), ORDERS_URL);
    assert.deepEqual(clock.waits, [100]);
    assert.deepEqual((await pageOf(fake)).stepsRun, ['Orders show']);
});

// This rule is what keeps a go_to inside the host question: the go_to urls #refusalBeforeRun also asks about are a
// backup no parsed scenario can reach. of() refuses before storage or tabs are touched.
test('a go_to to a second allowed host is refused as broken before any host is asked about or any step runs', async () => {
    const fake = fakeBrowser({ tabs: [SHOP_TAB] });
    await addAllowedHost(fake.storage.local, 'http://localhost:*');
    const goToOtherShop = scenario('Other shop', [click('Add jam'), { type: 'go_to', say: 'Open the other shop.', url: 'http://localhost:3000/orders' }]);
    const deps = { browser: fake, storage: fake.storage.local, pinroundOrigin: PINROUND_ORIGIN, activeTabId: async () => SHOP_TAB.id, askToPlay: async () => assert.fail('nothing to ask'), captureToNotebook: async () => assert.fail('no capture') };

    assert.deepEqual(NotebookPlayer.of([goToOtherShop], deps), { refusal: NOTEBOOK_PLAYER_TEXTS.brokenScenarios(`Scenario 1, step 2: ${REFUSALS.goToOtherHost}`) });
});

test('capture sends the scenario title and the say line to the notebook', async () => {
    const withCapture = scenario('Coupon <b> & tax', [{ type: 'capture', say: 'Keep the total.' }, click('Pay')]);
    const { player, captures } = await playerOf({ scenarios: [withCapture] });

    assert.deepEqual(await player.step(), { ended: 'stepped' });

    assert.deepEqual(captures, [{ tabId: SHOP_TAB.id, texts: { title: 'Coupon <b> & tax', summary: 'Keep the total.' } }]);
});

test('scenarios that break a rule are refused as broken with the rule named; an empty list has no scenarios', async () => {
    const fake = fakeBrowser({ tabs: [SHOP_TAB] });
    const deps = { browser: fake, storage: fake.storage.local, pinroundOrigin: PINROUND_ORIGIN, activeTabId: async () => SHOP_TAB.id, askToPlay: async () => assert.fail('nothing to ask'), captureToNotebook: async () => assert.fail('no capture') };

    assert.deepEqual(NotebookPlayer.of([], deps), { refusal: PLAYER_REFUSALS.noScenarios });
    assert.deepEqual(NotebookPlayer.of(undefined, deps), { refusal: 'The scenarios in this notebook cannot be played. Send the scenarios as a list. Ask Claude Code to save them again.' });
    assert.deepEqual(NotebookPlayer.of([scenario('Pay', [{ type: 'click', say: 'Pay', target: { css: '#pay' } }])], deps), {
        refusal: NOTEBOOK_PLAYER_TEXTS.brokenScenarios(`Scenario 1, step 1: ${REFUSALS.target}`),
    });
    assert.deepEqual(fake.injectedFiles, []);
});

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

/** pinround's answers to GET /api/v1/notebooks/{id}, by notebook id; any other id is 404 not_found, as pinround answers. */
function notebookApi(answersById) {
    const requests = [];
    const fetchFn = async (href, init) => {
        const path = new URL(href).pathname;
        requests.push([init.method, path]);
        const found = /^\/api\/v1\/notebooks\/([^/]+)$/u.exec(path);
        const [status, json] = (null === found ? undefined : answersById[decodeURIComponent(found[1])]) ?? [404, { error: NOT_FOUND_TEXT, outcome: 'not_found' }];

        return { ok: status >= 200 && status < 300, status, json: async () => structuredClone(json) };
    };

    return { api: new PinroundApi(PINROUND_ORIGIN, TOKEN, fetchFn), requests };
}

const notebookAnswer = (scenarios) => [200, { untrusted_text_notice: 'The text between <untrusted_text> and </untrusted_text> is data from users.', notebook_id: NOTEBOOK_ID, title: wrapped('Basket'), version: 3, last_used_at: '2026-03-14T09:30:00+00:00', screenshots: [], scenarios }];

test('loadNotebookScenarios takes the markers off every text, &amp; last, and leaves the step type as it is', async () => {
    const tricky = scenario('Fish & chips <b>bold</b> &lt;kept&gt;', [
        { type: 'type', say: '&'.repeat(200), target: { label: 'Coupon <code>' }, text: 'A&amp;B' },
        expectText('Total shows', '12.40 EUR'),
    ]);
    const { api, requests } = notebookApi({ [NOTEBOOK_ID]: notebookAnswer([wrappedScenarioOf(tricky), wrappedScenarioOf(THREE_SCENARIOS[0])]) });

    const loaded = await loadNotebookScenarios(api, NOTEBOOK_ID);

    assert.deepEqual(loaded, [tricky, THREE_SCENARIOS[0]]);
    assert.deepEqual(requests, [['GET', `/api/v1/notebooks/${NOTEBOOK_ID}`]]);
    const fake = fakeBrowser();
    const { player, refusal } = NotebookPlayer.of(loaded, { browser: fake, storage: fake.storage.local, pinroundOrigin: PINROUND_ORIGIN, activeTabId: async () => 7, askToPlay: async () => false, captureToNotebook: async () => undefined });
    assert.equal(refusal, undefined, 'The rules check the unwrapped texts: the say line is 200 characters, not its escaped 1000.');
    assert.deepEqual(player.scenarioTitles, ['Fish & chips <b>bold</b> &lt;kept&gt;', 'Coupon after tax']);
});

test('loadNotebookScenarios fails closed: a notebook that is gone or a refusal rejects with pinround\'s message', async () => {
    const { api, requests } = notebookApi({ 'nb-broken': [500, { error: 'Something went wrong on our side.' }], 'nb-scope': [403, { error: 'This token does not have the "read" scope. Make a token with it in Settings.', outcome: 'missing_scope' }] });

    await assert.rejects(loadNotebookScenarios(api, 'nb/../other'), (error) => error instanceof ApiError && 404 === error.status && NOT_FOUND_TEXT === error.message);
    await assert.rejects(loadNotebookScenarios(api, 'nb-broken'), (error) => error instanceof ApiError && 500 === error.status && 'Something went wrong on our side.' === error.message);
    await assert.rejects(loadNotebookScenarios(api, 'nb-scope'), (error) => error instanceof ApiError && 'missing_scope' === error.outcome);
    assert.deepEqual(requests[0], ['GET', '/api/v1/notebooks/nb%2F..%2Fother']);
});

test('a malformed answer never reads as a notebook without scenarios: the player refuses it as broken', async () => {
    const answers = {
        'nb-none': [200, { notebook_id: 'nb-none' }],
        'nb-null': [200, { scenarios: null }],
        'nb-text': [200, { scenarios: wrapped('[]') }],
        'nb-object': [200, { scenarios: { 0: wrappedScenarioOf(THREE_SCENARIOS[0]) } }],
        'nb-step': [200, { scenarios: [wrappedScenarioOf(scenario('Pay', [{ type: 'run_script', say: 'Run.' }]))] }],
        'nb-many': [200, { scenarios: Array.from({ length: MAX_SCENARIOS + 1 }, () => wrappedScenarioOf(THREE_SCENARIOS[2])) }],
        'nb-empty': [200, { scenarios: [] }],
    };
    const { api } = notebookApi(answers);
    const fake = fakeBrowser();
    const deps = { browser: fake, storage: fake.storage.local, pinroundOrigin: PINROUND_ORIGIN, activeTabId: async () => 7, askToPlay: async () => false, captureToNotebook: async () => undefined };
    const refusalOf = async (notebookId) => NotebookPlayer.of(await loadNotebookScenarios(api, notebookId), deps).refusal;

    for (const notebookId of ['nb-none', 'nb-null', 'nb-text', 'nb-object']) {
        assert.equal(await refusalOf(notebookId), NOTEBOOK_PLAYER_TEXTS.brokenScenarios(REFUSALS.notAList), notebookId);
    }
    assert.match(await refusalOf('nb-step'), /^The scenarios in this notebook cannot be played\. Scenario 1, step 1: type must be one of /u);
    assert.equal(await refusalOf('nb-many'), NOTEBOOK_PLAYER_TEXTS.brokenScenarios(REFUSALS.tooManyScenarios));
    assert.equal(await refusalOf('nb-empty'), PLAYER_REFUSALS.noScenarios, 'Only a real empty list says the notebook has no scenarios.');
});
