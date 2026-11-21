// The scenario run the dock plays (dock.js, overlay.js): one scenario of the chosen notebook, its step card with Do and
// Expected, and each step's result, kept in the extension's local storage so a click on the page, a navigation or a
// reload finds the same step when the dock opens again. The service worker only loads and checks the scenario and
// opens its start page (src/background/dock-scenario.js); it holds none of this. Injected as a classic script before
// overlay.js; node --test and the service worker import it for its rules. The whole file is one expression, so a
// second injection keeps the first. The card is built in the overlay's closed shadow root, and every text goes in
// through textContent, never as markup.
globalThis.pinroundDockRun ??= (() => {
    /** One run for the whole browser: the dock in any tab shows it, so the start page opened in its place does too. */
    const RUN_KEY = 'pinroundDockRun';
    // The caps of scenario-rules.js and capture-target.js, which a classic script cannot import; a test keeps them in step.
    const MAX_SCENARIOS = 20;
    const MAX_STEPS = 30;
    const TITLE_MAX_LENGTH = 120;
    const SAY_MAX_LENGTH = 200;
    const URL_MAX_LENGTH = 1000;
    const ID_MAX_LENGTH = 64;
    /** What the card shows as Expected, written by the service worker from the step's check. */
    const EXPECTED_MAX_LENGTH = 300;
    const RESULTS = Object.freeze(['passed', 'problem', 'skipped']);

    const TEXTS = Object.freeze({
        card: 'Scenario step',
        stepOf: (stepNumber, stepCount) => `Step ${stepNumber} of ${stepCount}`,
        doLabel: 'Do',
        expectedLabel: 'Expected',
        looksRight: 'Looks right',
        foundProblem: 'Found a problem',
        skip: 'Skip',
        stop: 'Stop',
        nextScenario: 'Next scenario',
        close: 'Close',
        done: ({ passed, problem, skipped }) => `Scenario done: ${passed} looked right, ${problem} with a problem, ${skipped} skipped.`,
        problemTagged: (stepNumber) => `Pin the problem, then capture. The capture is tagged with step ${stepNumber}.`,
        runOpen: 'A scenario is open in the dock. Stop or close it first.',
        runMoved: 'The scenario moved on in another tab. This is where it is now.',
        runStopped: 'The scenario was stopped in another tab.',
        notKept: 'The extension could not keep the scenario. Try again.',
    });

    const STYLES = `
        .dock--playing { width: 260px; }
        .dock-step { display: grid; gap: 6px; padding-top: 6px; border-top: 1px solid #c3c7cc; }
        .dock-step[hidden], .dock-step [hidden] { display: none; }
        .dock-step p { margin: 0; overflow-wrap: anywhere; white-space: pre-wrap; }
        .dock-step__where { font-size: 12px; color: #4a5250; }
        .dock-step__title { font-weight: 700; }
        .dock-step__texts { display: grid; gap: 4px; max-height: 200px; overflow: auto; }
        .dock-step__label { display: block; font-size: 12px; font-weight: 700; color: #4a5250; }
        .dock-step__buttons { display: flex; flex-wrap: wrap; gap: 6px; }
        .dock-step button { padding: 4px 10px; border: 1px solid #2f6fd6; border-radius: 6px; background: #ffffff; color: #1f4fa0; cursor: pointer; }
        .dock-step .dock-step__passed { background: #006c51; border-color: #006c51; color: #ffffff; }
        .dock-step .dock-step__problem { border-color: #a3221a; color: #a3221a; }
    `;

    const isObject = (value) => null !== value && 'object' === typeof value && !Array.isArray(value);
    const isIndex = (value, below) => Number.isInteger(value) && value >= 0 && value < below;

    /** Text of at most maxLength code points, as the caps count; a long text is refused before it is read through. */
    function isTextUpTo(value, maxLength) {
        if ('string' !== typeof value || value.length > 2 * maxLength) {
            return false;
        }

        return value.length <= maxLength || [...value].length <= maxLength;
    }

    const isFilled = (value, maxLength) => isTextUpTo(value, maxLength) && '' !== value.trim();
    const isWebAddress = (value) => isTextUpTo(value, URL_MAX_LENGTH) && /^https?:\/\/\S+$/u.test(value);

    /**
     * @typedef {{scenarioTitle: string, stepNumber: number, say: string}} StepTag what the next capture after "Found a
     *   problem" carries: the step's number counted from 1, and its Do text
     * @returns {StepTag|null} a new tag with only these fields, or null for anything else
     */
    function checkedTag(stored) {
        if (!isObject(stored) || !isFilled(stored.scenarioTitle, TITLE_MAX_LENGTH) || !isFilled(stored.say, SAY_MAX_LENGTH)
            || !Number.isInteger(stored.stepNumber) || stored.stepNumber < 1 || stored.stepNumber > MAX_STEPS) {
            return null;
        }

        return { scenarioTitle: stored.scenarioTitle, stepNumber: stored.stepNumber, say: stored.say };
    }

    /**
     * @typedef {{notebookId: string, scenarioIndex: number, scenarioCount: number, title: string, startUrl: string,
     *   steps: Array<{say: string, expected: string}>}} DockScenario one scenario as the service worker answers Play;
     *   expected is '' for a step with nothing to check
     * @returns {DockScenario|null} a new scenario with only these fields, or null for anything else
     */
    function checkedScenario(value) {
        if (!isObject(value) || !isFilled(value.notebookId, ID_MAX_LENGTH) || !Number.isInteger(value.scenarioCount)
            || value.scenarioCount < 1 || value.scenarioCount > MAX_SCENARIOS || !isIndex(value.scenarioIndex, value.scenarioCount)
            || !isFilled(value.title, TITLE_MAX_LENGTH) || !isWebAddress(value.startUrl)
            || !Array.isArray(value.steps) || 0 === value.steps.length || value.steps.length > MAX_STEPS) {
            return null;
        }
        const steps = value.steps.map((step) => (isObject(step) && isFilled(step.say, SAY_MAX_LENGTH) && isTextUpTo(step.expected, EXPECTED_MAX_LENGTH)
            ? { say: step.say, expected: step.expected }
            : null));
        if (steps.includes(null)) {
            return null;
        }
        const { notebookId, scenarioIndex, scenarioCount, title, startUrl } = value;

        return { notebookId, scenarioIndex, scenarioCount, title, startUrl, steps };
    }

    /**
     * @typedef {DockScenario & {runId: string, stepIndex: number, results: Array<null|'passed'|'problem'|'skipped'>,
     *   pendingTag: StepTag|null}} DockRun stepIndex is the step on the card, steps.length once every step has a result
     * @returns {DockRun|null} the run as stored, or null for anything this version cannot read
     */
    function checkedRun(stored) {
        const scenario = checkedScenario(stored);
        if (null === scenario || !isFilled(stored.runId, ID_MAX_LENGTH) || !Number.isInteger(stored.stepIndex)
            || stored.stepIndex < 0 || stored.stepIndex > scenario.steps.length
            || !Array.isArray(stored.results) || stored.results.length !== scenario.steps.length
            || stored.results.some((result) => null !== result && !RESULTS.includes(result))) {
            return null;
        }
        const pendingTag = null === stored.pendingTag ? null : checkedTag(stored.pendingTag);
        if (null !== stored.pendingTag && null === pendingTag) {
            return null;
        }

        return { ...scenario, runId: stored.runId, stepIndex: stored.stepIndex, results: [...stored.results], pendingTag };
    }

    /** A run at the scenario's first step; a tag still waiting for its capture goes on with it. */
    function startedRun(scenario, runId, pendingTag = null) {
        return { ...scenario, runId, stepIndex: 0, results: scenario.steps.map(() => null), pendingTag };
    }

    const isDone = (run) => run.stepIndex >= run.steps.length;

    /** The run with the card's step answered and the next one on the card; "problem" also tags the next capture. */
    function answeredRun(run, result) {
        if (!RESULTS.includes(result) || isDone(run)) {
            return run;
        }
        const results = [...run.results];
        results[run.stepIndex] = result;
        const pendingTag = 'problem' === result
            ? { scenarioTitle: run.title, stepNumber: run.stepIndex + 1, say: run.steps[run.stepIndex].say }
            : run.pendingTag;

        return { ...run, results, stepIndex: run.stepIndex + 1, pendingTag };
    }

    const isSameStep = (one, other) => null !== one && null !== other && one.runId === other.runId && one.stepIndex === other.stepIndex;
    const isSameTag = (one, other) => null !== one && null !== other && one.scenarioTitle === other.scenarioTitle && one.stepNumber === other.stepNumber && one.say === other.say;

    function countsOf(run) {
        const counts = { passed: 0, problem: 0, skipped: 0 };
        for (const result of run.results) {
            if (null !== result) {
                counts[result] += 1;
            }
        }

        return counts;
    }

    /** Whether the page is at the address, its fragment aside, so Play opens the start page only when the tab is elsewhere. */
    function isAtAddress(pageHref, address) {
        try {
            const page = new URL(pageHref);
            const wanted = new URL(address);
            page.hash = '';
            wanted.hash = '';

            return page.href === wanted.href;
        } catch {
            return false;
        }
    }

    /** @returns {Promise<DockRun|null>} rejects when storage refuses, which is no run gone */
    async function loadRun(storage) {
        return checkedRun((await storage.get(RUN_KEY))[RUN_KEY] ?? null);
    }

    async function saveRun(storage, run) {
        await storage.set({ [RUN_KEY]: run });
    }

    async function forgetRun(storage) {
        await storage.remove(RUN_KEY);
    }

    /**
     * Builds the step card into `slot`, hidden until show().
     *
     * @param {ShadowRoot} shadow the overlay's closed root, which takes the card's styles
     * @param {HTMLElement} slot where the card goes, inside the dock
     * @param {{onAnswer: (result: 'passed'|'problem'|'skipped') => void, onStop: () => void, onNextScenario: () => void}} actions
     */
    function mountStepCard(shadow, slot, { onAnswer, onStop, onNextScenario }) {
        const make = (tag, className, properties = {}) => Object.assign(document.createElement(tag), { className, ...properties });
        const onlyTrusted = (handler) => (event) => {
            if (event.isTrusted) {
                handler(event);
            }
        };
        const sheet = new CSSStyleSheet();
        sheet.replaceSync(STYLES);
        shadow.adoptedStyleSheets = [...shadow.adoptedStyleSheets, sheet];

        const card = make('section', 'dock-step', { hidden: true });
        card.setAttribute('aria-label', TEXTS.card);
        const where = make('p', 'dock-step__where');
        const title = make('p', 'dock-step__title');
        const doText = make('span', 'dock-step__do');
        const doLine = make('p', 'dock-step__line');
        doLine.append(make('span', 'dock-step__label', { textContent: TEXTS.doLabel }), doText);
        const expectedText = make('span', 'dock-step__expected');
        const expectedLine = make('p', 'dock-step__line');
        expectedLine.append(make('span', 'dock-step__label', { textContent: TEXTS.expectedLabel }), expectedText);
        const texts = make('div', 'dock-step__texts');
        texts.append(doLine, expectedLine);
        const summary = make('p', 'dock-step__summary');

        const button = (text, className, handler) => {
            const made = make('button', className, { type: 'button', textContent: text });
            made.addEventListener('click', onlyTrusted(handler));

            return made;
        };
        const looksRight = button(TEXTS.looksRight, 'dock-step__passed', () => onAnswer('passed'));
        const foundProblem = button(TEXTS.foundProblem, 'dock-step__problem', () => onAnswer('problem'));
        const skip = button(TEXTS.skip, 'dock-step__skip', () => onAnswer('skipped'));
        const nextScenario = button(TEXTS.nextScenario, 'dock-step__next', onNextScenario);
        const stop = button(TEXTS.stop, 'dock-step__stop', onStop);
        const answers = make('div', 'dock-step__buttons');
        answers.append(looksRight, foundProblem, skip, nextScenario, stop);
        card.append(where, title, texts, summary, answers);
        slot.append(card);

        let shownRun = null;

        return {
            /** Shows the run's step, or its summary once every step has a result; null hides the card. */
            show(run) {
                shownRun = run;
                card.hidden = null === run;
                slot.parentNode?.classList.toggle('dock--playing', null !== run);
                if (null === run) {
                    return;
                }
                const hasEnded = isDone(run);
                where.textContent = hasEnded ? TEXTS.stepOf(run.steps.length, run.steps.length) : TEXTS.stepOf(run.stepIndex + 1, run.steps.length);
                title.textContent = run.title;
                texts.hidden = hasEnded;
                doText.textContent = hasEnded ? '' : run.steps[run.stepIndex].say;
                expectedText.textContent = hasEnded ? '' : run.steps[run.stepIndex].expected;
                expectedLine.hidden = '' === expectedText.textContent;
                summary.hidden = !hasEnded;
                summary.textContent = hasEnded ? TEXTS.done(countsOf(run)) : '';
                for (const answer of [looksRight, foundProblem, skip]) {
                    answer.hidden = hasEnded;
                }
                nextScenario.hidden = !hasEnded || run.scenarioIndex + 1 >= run.scenarioCount;
                stop.textContent = hasEnded ? TEXTS.close : TEXTS.stop;
            },
            /** The card's buttons that show, in Tab order, for the overlay's focus trap. */
            focusables: () => (null === shownRun ? [] : [looksRight, foundProblem, skip, nextScenario, stop].filter((control) => !control.hidden)),
            /** Where focus goes after an answer moved the card on: the first button the new step or summary shows. */
            focusFirstButton() {
                [looksRight, nextScenario, stop].find((control) => !control.hidden)?.focus();
            },
        };
    }

    return Object.freeze({
        RUN_KEY, MAX_SCENARIOS, MAX_STEPS, TITLE_MAX_LENGTH, SAY_MAX_LENGTH, URL_MAX_LENGTH, ID_MAX_LENGTH, EXPECTED_MAX_LENGTH, RESULTS, TEXTS,
        checkedTag, checkedScenario, checkedRun, startedRun, answeredRun, isDone, isSameStep, isSameTag, countsOf, isAtAddress, loadRun, saveRun, forgetRun, mountStepCard,
    });
})();
