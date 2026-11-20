import assert from 'node:assert/strict';
import { test } from 'node:test';
import { PLAYER_REFUSALS, ScenarioPlayer } from '../src/player/player.js';
import { PAGE_PLAYER_FILES, RUNNER_REFUSALS, checkedPageAnswer, stepRunnerFor } from '../src/player/step-runner.js';
import { ALL_URLS, fakeBrowser } from './fake-browser.mjs';

const { SHOWN_MAX_LENGTH, collapsedWhitespace, shownText } = globalThis.pinroundPlayerText;

const START_URL = 'http://shop.acme-foods.test/basket';
const SHOP_PAGE = { id: 7, url: START_URL, title: 'Acme Foods shop', active: true };
const SETTINGS_PAGE = { id: 9, url: 'chrome://settings/', title: 'Settings', active: true };

const click = (say) => ({ type: 'click', say, target: { role: 'button', name: say } });
const pause = (say) => ({ type: 'pause', say });
const expectText = (say, text) => ({ type: 'expect_text', say, text });
const scenario = (title, steps) => ({ title, why: 'BasketTotal adds the coupon after tax.', setup: '', start_url: START_URL, steps });

const THREE_SCENARIOS = [
    scenario('Coupon after tax', [click('Add jam'), click('Add bread'), pause('Look at the total'), expectText('Total shows', '12.40 EUR'), click('Pay')]),
    scenario('Empty basket', [click('Remove jam')]),
    scenario('Two coupons', [pause('Log in as Mia first'), click('Apply coupon')]),
];

/**
 * A runner that answers as stepRunnerFor does: {ok: true} or a stop, never a rejection. A step whose say line is in
 * `stopsBySay` stops with that answer; every step run is logged by its say line.
 */
function fakeRunner(stopsBySay = {}) {
    const ran = [];
    const runStep = async (step) => {
        ran.push(step.say);

        return stopsBySay[step.say] ?? { ok: true };
    };

    return { ran, runStep };
}

function playerOf(scenarios = THREE_SCENARIOS, stopsBySay = {}) {
    const runner = fakeRunner(stopsBySay);
    const { player, refusal } = ScenarioPlayer.of(scenarios, runner.runStep);
    assert.equal(refusal, undefined);

    return { player, ran: runner.ran };
}

const positionOf = (player) => ({ scenario: player.scenarioIndex, step: player.stepIndex, say: player.say, lastStop: player.lastStop });

test('a new player is on the first step of the first scenario, showing its say line, with no stop', () => {
    const { player, ran } = playerOf();

    assert.equal(player.scenarioCount, 3);
    assert.equal(player.scenario.title, 'Coupon after tax');
    assert.deepEqual(positionOf(player), { scenario: 0, step: 0, say: 'Add jam', lastStop: null });
    assert.equal(player.isDone, false);
    assert.equal(player.isRunning, false);
    assert.deepEqual(ran, []);
});

test('scenarios that break a rule are refused and no player is made; an empty list has nothing to play', () => {
    const { runStep } = fakeRunner();

    assert.deepEqual(ScenarioPlayer.of([scenario('Coupon after tax', [{ type: 'click', say: 'Pay', target: { css: '#pay' } }])], runStep), {
        refusal: 'Scenario 1, step 1: find the element by role and name, by visible text or by label, never by a CSS or XPath string.',
    });
    assert.deepEqual(ScenarioPlayer.of([], runStep), { refusal: PLAYER_REFUSALS.noScenarios });
    assert.deepEqual(ScenarioPlayer.of(null, runStep), { refusal: 'Send the scenarios as a list.' });
    assert.throws(() => new ScenarioPlayer([], runStep), { name: 'RangeError', message: PLAYER_REFUSALS.noScenarios });
});

test('play runs the steps in order and stops before a pause step, with its say line showing', async () => {
    const { player, ran } = playerOf();

    assert.deepEqual(await player.play(), { ended: 'paused' });

    assert.deepEqual(ran, ['Add jam', 'Add bread']);
    assert.deepEqual(positionOf(player), { scenario: 0, step: 2, say: 'Look at the total', lastStop: null });
});

