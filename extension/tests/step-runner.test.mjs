import assert from 'node:assert/strict';
import { test } from 'node:test';
import { LOAD_POLL_MS, PAGE_PLAYER_FILES, PAGE_STEP_TIMEOUT_MS, RUNNER_REFUSALS, clearPageHighlight, stepRunnerFor } from '../src/player/step-runner.js';
import { fakeBrowser } from './fake-browser.mjs';

const SHOP_PAGE = { id: 7, url: 'http://shop.acme-foods.test/basket', title: 'Acme Foods shop', active: true };
const RECEIPT_URL = 'http://shop.acme-foods.test/receipt';
// A fixed time on a day that is not today: the runner reads no other clock.
const STARTED_AT_MS = Date.UTC(2026, 2, 14, 9, 30);

const waitForReceipt = { type: 'wait_for', say: 'Wait for the receipt.', target: { text: 'Order sent' } };
const expectReceipt = { type: 'expect_text', say: 'The receipt shows.', text: 'Order sent' };

/** A clock frozen at STARTED_AT_MS that moves only when the runner waits; each wait ends once `onWait` has run. */
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

/** Leaves a stand-in page player in the tab's current document that answers every step with `answerSource`'s value. */
async function givePagePlayer(browser, answerSource) {
    await browser.scripting.executeScript({
        target: { tabId: SHOP_PAGE.id },
        args: [answerSource],
        func: (source) => {
            globalThis.stepsRun = [];
            globalThis.pinroundPagePlayer = {
                runStep(step, options) {
                    globalThis.stepsRun.push({ type: step.type, timeoutMs: options.timeoutMs });

                    return 'never' === source ? new Promise(() => { globalThis.waiting = true; }) : JSON.parse(source);
                },
                clearHighlight() {
                    globalThis.highlightCleared = true;
                },
            };
        },
    });
}

const pageValue = async (browser, func) => (await browser.scripting.executeScript({ target: { tabId: SHOP_PAGE.id }, func }))[0].result;

function runnerOf(browser, clock, captureToNotebook = async () => assert.fail('no capture')) {
    return stepRunnerFor(browser, { pageRefusalOf: () => null, tabId: SHOP_PAGE.id, openUrl: async () => assert.fail('no navigation'), captureToNotebook, clock });
}

test('capture clears the highlight in the tab before it adds the screenshot to the notebook', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE] });
    browser.clickIcon(SHOP_PAGE.id);
    await givePagePlayer(browser, '{"ok":true}');
    const order = [];
    const runStep = runnerOf(browser, frozenClock(), async () => {
        order.push(await pageValue(browser, () => (globalThis.highlightCleared ? 'cleared' : 'still shown')), 'captured');
    });

    assert.deepEqual(await runStep({ type: 'capture', say: 'Keep a picture.' }), { ok: true });

    assert.deepEqual(order, ['cleared', 'captured']);
});

test('clearPageHighlight is no failure on a page that holds no player, and rejects for a tab that is gone', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE] });
    browser.clickIcon(SHOP_PAGE.id);

    await clearPageHighlight(browser, SHOP_PAGE.id);
    await assert.rejects(clearPageHighlight(browser, 99), { message: 'No tab with id: 99' });
});

test('capture waits for the tab to load, and stops without a screenshot when it does not', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE] });
    browser.clickIcon(SHOP_PAGE.id);
    browser.navigate(SHOP_PAGE.id, RECEIPT_URL);
    let captures = 0;
    const runStep = runnerOf(browser, frozenClock(), async () => {
        captures += 1;
    });

    assert.deepEqual(await runStep({ type: 'capture', say: 'Keep a picture.' }), { stopped: true, message: RUNNER_REFUSALS.notLoaded });
    assert.equal(captures, 0);

    const loadingAfterTwoLooks = frozenClock((waits) => (2 === waits ? browser.finishLoading(SHOP_PAGE.id) : undefined));
    const runOnceLoaded = runnerOf(browser, loadingAfterTwoLooks, async () => {
        captures += 1;
    });
    assert.deepEqual(await runOnceLoaded({ type: 'capture', say: 'Keep a picture.' }), { ok: true });
    assert.equal(captures, 1);
    assert.deepEqual(loadingAfterTwoLooks.waits, [LOAD_POLL_MS, LOAD_POLL_MS]);
});

