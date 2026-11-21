import assert from 'node:assert/strict';
import { test } from 'node:test';
import '../src/overlay/dock.js';
import '../src/overlay/dock-run.js';
import { OVERLAY_FILES } from '../src/capture/page-pins.js';
import { isId } from '../src/capture/capture-target.js';
import { MAX_SCENARIOS, MAX_STEPS, SAY_MAX_LENGTH, TEXT_VALUE_MAX_LENGTH, TITLE_MAX_LENGTH } from '../src/player/scenario-rules.js';
import { PLAY_REFUSALS } from '../src/shared/allowed-hosts.js';
import { fakeBrowser } from './fake-browser.mjs';
import { fakePage } from './fake-page.mjs';

const dockTexts = globalThis.pinroundDock.TEXTS;
const runRules = globalThis.pinroundDockRun;
const { RUN_KEY, TEXTS } = runRules;

const SHOP_HOST = 'shop.acme-foods.example';
const START_URL = `https://${SHOP_HOST}/basket`;
const NOTEBOOK_ID = 'nb000000-0000-4000-8000-000000000001';
const STATE = { choices: [{ value: 'new-report', label: 'A new report' }, { value: `notebook:${NOTEBOOK_ID}`, label: 'Notebook: Checkout' }], chosen: `notebook:${NOTEBOOK_ID}`, count: null, canSave: false };
const TOTAL_EXPECTED = 'The page shows "12.40 EUR".';
const SCENARIO = Object.freeze({
    notebookId: NOTEBOOK_ID,
    scenarioIndex: 0,
    scenarioCount: 2,
    title: 'Coupon after tax',
    startUrl: START_URL,
    steps: [{ say: 'Add jam', expected: '' }, { say: 'Look at the total', expected: TOTAL_EXPECTED }, { say: 'Pay', expected: '' }],
});
const SAVED_CAPTURE = { message: 'Screenshot 1 added to the notebook. Only you see it.', state: STATE };
const REFUSED_CAPTURE = { message: 'A notebook holds at most 10 screenshots.', isError: true, state: STATE };

const settled = () => new Promise((resolve) => setTimeout(resolve, 0));

/**
 * The dock open on a page, its extension answering each request from `answers` by action: a function of the request
 * value, called in order for every request. 'state' answers STATE, 'scenario' SCENARIO and 'open-start' {} unless given.
 */
async function playingPage({ storage, href = START_URL, answers = {} }) {
    const answerOf = { state: () => ({ state: STATE }), scenario: () => ({ scenario: structuredClone(SCENARIO) }), 'open-start': () => ({}), ...answers };
    const page = fakePage({ siteHost: SHOP_HOST, href, storage, sendMessage: async (message) => answerOf[message.pinroundDock](message.value) });
    page.inject(...OVERLAY_FILES);
    await page.evaluate('globalThis.pinroundOverlay.openDock()');
    await settled();
    const host = page.document.documentElement.children.find((child) => 'PINROUND-OVERLAY' === child.tagName);
    const root = page.closedRootOf(host);
    const inRoot = (selector) => root.querySelector(selector);
    const press = async (selector) => {
        page.click(inRoot(selector));
        await settled();
    };
    const playButton = root.querySelectorAll('button').find((button) => button.textContent.startsWith(dockTexts.play));
    const captureButton = root.querySelectorAll('button').find((button) => button.textContent.startsWith(dockTexts.capture));
    const card = () => ({
        hidden: inRoot('.dock-step').hidden,
        where: inRoot('.dock-step__where').textContent,
        say: inRoot('.dock-step__do').textContent,
        expected: inRoot('.dock-step__expected').textContent,
        isExpectedShown: !inRoot('.dock-step__expected').parentNode.hidden,
    });

    return {
        page,
        host,
        root,
        inRoot,
        press,
        card,
        play: async () => {
            page.click(playButton);
            await settled();
        },
        capture: async () => {
            page.click(captureButton);
            await settled();
        },
        status: () => inRoot('.dock__status').textContent,
        requests: () => page.sentMessages.map((message) => message.pinroundDock),
    };
}