test('play on a pause step passes it and runs on to the end of the scenario', async () => {
    const { player, ran } = playerOf();
    await player.play();

    assert.deepEqual(await player.play(), { ended: 'done' });

    assert.deepEqual(ran, ['Add jam', 'Add bread', 'Total shows', 'Pay']);
    assert.deepEqual(positionOf(player), { scenario: 0, step: 5, say: null, lastStop: null });
    assert.equal(player.isDone, true);
    assert.equal(player.currentStep, null);
});

test('a scenario that starts with a pause step plays on past it: the person has read its say line', async () => {
    const { player, ran } = playerOf();
    player.next();
    player.next();
    assert.equal(player.say, 'Log in as Mia first');

    assert.deepEqual(await player.play(), { ended: 'done' });
    assert.deepEqual(ran, ['Apply coupon']);
});

test('a failed expect stops the run on its step, keeps expected and found, and runs no later step', async () => {
    const failed = { stopped: true, message: 'The page does not show the expected text.', expected: '12.40 EUR', found: 'Total 11.90 EUR' };
    const { player, ran } = playerOf(THREE_SCENARIOS, { 'Total shows': failed });
    await player.play();

    assert.deepEqual(await player.play(), { ended: 'stopped' });

    assert.deepEqual(ran, ['Add jam', 'Add bread', 'Total shows']);
    assert.deepEqual(positionOf(player), {
        scenario: 0,
        step: 3,
        say: 'Total shows',
        lastStop: { stepIndex: 3, message: 'The page does not show the expected text.', expected: '12.40 EUR', found: 'Total 11.90 EUR' },
    });
});

test('playing again after a stop tries the stopped step again and clears the stop once it runs', async () => {
    const answers = [{ stopped: true, message: 'Found no visible element with role button named "Pay".' }, { ok: true }];
    const ran = [];
    const { player } = ScenarioPlayer.of([scenario('Pay', [click('Pay'), click('Done')])], async (step) => {
        ran.push(step.say);

        return 'Pay' === step.say ? answers.shift() : { ok: true };
    });

    assert.deepEqual(await player.play(), { ended: 'stopped' });
    assert.equal(player.lastStop.message, 'Found no visible element with role button named "Pay".');
    assert.equal(player.lastStop.expected, undefined);

    assert.deepEqual(await player.play(), { ended: 'done' });
    assert.deepEqual(ran, ['Pay', 'Pay', 'Done']);
    assert.equal(player.lastStop, null);
});

test('step runs one step at a time, a pause step included, and says when the scenario is done', async () => {
    const { player, ran } = playerOf();

    assert.deepEqual(await player.step(), { ended: 'stepped' });
    assert.deepEqual(positionOf(player), { scenario: 0, step: 1, say: 'Add bread', lastStop: null });
    await player.step();
    assert.deepEqual(await player.step(), { ended: 'stepped' });
    assert.deepEqual(ran, ['Add jam', 'Add bread']);
    assert.equal(player.say, 'Total shows');
    await player.step();
    assert.deepEqual(await player.step(), { ended: 'done' });
    assert.deepEqual(ran, ['Add jam', 'Add bread', 'Total shows', 'Pay']);
});

test('a step that stops keeps the player on it with the stop', async () => {
    const { player, ran } = playerOf(THREE_SCENARIOS, { 'Add jam': { stopped: true, message: 'The element with role button named "Add jam" is disabled, so it was not clicked.' } });

    assert.deepEqual(await player.step(), { ended: 'stopped' });

    assert.deepEqual(ran, ['Add jam']);
    assert.deepEqual(positionOf(player), { scenario: 0, step: 0, say: 'Add jam', lastStop: { stepIndex: 0, message: 'The element with role button named "Add jam" is disabled, so it was not clicked.' } });
});

