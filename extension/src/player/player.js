// The scenario player as a state machine: which scenario and step it is on, and what the last run stopped on. It runs
// steps through the runner it is given (step-runner.js in the extension, a fake in tests) and holds no DOM and no
// chrome, so the popup only draws its state and calls its moves.
import { checkScenarios } from './scenario-rules.js';

export const PLAYER_REFUSALS = Object.freeze({
    noScenarios: 'This notebook has no scenarios yet.',
    busy: 'A step is running. Wait for it to end.',
    scenarioDone: 'This scenario has run to its end. Play it again from the start, or go to another one.',
    lastScenario: 'This is the last scenario.',
    firstScenario: 'This is the first scenario.',
    unreadableAnswer: 'The step gave an answer the player cannot read. Play the step again.',
    stepBroke: 'The step could not run. Play the step again.',
});

/**
 * Plays checked scenarios one step at a time. A pause step stops a run before it, with its say line showing, until
 * the person plays on; a stop keeps the step it stopped on, so playing again tries that step again. Next and Back
 * past either end are refused and change nothing.
 */
export class ScenarioPlayer {
    #scenarios;
    #runStep;
    #scenarioIndex = 0;
    #stepIndex = 0;
    #lastStop = null;
    #isRunning = false;

    /**
     * Checks the scenarios first, as they come from pinround: the player only ever holds scenarios that passed.
     *
     * @param {unknown} untrustedScenarios the scenarios as pinround sent them
     * @param {(step: object) => Promise<{ok: true} | {stopped: true, message: string, expected?: string, found?: string}>} runStep
     * @returns {{player: ScenarioPlayer} | {refusal: string}}
     */
    static of(untrustedScenarios, runStep) {
        const checked = checkScenarios(untrustedScenarios);
        if (undefined !== checked.refusal) {
            return { refusal: checked.refusal };
        }
        if (0 === checked.scenarios.length) {
            return { refusal: PLAYER_REFUSALS.noScenarios };
        }

        return { player: new ScenarioPlayer(checked.scenarios, runStep) };
    }

    /** Use ScenarioPlayer.of, which checks the scenarios; this takes them as checked and refuses an empty list. */
    constructor(checkedScenarios, runStep) {
        if (!Array.isArray(checkedScenarios) || 0 === checkedScenarios.length) {
            throw new RangeError(PLAYER_REFUSALS.noScenarios);
        }
        this.#scenarios = checkedScenarios;
        this.#runStep = runStep;
    }

    get scenarioIndex() {
        return this.#scenarioIndex;
    }

    get scenarioCount() {
        return this.#scenarios.length;
    }

    get scenario() {
        return this.#scenarios[this.#scenarioIndex];
    }

    /** @returns {ReadonlyArray<object>} every scenario, as checked */
    get scenarios() {
        return this.#scenarios;
    }

    /** From 0; equal to the number of steps once the scenario has run to its end. */
    get stepIndex() {
        return this.#stepIndex;
    }

    /** The step that runs next, or null once the scenario has run to its end. */
    get currentStep() {
        return this.scenario.steps[this.#stepIndex] ?? null;
    }

    /** The say line of the step that runs next, or null once the scenario has run to its end. */
    get say() {
        return this.currentStep?.say ?? null;
    }

    get isDone() {
        return null === this.currentStep;
    }

    get isRunning() {
        return this.#isRunning;
    }

    /** @returns {null | {stepIndex: number, message: string, expected?: string, found?: string}} cleared by any move */
    get lastStop() {
        return this.#lastStop;
    }

    /**
     * Runs the scenario's steps from the current one until its end, a pause step or a stop. A pause step the run
     * starts on counts as passed: the person has read its say line and played on.
     *
     * @returns {Promise<{refused: string} | {ended: 'done' | 'paused' | 'stopped'}>}
     */
    async play() {
        return this.#run(async () => {
            for (let isFirst = true; !this.isDone; isFirst = false) {
                if ('pause' === this.currentStep.type && !isFirst) {
                    return 'paused';
                }
                if (!(await this.#runCurrentStep())) {
                    return 'stopped';
                }
            }

            return 'done';
        });
    }

    /**
     * Runs the current step only, a pause step included, which it passes.
     *
     * @returns {Promise<{refused: string} | {ended: 'stepped' | 'done' | 'stopped'}>}
     */
    async step() {
        return this.#run(async () => {
            if (!(await this.#runCurrentStep())) {
                return 'stopped';
            }

            return this.isDone ? 'done' : 'stepped';
        });
    }

    /** @returns {{refused: string} | {moved: true}} */
    next() {
        return this.#moveTo(this.#scenarioIndex + 1, PLAYER_REFUSALS.lastScenario);
    }

    /** @returns {{refused: string} | {moved: true}} */
    back() {
        return this.#moveTo(this.#scenarioIndex - 1, PLAYER_REFUSALS.firstScenario);
    }

    /** Back to the first step of the current scenario. @returns {{refused: string} | {moved: true}} */
    restart() {
        return this.#moveTo(this.#scenarioIndex, null);
    }

    #moveTo(scenarioIndex, pastEndRefusal) {
        if (this.#isRunning) {
            return { refused: PLAYER_REFUSALS.busy };
        }
        if (scenarioIndex < 0 || scenarioIndex >= this.#scenarios.length) {
            return { refused: pastEndRefusal };
        }
        this.#scenarioIndex = scenarioIndex;
        this.#stepIndex = 0;
        this.#lastStop = null;

        return { moved: true };
    }

    async #run(steps) {
        if (this.#isRunning) {
            return { refused: PLAYER_REFUSALS.busy };
        }
        if (this.isDone) {
            return { refused: PLAYER_REFUSALS.scenarioDone };
        }
        this.#isRunning = true;
        this.#lastStop = null;
        try {
            return { ended: await steps() };
        } finally {
            this.#isRunning = false;
        }
    }

    /** @returns {Promise<boolean>} true when the step passed and the player moved on to the next */
    async #runCurrentStep() {
        const step = this.currentStep;
        if ('pause' === step.type) {
            this.#stepIndex += 1;

            return true;
        }
        let answer;
        try {
            answer = await this.#runStep(step);
        } catch {
            answer = { stopped: true, message: PLAYER_REFUSALS.stepBroke };
        }
        if (true === answer?.ok && undefined === answer.stopped) {
            this.#stepIndex += 1;

            return true;
        }
        this.#lastStop = Object.freeze(stopOf(answer, this.#stepIndex));

        return false;
    }
}

function stopOf(answer, stepIndex) {
    if (true !== answer?.stopped || 'string' !== typeof answer.message) {
        return { stepIndex, message: PLAYER_REFUSALS.unreadableAnswer };
    }
    const stop = { stepIndex, message: answer.message };
    for (const field of ['expected', 'found']) {
        if ('string' === typeof answer[field]) {
            stop[field] = answer[field];
        }
    }

    return stop;
}