const storedRunOf = async (storage) => (await storage.get(RUN_KEY))[RUN_KEY];

test('Play keeps the run in storage at its first step and shows it, with no start page opened on the start page itself', async () => {
    const { storage } = fakeBrowser();
    // The fragment is no other page.
    const { play, card, page } = await playingPage({ storage: storage.local, href: `${START_URL}#total` });

    await play();

    assert.deepEqual(page.sentMessages, [{ pinroundDock: 'state', value: undefined }, { pinroundDock: 'scenario', value: 0 }]);
    const stored = await storedRunOf(storage.local);
    assert.match(stored.runId, /^[0-9a-f]{32}$/u);
    assert.deepEqual(stored, { ...SCENARIO, runId: stored.runId, stepIndex: 0, results: [null, null, null], pendingTag: null });
    assert.deepEqual(card(), { hidden: false, where: 'Step 1 of 3', say: 'Add jam', expected: '', isExpectedShown: false });
});

test('Play on another page of the tab keeps the run, then asks the extension to open the start page', async () => {
    const { storage } = fakeBrowser();
    const { play, requests, page } = await playingPage({ storage: storage.local, href: `https://${SHOP_HOST}/` });

    await play();

    assert.deepEqual(requests(), ['state', 'scenario', 'open-start']);
    assert.equal(page.sentMessages.at(-1).value, START_URL);
    assert.equal((await storedRunOf(storage.local)).stepIndex, 0);
});

test('two runs started on two pages get different run ids', async () => {
    const one = fakeBrowser().storage.local;
    const other = fakeBrowser().storage.local;
    await (await playingPage({ storage: one })).play();
    await (await playingPage({ storage: other })).play();

    assert.notEqual((await storedRunOf(one)).runId, (await storedRunOf(other)).runId);
});

test('Play with a run already open never replaces it: it shows the open run and says so', async () => {
    const { storage } = fakeBrowser();
    const first = await playingPage({ storage: storage.local });
    await first.play();
    await first.press('.dock-step__passed');
    const before = await storedRunOf(storage.local);

    const second = await playingPage({ storage: storage.local, href: `https://${SHOP_HOST}/` });
    await second.play();

    assert.deepEqual(second.requests(), ['state'], 'No scenario was asked for and no page opened.');
    assert.equal(second.status(), TEXTS.runOpen);
    assert.deepEqual(await storedRunOf(storage.local), before);
    assert.equal(second.card().where, 'Step 2 of 3');
});

test('Looks right and Skip record the step\'s result and move the card on; the last answer shows the summary', async () => {
    const { storage } = fakeBrowser();
    const { play, press, card, inRoot } = await playingPage({ storage: storage.local });
    await play();

    await press('.dock-step__passed');
    assert.deepEqual((({ stepIndex, results }) => ({ stepIndex, results }))(await storedRunOf(storage.local)), { stepIndex: 1, results: ['passed', null, null] });
    assert.deepEqual(card(), { hidden: false, where: 'Step 2 of 3', say: 'Look at the total', expected: TOTAL_EXPECTED, isExpectedShown: true });

    await press('.dock-step__skip');
    assert.deepEqual((({ stepIndex, results }) => ({ stepIndex, results }))(await storedRunOf(storage.local)), { stepIndex: 2, results: ['passed', 'skipped', null] });
    assert.deepEqual(card(), { hidden: false, where: 'Step 3 of 3', say: 'Pay', expected: '', isExpectedShown: false });

    await press('.dock-step__passed');
    assert.deepEqual((({ stepIndex, results }) => ({ stepIndex, results }))(await storedRunOf(storage.local)), { stepIndex: 3, results: ['passed', 'skipped', 'passed'] });
    assert.equal(inRoot('.dock-step__summary').textContent, 'Scenario done: 2 looked right, 0 with a problem, 1 skipped.');
    assert.deepEqual(['.dock-step__passed', '.dock-step__problem', '.dock-step__skip', '.dock-step__next'].map((selector) => inRoot(selector).hidden), [true, true, true, false]);
    assert.equal(inRoot('.dock-step__stop').textContent, TEXTS.close);
});