test('play and step at the end of a scenario are refused and change nothing; restart goes back to its first step', async () => {
    const { player, ran } = playerOf();
    player.next();
    await player.play();
    const before = positionOf(player);

    assert.deepEqual(await player.play(), { refused: PLAYER_REFUSALS.scenarioDone });
    assert.deepEqual(await player.step(), { refused: PLAYER_REFUSALS.scenarioDone });
    assert.deepEqual(positionOf(player), before);
    assert.deepEqual(ran, ['Remove jam']);

    assert.deepEqual(player.restart(), { moved: true });
    assert.deepEqual(positionOf(player), { scenario: 1, step: 0, say: 'Remove jam', lastStop: null });
});

test('next and back move between scenarios, each to its first step, and drop the last stop', async () => {
    const { player } = playerOf(THREE_SCENARIOS, { 'Add bread': { stopped: true, message: 'Found 2 visible elements with role button named "Add bread"; a step needs exactly one.' } });
    await player.play();
    assert.notEqual(player.lastStop, null);

    assert.deepEqual(player.next(), { moved: true });
    assert.deepEqual(positionOf(player), { scenario: 1, step: 0, say: 'Remove jam', lastStop: null });
    assert.deepEqual(player.next(), { moved: true });
    assert.equal(player.scenario.title, 'Two coupons');
    assert.deepEqual(player.back(), { moved: true });
    assert.deepEqual(player.back(), { moved: true });
    assert.deepEqual(positionOf(player), { scenario: 0, step: 0, say: 'Add jam', lastStop: null });
});

test('next at the last scenario and back at the first are refused and change nothing', async () => {
    const { player } = playerOf();
    await player.step();

    assert.deepEqual(player.back(), { refused: 'This is the first scenario.' });
    assert.deepEqual(positionOf(player), { scenario: 0, step: 1, say: 'Add bread', lastStop: null });

    player.next();
    player.next();
    await player.step();
    assert.deepEqual(player.next(), { refused: 'This is the last scenario.' });
    assert.deepEqual(positionOf(player), { scenario: 2, step: 1, say: 'Apply coupon', lastStop: null });
});

test('while a step runs, play, step, next, back and restart are refused and change nothing', async () => {
    let finishStep;
    const ran = [];
    const { player } = ScenarioPlayer.of(THREE_SCENARIOS, (step) => {
        ran.push(step.say);

        return new Promise((resolve) => {
            finishStep = () => resolve({ ok: true });
        });
    });

    const running = player.step();
    assert.equal(player.isRunning, true);
    assert.deepEqual(await player.play(), { refused: PLAYER_REFUSALS.busy });
    assert.deepEqual(await player.step(), { refused: PLAYER_REFUSALS.busy });
    assert.deepEqual(player.next(), { refused: PLAYER_REFUSALS.busy });
    assert.deepEqual(player.back(), { refused: PLAYER_REFUSALS.busy });
    assert.deepEqual(player.restart(), { refused: PLAYER_REFUSALS.busy });
    assert.deepEqual(positionOf(player), { scenario: 0, step: 0, say: 'Add jam', lastStop: null });

    finishStep();
    assert.deepEqual(await running, { ended: 'stepped' });
    assert.equal(player.isRunning, false);
    assert.deepEqual(ran, ['Add jam']);
});

test('a runner that throws or answers something unreadable stops the run, never moves on', async () => {
    for (const [answer, message] of [
        [Promise.reject(new Error('lost')), PLAYER_REFUSALS.stepBroke],
        [undefined, PLAYER_REFUSALS.unreadableAnswer],
        [{ ok: 'yes' }, PLAYER_REFUSALS.unreadableAnswer],
        [{ ok: true, stopped: true, message: 'both' }, 'both'],
        [{ stopped: true }, PLAYER_REFUSALS.unreadableAnswer],
        [{ stopped: true, message: 'Found no option "Sunday" in the list.', expected: 3, found: null }, 'Found no option "Sunday" in the list.'],
    ]) {
        const { player } = ScenarioPlayer.of(THREE_SCENARIOS, () => answer);

        assert.deepEqual(await player.play(), { ended: 'stopped' }, message);
        assert.deepEqual(player.lastStop, { stepIndex: 0, message }, message);
        assert.equal(player.isRunning, false);
    }
});