test('a page step after a navigation waits until the tab has loaded, then runs once in the new page', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE] });
    browser.clickIcon(SHOP_PAGE.id);
    browser.navigate(SHOP_PAGE.id, RECEIPT_URL);
    await givePagePlayer(browser, '{"ok":true}');
    const stepsRunAtEachWait = [];
    const clock = frozenClock();
    clock.wait = async (ms) => {
        clock.nowMs += ms;
        clock.waits.push(ms);
        stepsRunAtEachWait.push((await pageValue(browser, () => globalThis.stepsRun)).length);
        if (3 === clock.waits.length) {
            browser.finishLoading(SHOP_PAGE.id);
        }
    };

    assert.deepEqual(await runnerOf(browser, clock)(expectReceipt), { ok: true });

    assert.deepEqual(stepsRunAtEachWait, [0, 0, 0]);
    assert.deepEqual(await pageValue(browser, () => globalThis.stepsRun), [{ type: 'expect_text', timeoutMs: PAGE_STEP_TIMEOUT_MS - (3 * LOAD_POLL_MS) }]);
    assert.equal(browser.injectedFiles.length, 1);
});

test('a page step stops when the tab is still loading once the step\'s time is up, and nothing is injected', async () => {
    const browser = fakeBrowser({ tabs: [{ ...SHOP_PAGE, status: 'loading' }] });
    browser.clickIcon(SHOP_PAGE.id);
    const clock = frozenClock();

    assert.deepEqual(await runnerOf(browser, clock)(waitForReceipt), { stopped: true, message: RUNNER_REFUSALS.notLoaded });

    assert.equal(clock.waits.length, PAGE_STEP_TIMEOUT_MS / LOAD_POLL_MS);
    assert.deepEqual(browser.injectedFiles, []);
});

test('a wait_for whose page navigates away while it looks looks again in the new page, with the time it has left', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE] });
    browser.clickIcon(SHOP_PAGE.id);
    await givePagePlayer(browser, 'never');
    const clock = frozenClock((waits) => (1 === waits ? browser.finishLoading(SHOP_PAGE.id) : undefined));

    const running = runnerOf(browser, clock)(waitForReceipt);
    for (let look = 0; look < 100 && true !== await pageValue(browser, () => globalThis.waiting); look += 1) {
        await Promise.resolve();
    }
    assert.equal(await pageValue(browser, () => globalThis.waiting), true);
    browser.navigate(SHOP_PAGE.id, RECEIPT_URL);
    await givePagePlayer(browser, '{"ok":true}');

    assert.deepEqual(await running, { ok: true });
    assert.deepEqual(await pageValue(browser, () => globalThis.stepsRun), [{ type: 'wait_for', timeoutMs: PAGE_STEP_TIMEOUT_MS - LOAD_POLL_MS }]);
    assert.deepEqual(browser.injectedFiles, [{ tabId: SHOP_PAGE.id, files: PAGE_PLAYER_FILES }, { tabId: SHOP_PAGE.id, files: PAGE_PLAYER_FILES }]);
});

for (const refusedInjection of ['files', 'func']) {
    test(`a wait_for whose ${refusedInjection} injection is refused because its frame went away looks again in the new page; another step stops`, async () => {
        const fake = fakeBrowser({ tabs: [SHOP_PAGE] });
        fake.clickIcon(SHOP_PAGE.id);
        // Chrome's own refusal, seen in tests/e2e/extension-player.spec.ts when a step is injected into a page that is leaving.
        const frameRemoved = new Error('Frame with ID 0 was removed.');
        let lookRefusals = 1;
        const browser = {
            ...fake,
            scripting: {
                executeScript(injection) {
                    if (undefined !== injection[refusedInjection] && lookRefusals > 0) {
                        lookRefusals -= 1;
                        fake.navigate(SHOP_PAGE.id, RECEIPT_URL);

                        return Promise.reject(frameRemoved);
                    }

                    return fake.scripting.executeScript(injection);
                },
            },
        };
        const clock = frozenClock(async (waits) => {
            if (1 === waits) {
                await givePagePlayer(fake, '{"ok":true}');
                fake.finishLoading(SHOP_PAGE.id);
            }
    });
    const runStep = runnerOf(browser, clock);

    assert.deepEqual(await runStep(waitForReceipt), { ok: true });
    assert.deepEqual(await pageValue(fake, () => globalThis.stepsRun), [{ type: 'wait_for', timeoutMs: PAGE_STEP_TIMEOUT_MS - LOAD_POLL_MS }]);

    lookRefusals = 1;
    assert.deepEqual(await runStep(expectReceipt), { stopped: true, message: 'The step could not run: Frame with ID 0 was removed.' });
    });
}

test('a wait_for answered null by a page that did not navigate stops as unreadable after one more look; other steps never look again', async () => {
    const browser = fakeBrowser({ tabs: [SHOP_PAGE] });
    browser.clickIcon(SHOP_PAGE.id);
    const runStep = runnerOf(browser, frozenClock());

    assert.deepEqual(await runStep(waitForReceipt), { stopped: true, message: RUNNER_REFUSALS.unreadableAnswer });
    assert.equal(browser.injectedFiles.length, 2);

    assert.deepEqual(await runStep(expectReceipt), { stopped: true, message: RUNNER_REFUSALS.unreadableAnswer });
    assert.equal(browser.injectedFiles.length, 3);
});