test('focus stays on the card as it moves on: the next step\'s first button, then the summary\'s, then Pin once it closes', async () => {
    const { storage } = fakeBrowser();
    const { play, inRoot, root, page } = await playingPage({ storage: storage.local });
    await play();
    const pressFocused = async () => {
        page.click(root.activeElement);
        await settled();
    };

    inRoot('.dock-step__skip').focus();
    await pressFocused();
    assert.equal(root.activeElement, inRoot('.dock-step__passed'));
    await pressFocused();
    await pressFocused();
    assert.equal(root.activeElement, inRoot('.dock-step__next'), 'Looks right is hidden on the summary.');

    inRoot('.dock-step__stop').focus();
    await pressFocused();
    assert.equal(root.activeElement.textContent.startsWith(dockTexts.pin), true);
});

test('a click the page\'s script makes on the card answers nothing', async () => {
    const { storage } = fakeBrowser();
    const { play, inRoot, page } = await playingPage({ storage: storage.local });
    await play();
    const before = await storedRunOf(storage.local);

    for (const selector of ['.dock-step__passed', '.dock-step__problem', '.dock-step__skip', '.dock-step__stop']) {
        page.click(inRoot(selector), { isTrusted: false });
    }
    await settled();

    assert.deepEqual(await storedRunOf(storage.local), before);
    assert.equal(inRoot('.surface').hidden, true);
});

test('the overlay\'s focus trap ends at the card\'s last shown button, and at the handle while the dock is shrunk', async () => {
    const { storage } = fakeBrowser();
    const { play, inRoot, root, page } = await playingPage({ storage: storage.local });
    await play();
    page.keydown(page.document.body, 'p');

    inRoot('.dock-step__stop').focus();
    page.keydown(root.activeElement, 'Tab');
    assert.equal(root.activeElement, inRoot('.surface'), 'Tab on Stop wraps to the first part.');
    page.keydown(root.activeElement, 'Tab', { shiftKey: true });
    assert.equal(root.activeElement, inRoot('.dock-step__stop'));

    page.keydown(page.document.body, 'h');
    inRoot('.surface').focus();
    page.keydown(root.activeElement, 'Tab', { shiftKey: true });
    assert.equal(root.activeElement, inRoot('.dock-handle'), 'A shrunk dock hides the card, so its buttons are out of the trap.');
});

test('Close on the summary forgets the run and hides the card', async () => {
    const { storage } = fakeBrowser();
    const { play, press, card } = await playingPage({ storage: storage.local });
    await play();

    await press('.dock-step__stop');

    assert.equal(await storedRunOf(storage.local), undefined);
    assert.equal(card().hidden, true);
});

test('Found a problem turns pinning on and tags the next capture, which keeps the tag until a capture is saved', async () => {
    const { storage } = fakeBrowser();
    const captureAnswers = [REFUSED_CAPTURE, SAVED_CAPTURE, SAVED_CAPTURE];
    const { play, press, capture, inRoot, status, page } = await playingPage({ storage: storage.local, answers: { capture: () => captureAnswers.shift() } });
    await play();
    assert.equal(inRoot('.surface').hidden, true);

    await press('.dock-step__problem');

    const tag = { scenarioTitle: SCENARIO.title, stepNumber: 1, say: 'Add jam' };
    const tagged = await storedRunOf(storage.local);
    assert.deepEqual([tagged.stepIndex, tagged.results, tagged.pendingTag], [1, ['problem', null, null], tag]);
    assert.equal(inRoot('.surface').hidden, false, 'Pinning is on.');
    assert.equal(status(), TEXTS.problemTagged(1));

    await capture();
    assert.deepEqual(page.sentMessages.at(-1), { pinroundDock: 'capture', value: tag });
    assert.deepEqual((await storedRunOf(storage.local)).pendingTag, tag, 'A refused capture keeps the tag for the retry.');

    await capture();
    assert.deepEqual(page.sentMessages.at(-1), { pinroundDock: 'capture', value: tag });
    assert.deepEqual(await storedRunOf(storage.local), { ...tagged, pendingTag: null });

    await capture();
    assert.deepEqual(page.sentMessages.at(-1), { pinroundDock: 'capture', value: undefined });
    assert.deepEqual(captureAnswers, []);
});