test('the runner sends page steps to the page player and checks its answer', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE] });
    browser.clickIcon(SHOP_PAGE.id);
    await browser.scripting.executeScript({
        target: { tabId: SHOP_PAGE.id },
        func: () => {
            globalThis.stepsRun = [];
            globalThis.pinroundPagePlayer = {
                async runStep(step) {
                    globalThis.stepsRun.push(step.type);

                    return 'expect_text' === step.type
                        ? { stopped: true, message: 'The page does not show the expected text.', expected: '12.40 EUR', found: 'Total\u001b[2J 11.90\u{2028}EUR' }
                        : { ok: true };
                },
            };
        },
    });
    const runStep = stepRunnerFor(browser, { pageRefusalOf: () => null, tabId: SHOP_PAGE.id, openUrl: async () => assert.fail('no navigation'), captureToNotebook: async () => assert.fail('no capture') });

    assert.deepEqual(await runStep(click('Pay')), { ok: true });
    assert.deepEqual(await runStep(expectText('Total shows', '12.40 EUR')), {
        stopped: true,
        message: 'The page does not show the expected text.',
        expected: '12.40 EUR',
        found: 'Total\\u{1b}[2J 11.90\\u{2028}EUR',
    });
    assert.deepEqual(PAGE_PLAYER_FILES, ['src/player/player-text.js', 'src/player/page-player.js']);
    assert.deepEqual(browser.injectedFiles, [{ tabId: SHOP_PAGE.id, files: PAGE_PLAYER_FILES }, { tabId: SHOP_PAGE.id, files: PAGE_PLAYER_FILES }]);
    const [{ result: stepsRun }] = await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func: () => globalThis.stepsRun });
    assert.deepEqual(stepsRun, ['click', 'expect_text']);
});

test('a page with no player, or one that broke, stops the step: it never looks done', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE] });
    browser.clickIcon(SHOP_PAGE.id);
    const runStep = stepRunnerFor(browser, { pageRefusalOf: () => null, tabId: SHOP_PAGE.id, openUrl: async () => undefined, captureToNotebook: async () => undefined });

    assert.deepEqual(await runStep(click('Pay')), { stopped: true, message: RUNNER_REFUSALS.unreadableAnswer });

    await browser.scripting.executeScript({
        target: { tabId: SHOP_PAGE.id },
        func: () => {
            globalThis.pinroundPagePlayer = {
                runStep() {
                    throw new Error('the page broke the player');
                },
            };
        },
    });
    assert.deepEqual(await runStep(click('Pay')), { stopped: true, message: RUNNER_REFUSALS.unreadableAnswer });
});

test('a page out of reach stops the step with a plain message; any other refusal is shown safely', async () => {
    const browser = fakeBrowser({ tabs: [SETTINGS_PAGE], hostOrigins: [ALL_URLS] });
    const runStep = stepRunnerFor(browser, { pageRefusalOf: () => null, tabId: SETTINGS_PAGE.id, openUrl: async () => undefined, captureToNotebook: async () => undefined });

    assert.deepEqual(await runStep(click('Pay')), { stopped: true, message: RUNNER_REFUSALS.outOfReach });
    assert.deepEqual(browser.injectedFiles, []);

    const goneTab = stepRunnerFor(browser, { pageRefusalOf: () => null, tabId: 99, openUrl: async () => undefined, captureToNotebook: async () => undefined });
    assert.deepEqual(await goneTab(click('Pay')), { stopped: true, message: 'The step could not run: No tab with id: 99.' });
});