test('a saved capture leaves a newer tag in place when another step was tagged while it ran', async () => {
    const { storage } = fakeBrowser();
    let releaseCapture;
    const { play, press, capture } = await playingPage({
        storage: storage.local,
        answers: { capture: () => new Promise((resolve) => { releaseCapture = () => resolve(SAVED_CAPTURE); }) },
    });
    await play();
    await press('.dock-step__problem');
    await capture();

    const newerTag = { scenarioTitle: SCENARIO.title, stepNumber: 2, say: 'Look at the total' };
    // The step-2 answer comes from another tab, straight into storage, while this tab's capture is on its way.
    await storage.local.set({ [RUN_KEY]: { ...(await storedRunOf(storage.local)), stepIndex: 2, results: ['problem', 'problem', null], pendingTag: newerTag } });
    releaseCapture();
    await settled();

    assert.deepEqual((await storedRunOf(storage.local)).pendingTag, newerTag);
});

test('a new page on the same storage shows the same step, as after a reload or a navigation', async () => {
    const { storage } = fakeBrowser();
    const first = await playingPage({ storage: storage.local });
    await first.play();
    await first.press('.dock-step__passed');

    const reloaded = await playingPage({ storage: storage.local });

    assert.deepEqual(reloaded.card(), { hidden: false, where: 'Step 2 of 3', say: 'Look at the total', expected: TOTAL_EXPECTED, isExpectedShown: true });
    assert.deepEqual(reloaded.requests(), ['state']);
});

test('a refusal or an unreadable scenario from the extension stores no run and shows no card', async () => {
    const refusal = PLAY_REFUSALS.notAllowed(SHOP_HOST);
    const cases = [
        [() => ({ message: refusal, isError: true }), refusal],
        [() => ({ scenario: { ...SCENARIO, steps: Array.from({ length: MAX_STEPS + 1 }, () => ({ say: 'Add jam', expected: '' })) } }), dockTexts.notAnswered],
        [() => ({ scenario: { ...SCENARIO, startUrl: 'javascript:alert(1)' } }), dockTexts.notAnswered],
    ];
    for (const [scenario, message] of cases) {
        const { storage } = fakeBrowser();
        const { play, card, status, requests } = await playingPage({ storage: storage.local, href: `https://${SHOP_HOST}/`, answers: { scenario } });

        await play();

        assert.deepEqual(await storage.local.get(null), {}, message);
        assert.equal(card().hidden, true);
        assert.equal(status(), message);
        assert.deepEqual(requests(), ['state', 'scenario'], 'No start page is opened.');
    }
});

test('a step\'s texts go on the card as text, never as markup', async () => {
    const markup = '<img src=x onerror=alert(1)>';
    const { storage } = fakeBrowser();
    const { play, card, root, inRoot } = await playingPage({
        storage: storage.local,
        answers: { scenario: () => ({ scenario: { ...SCENARIO, title: `Coupon ${markup}`, steps: [{ say: markup, expected: `The text "${markup}" is visible.` }] } }) },
    });

    await play();

    assert.deepEqual([card().say, card().expected], [markup, `The text "${markup}" is visible.`]);
    assert.equal(inRoot('.dock-step__title').textContent, `Coupon ${markup}`);
    assert.deepEqual(root.querySelectorAll('img'), []);
    assert.deepEqual(['.dock-step__do', '.dock-step__expected', '.dock-step__title'].map((selector) => inRoot(selector).children.length), [0, 0, 0]);
});

test('the step card lives in the overlay\'s closed root, where the page\'s DOM cannot find it', async () => {
    const { storage } = fakeBrowser();
    const { play, page, host, inRoot } = await playingPage({ storage: storage.local });
    await play();
    assert.equal(inRoot('.dock-step').hidden, false);

    assert.equal(page.document.querySelector('.dock-step'), null);
    assert.equal(page.document.querySelector('button'), null);
    assert.equal(host.shadowRoot, null);
    assert.deepEqual(page.document.querySelectorAll('*').map((element) => element.tagName), ['HTML', 'BODY', 'PINROUND-OVERLAY']);
});

test('a press on a step another tab has answered shows where the run is now and records nothing', async () => {
    const { storage } = fakeBrowser();
    const here = await playingPage({ storage: storage.local });
    await here.play();
    const there = await playingPage({ storage: storage.local });
    assert.equal(there.card().where, 'Step 1 of 3');

    await here.press('.dock-step__passed');
    const answeredHere = await storedRunOf(storage.local);
    await there.press('.dock-step__problem');

    assert.equal(there.status(), TEXTS.runMoved);
    assert.deepEqual(await storedRunOf(storage.local), answeredHere);
    assert.equal(answeredHere.pendingTag, null);
    assert.equal(there.card().where, 'Step 2 of 3');
    assert.equal(there.inRoot('.surface').hidden, true, 'No pinning for a step that was not tagged.');
});

test('a press on a run another tab stopped says so, hides the card and stores nothing', async () => {
    const { storage } = fakeBrowser();
    const here = await playingPage({ storage: storage.local });
    await here.play();
    const there = await playingPage({ storage: storage.local });

    await here.press('.dock-step__stop');
    await there.press('.dock-step__skip');

    assert.equal(there.status(), TEXTS.runStopped);
    assert.equal(there.card().hidden, true);
    assert.deepEqual(await storage.local.get(null), {});
});

test('Next scenario on a finished run starts the notebook\'s next scenario as a new run', async () => {
    const { storage } = fakeBrowser();
    const second = { ...SCENARIO, scenarioIndex: 1, title: 'Empty basket', steps: [{ say: 'Remove jam', expected: '' }] };
    const { play, press, card, page } = await playingPage({ storage: storage.local, answers: { scenario: (index) => ({ scenario: structuredClone(0 === index ? SCENARIO : second) }) } });
    await play();
    for (let step = 0; step < SCENARIO.steps.length; step += 1) {
        await press('.dock-step__skip');
    }
    const finished = await storedRunOf(storage.local);

    await press('.dock-step__next');

    assert.deepEqual(page.sentMessages.at(-1), { pinroundDock: 'scenario', value: 1 });
    const next = await storedRunOf(storage.local);
    assert.notEqual(next.runId, finished.runId);
    assert.deepEqual(next, { ...second, runId: next.runId, stepIndex: 0, results: [null], pendingTag: null });
    assert.deepEqual([card().where, card().say], ['Step 1 of 1', 'Remove jam']);
});

test('a storage that refuses to keep the run says so and shows no card', async () => {
    const { storage } = fakeBrowser();
    const refusing = { ...storage.local, set: async () => { throw new Error('QUOTA_BYTES quota exceeded'); } };
    const { play, card, status } = await playingPage({ storage: refusing });

    await play();

    assert.equal(status(), TEXTS.notKept);
    assert.equal(card().hidden, true);
    assert.deepEqual(await storage.local.get(null), {});
});

const validRun = () => ({ ...structuredClone(SCENARIO), runId: 'a'.repeat(32), stepIndex: 1, results: ['passed', null, null], pendingTag: { scenarioTitle: SCENARIO.title, stepNumber: 1, say: 'Add jam' } });