test('go_to opens the url through the given navigation, capture adds to the notebook, and pause does nothing in the tab', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE] });
    browser.clickIcon(SHOP_PAGE.id);
    const opened = [];
    let captures = 0;
    const runStep = stepRunnerFor(browser, { pageRefusalOf: () => null,
        tabId: SHOP_PAGE.id,
        openUrl: async (url) => {
            opened.push(url);
        },
        captureToNotebook: async () => {
            captures += 1;
        },
    });

    assert.deepEqual(await runStep({ type: 'go_to', say: 'Open the orders.', url: 'http://shop.acme-foods.test/orders' }), { ok: true });
    assert.deepEqual(await runStep({ type: 'capture', say: 'Keep a picture.' }), { ok: true });
    assert.deepEqual(await runStep(pause('Look.')), { ok: true });

    assert.deepEqual(opened, ['http://shop.acme-foods.test/orders']);
    assert.equal(captures, 1);
    assert.deepEqual(browser.injectedFiles, []);
});

test('a navigation or capture that fails stops the step with its message, control characters shown safely', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE] });
    browser.clickIcon(SHOP_PAGE.id);
    const runStep = stepRunnerFor(browser, { pageRefusalOf: () => null,
        tabId: SHOP_PAGE.id,
        openUrl: async () => {
            throw new Error('Tabs cannot be edited right now.');
        },
        captureToNotebook: async () => {
            throw new Error('A notebook holds at most 10 screenshots.\r\nRemove one first.');
        },
    });

    assert.deepEqual(await runStep({ type: 'go_to', say: 'Go.', url: START_URL }), { stopped: true, message: 'The step could not run: Tabs cannot be edited right now.' });
    assert.deepEqual(await runStep({ type: 'capture', say: 'Keep it.' }), { stopped: true, message: 'The step could not run: A notebook holds at most 10 screenshots.\\u{d}\\u{a}Remove one first.' });
    assert.deepEqual(await runStep({ type: 'run_script', say: 'Run.' }), { stopped: true, message: RUNNER_REFUSALS.unknownStep });
});

test('the page player\'s answer keeps only the known fields, each capped and shown safely; anything else is unreadable', () => {
    const longFound = `${'\u{1F353}'.repeat(SHOWN_MAX_LENGTH)}tail`;

    assert.deepEqual(checkedPageAnswer({ ok: true, extra: 'x' }), { ok: true });
    assert.deepEqual(checkedPageAnswer({ stopped: true, message: 'No.', found: longFound, extra: 'x' }), {
        stopped: true,
        message: 'No.',
        found: `${'\u{1F353}'.repeat(SHOWN_MAX_LENGTH - 1)}…`,
    });
    for (const answer of [null, undefined, 'ok', { ok: 1 }, { ok: true, stopped: false }, { stopped: true }, { stopped: 'yes', message: 'x' }, { stopped: true, message: 'x', found: 5 }]) {
        assert.deepEqual(checkedPageAnswer(answer), { stopped: true, message: RUNNER_REFUSALS.unreadableAnswer }, JSON.stringify(answer));
    }
});

test('shown text caps at 200 characters without cutting an emoji, writes control, separator and bidi characters out, and mends a lone surrogate', () => {
    assert.equal(SHOWN_MAX_LENGTH, 200);
    assert.equal(shownText('a'.repeat(200)), 'a'.repeat(200));
    assert.equal(shownText('a'.repeat(201)), `${'a'.repeat(199)}…`);
    assert.equal(shownText('\u{FEFF}Jam\r\n\t\u001b[31m\u007f\u0085\u{2028}\u{2029}\u{202E}euro\u{2066}'), '\u{FEFF}Jam\\u{d}\\u{a}\\u{9}\\u{1b}[31m\\u{7f}\\u{85}\\u{2028}\\u{2029}\\u{202e}euro\\u{2066}');
    assert.equal(shownText('Jam \uD83C'), 'Jam \u{FFFD}');
    assert.equal(shownText('Rye \u{1F35E} été'), 'Rye \u{1F35E} été');
    assert.equal(shownText(''), '');
});

test('white space collapses to single spaces with none at the ends, CRLF, no-break spaces and a byte order mark included', () => {
    assert.equal(collapsedWhitespace('\u{FEFF}  Pay\r\n12.40\u{A0}EUR \t'), 'Pay 12.40 EUR');
    assert.equal(collapsedWhitespace('Total\u001b'), 'Total\u001b');
});