test('checkedRun reads a stored run back as a copy with only its own fields', () => {
    const stored = { ...validRun(), extra: 'left out' };

    const checked = runRules.checkedRun(stored);

    assert.deepEqual(checked, validRun());
    assert.notEqual(checked.results, stored.results);
    assert.deepEqual(runRules.checkedRun({ ...validRun(), steps: Array.from({ length: MAX_STEPS }, () => ({ say: 'Add jam', expected: '' })), results: Array(MAX_STEPS).fill(null) }).steps.length, MAX_STEPS);
    assert.equal(runRules.checkedRun({ ...validRun(), title: '😀'.repeat(TITLE_MAX_LENGTH) }).title.length, 2 * TITLE_MAX_LENGTH, 'The caps count code points, as pinround does.');
});

test('checkedRun refuses more steps than a scenario holds, texts over their caps and a run it cannot place', () => {
    const steps = (count, step = { say: 'Add jam', expected: '' }) => Array.from({ length: count }, () => ({ ...step }));
    const refused = {
        'too many steps': { ...validRun(), steps: steps(MAX_STEPS + 1), results: Array(MAX_STEPS + 1).fill(null) },
        'no steps': { ...validRun(), steps: [], results: [], stepIndex: 0 },
        'title over its cap': { ...validRun(), title: 'T'.repeat(TITLE_MAX_LENGTH + 1) },
        'emoji title over its cap': { ...validRun(), title: '😀'.repeat(TITLE_MAX_LENGTH + 1) },
        'blank title': { ...validRun(), title: ' \n' },
        'say over its cap': { ...validRun(), steps: [...steps(2), { say: 'S'.repeat(SAY_MAX_LENGTH + 1), expected: '' }] },
        'expected over its cap': { ...validRun(), steps: [...steps(2), { say: 'Pay', expected: 'E'.repeat(runRules.EXPECTED_MAX_LENGTH + 1) }] },
        'start address over its cap': { ...validRun(), startUrl: `${START_URL}?q=${'q'.repeat(runRules.URL_MAX_LENGTH)}` },
        'start address that is no web page': { ...validRun(), startUrl: 'javascript:alert(1)' },
        'start address with a trailing newline': { ...validRun(), startUrl: `${START_URL}\n` },
        'notebook id over its cap': { ...validRun(), notebookId: 'n'.repeat(runRules.ID_MAX_LENGTH + 1) },
        'run id missing': { ...validRun(), runId: undefined },
        'scenario index past the count': { ...validRun(), scenarioIndex: 2 },
        'more scenarios than a notebook holds': { ...validRun(), scenarioCount: MAX_SCENARIOS + 1 },
        'step index past the end': { ...validRun(), stepIndex: 4 },
        'step index below 0': { ...validRun(), stepIndex: -1 },
        'results of another length': { ...validRun(), results: ['passed', null] },
        'a result it does not know': { ...validRun(), results: ['failed', null, null] },
        'a tag of no step': { ...validRun(), pendingTag: { scenarioTitle: SCENARIO.title, stepNumber: 0, say: 'Add jam' } },
        'a tag missing': { ...validRun(), pendingTag: undefined },
        'a list': [validRun()],
        'nothing stored': null,
    };

    for (const [name, stored] of Object.entries(refused)) {
        assert.equal(runRules.checkedRun(stored), null, name);
    }
});

test('the run\'s caps are the ones scenario-rules.js and capture-target.js keep', () => {
    assert.deepEqual(
        [runRules.MAX_SCENARIOS, runRules.MAX_STEPS, runRules.TITLE_MAX_LENGTH, runRules.SAY_MAX_LENGTH, runRules.URL_MAX_LENGTH],
        [MAX_SCENARIOS, MAX_STEPS, TITLE_MAX_LENGTH, SAY_MAX_LENGTH, TEXT_VALUE_MAX_LENGTH],
    );
    assert.deepEqual([isId('n'.repeat(runRules.ID_MAX_LENGTH)), isId('n'.repeat(runRules.ID_MAX_LENGTH + 1))], [true, false]);
});
