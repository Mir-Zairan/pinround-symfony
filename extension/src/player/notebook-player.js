// A notebook's scenarios as the popup plays them: ScenarioPlayer (player.js) for the moves, step-runner.js in the tab,
// and the safety rules of ADR-0046 checked before any step runs: every address the scenario names and the tab's own
// on an allowed host and never on pinround, and the person's "Play anyway" once per host. No DOM: the popup draws its
// state and passes the question to ask.
import { captureTexts } from '../capture/capture.js';
import { unwrappedText } from '../capture/untrusted-text.js';
import { confirmSite, hostTextOf, isConfirmedSite, loadAllowedHosts, playRefusalFor, siteOf } from '../shared/allowed-hosts.js';
import { PLAYER_REFUSALS, ScenarioPlayer } from './player.js';
import { MAX_SCENARIOS, MAX_STEPS } from './scenario-rules.js';
import { StepRefusal, clearPageHighlight, stepRunnerFor } from './step-runner.js';

export const NOTEBOOK_PLAYER_TEXTS = Object.freeze({
    stopped: 'Stopped. Play to go on from this step.',
    notPlayed: 'Nothing ran.',
    hostQuestion: (host) => `This scenario clicks and types on ${host}. It can change data there.`,
    brokenScenarios: (refusal) => `The scenarios in this notebook cannot be played. ${refusal} Ask Claude Code to save them again.`,
});

/**
 * The notebook's scenarios from GET /api/v1/notebooks/{id} with pinround's <untrusted_text> markers taken off each
 * text, still unchecked: ScenarioPlayer.of checks them. A list longer than the format allows is passed on as it came,
 * so the rules refuse it before anything reads it through.
 *
 * @returns {Promise<unknown>}
 * @throws {import('../shared/api.js').ApiError} pinround's refusal, such as a notebook that is gone
 */
export async function loadNotebookScenarios(api, notebookId) {
    const answer = await api.call('GET', `/api/v1/notebooks/${encodeURIComponent(notebookId)}`);

    return unwrappedListOf(answer.scenarios, MAX_SCENARIOS, unwrappedScenarioOf);
}

const isPlainObject = (value) => null !== value && 'object' === typeof value && !Array.isArray(value);
const unwrappedListOf = (value, max, unwrapEntry) => (Array.isArray(value) && value.length <= max ? value.map(unwrapEntry) : value);

function unwrappedFieldsOf(value, unwrapField) {
    if (!isPlainObject(value)) {
        return value;
    }

    return Object.fromEntries(Object.entries(value).map(([field, fieldValue]) => [field, unwrapField(field, fieldValue)]));
}

const unwrappedValueOf = (value) => ('string' === typeof value ? unwrappedText(value) : value);
const unwrappedStepOf = (step) => unwrappedFieldsOf(step, (field, value) => ('target' === field ? unwrappedFieldsOf(value, (_, part) => unwrappedValueOf(part)) : unwrappedValueOf(value)));
const unwrappedScenarioOf = (scenario) => unwrappedFieldsOf(scenario, (field, value) => ('steps' === field ? unwrappedListOf(value, MAX_STEPS, unwrappedStepOf) : unwrappedValueOf(value)));

/**
 * Plays one notebook's checked scenarios in the tab the popup was opened on. Every run (Play or Step) is refused, with
 * nothing run, unless the scenario's start_url, each of its go_to urls and the tab's address (and the one it is
 * loading) are on an allowed host
 * and not on pinround, and the person said "Play anyway" for each of those hosts once. go_to checks its url again
 * before it navigates, and every page step checks the loaded tab before it acts. The page's highlight is cleared when
 * a run ends or stops, before Next and Back, and on close.
 */
export class NotebookPlayer {
    #scenarioPlayer;
    #browser;
    #storage;
    #pinroundOrigin;
    #activeTabId;
    #askToPlay;
    #captureToNotebook;
    #onStepStart;
    #clock;
    #runStep = null;
    #playingTabId = null;
    #isStopAsked = false;
    #isPreparing = false;

    /**
     * @param {unknown} untrustedScenarios as loadNotebookScenarios answered them
     * @param {{browser: object, storage: object, pinroundOrigin: string, activeTabId: () => Promise<number>,
     *   askToPlay: (host: string) => Promise<boolean>, captureToNotebook: (tabId: number, texts: object) => Promise<unknown>,
     *   onStepStart?: (step: object) => void, clock?: object}} deps storage is the extension's local area, where the
     *   allowed hosts and the answers are; askToPlay asks hostQuestion and answers true for "Play anyway"
     * @returns {{player: NotebookPlayer} | {refusal: string}} the refusal is a plain message to show
     */
    static of(untrustedScenarios, deps) {
        const delegate = { runStep: null };
        const made = ScenarioPlayer.of(untrustedScenarios, (step) => delegate.runStep(step));
        if (undefined !== made.refusal) {
            return { refusal: PLAYER_REFUSALS.noScenarios === made.refusal ? made.refusal : NOTEBOOK_PLAYER_TEXTS.brokenScenarios(made.refusal) };
        }
        const player = new NotebookPlayer(made.player, deps);
        delegate.runStep = (step) => player.#runOneStep(step);

        return { player };
    }

    constructor(scenarioPlayer, { browser, storage, pinroundOrigin, activeTabId, askToPlay, captureToNotebook, onStepStart = () => undefined, clock = undefined }) {
        this.#scenarioPlayer = scenarioPlayer;
        this.#browser = browser;
        this.#storage = storage;
        this.#pinroundOrigin = pinroundOrigin;
        this.#activeTabId = activeTabId;
        this.#askToPlay = askToPlay;
        this.#captureToNotebook = captureToNotebook;
        this.#onStepStart = onStepStart;
        this.#clock = clock;
    }

    get scenarioIndex() {
        return this.#scenarioPlayer.scenarioIndex;
    }

    get scenarioCount() {
        return this.#scenarioPlayer.scenarioCount;
    }

    get scenario() {
        return this.#scenarioPlayer.scenario;
    }

    get scenarioTitles() {
        return this.#scenarioPlayer.scenarios.map((scenario) => scenario.title);
    }

    get say() {
        return this.#scenarioPlayer.say;
    }

    get isDone() {
        return this.#scenarioPlayer.isDone;
    }

    /** True from the press of Play or Step, through the host checks and the question, until the run ends. */
    get isRunning() {
        return this.#isPreparing || this.#scenarioPlayer.isRunning;
    }

    get lastStop() {
        return this.#scenarioPlayer.lastStop;
    }

    /**
     * Runs the scenario to its end, a pause step or a stop; at its end, from its start again.
     *
     * @returns {Promise<{refused: string} | {ended: 'done' | 'paused' | 'stopped'}>}
     */
    async play() {
        return this.#run(() => this.#scenarioPlayer.play());
    }

    /** @returns {Promise<{refused: string} | {ended: 'stepped' | 'done' | 'stopped'}>} */
    async step() {
        return this.#run(() => this.#scenarioPlayer.step());
    }

    /** @returns {Promise<{refused: string} | {moved: true}>} */
    async next() {
        return this.#move(() => this.#scenarioPlayer.next());
    }

    /** @returns {Promise<{refused: string} | {moved: true}>} */
    async back() {
        return this.#move(() => this.#scenarioPlayer.back());
    }

    /** Stops a run before its next step; the step running now ends first. Nothing happens when no run is going. */
    stop() {
        if (this.isRunning) {
            this.#isStopAsked = true;
        }
    }

    /** For the popup's close: the page keeps no highlight of a player nobody sees. */
    async close() {
        this.#isStopAsked = true;
        await this.#clearHighlight();
    }

    async #move(move) {
        if (this.isRunning) {
            return { refused: PLAYER_REFUSALS.busy };
        }
        await this.#clearHighlight();

        return move();
    }

    async #run(run) {
        if (this.isRunning) {
            return { refused: PLAYER_REFUSALS.busy };
        }
        this.#isPreparing = true;
        this.#isStopAsked = false;
        try {
            const refusal = await this.#refusalBeforeRun(await this.#activeTabId());
            if (null !== refusal) {
                return { refused: refusal };
            }
            if (this.#scenarioPlayer.isDone) {
                this.#scenarioPlayer.restart();
            }
        } finally {
            this.#isPreparing = false;
        }
        const ended = await run();
        if ('done' === ended.ended || 'stopped' === ended.ended) {
            await this.#clearHighlight();
        }

        return ended;
    }

    /** Why the run may not start, after asking the person about each host not asked about yet, or null. */
    async #refusalBeforeRun(tabId) {
        const rules = { allowedHosts: await loadAllowedHosts(this.#storage), pinroundOrigin: this.#pinroundOrigin };
        const scenario = this.#scenarioPlayer.scenario;
        const scenarioUrls = [scenario.start_url, ...scenario.steps.filter((step) => 'go_to' === step.type).map((step) => step.url)];
        for (const url of scenarioUrls) {
            const refusal = playRefusalFor(rules, url);
            if (null !== refusal) {
                return refusal;
            }
        }
        const tab = await this.#browser.tabs.get(tabId);
        const tabRefusal = tabRefusalOf(rules, tab);
        if (null !== tabRefusal) {
            return tabRefusal;
        }
        // A tab still loading acts on its pendingUrl's page first, so that host is asked about too. The go_to urls are
        // asked about here, as a run cannot stop to ask, even though scenario-rules.js keeps them on start_url's host.
        for (const site of uniqueSites([...scenarioUrls, tab.url, tab.pendingUrl])) {
            if (await isConfirmedSite(this.#storage, site)) {
                continue;
            }
            if (true !== await this.#askToPlay(hostTextOf(site))) {
                return NOTEBOOK_PLAYER_TEXTS.notPlayed;
            }
            await confirmSite(this.#storage, site);
        }
        this.#playingTabId = tabId;
        this.#runStep = stepRunnerFor(this.#browser, {
            tabId,
            openUrl: async (url) => {
                const refusal = playRefusalFor(rules, url);
                if (null !== refusal) {
                    throw new StepRefusal(refusal);
                }
                await this.#browser.tabs.update(tabId, { url });
            },
            captureToNotebook: () => this.#captureToNotebook(tabId, captureTexts(scenario.title, this.#scenarioPlayer.say ?? '')),
            pageRefusalOf: (loadedTab) => tabRefusalOf(rules, loadedTab),
            ...(undefined === this.#clock ? {} : { clock: this.#clock }),
        });

        return null;
    }

    async #runOneStep(step) {
        if (this.#isStopAsked) {
            return { stopped: true, message: NOTEBOOK_PLAYER_TEXTS.stopped };
        }
        this.#onStepStart(step);

        return this.#runStep(step);
    }

    async #clearHighlight() {
        if (null === this.#playingTabId) {
            return;
        }
        try {
            await clearPageHighlight(this.#browser, this.#playingTabId);
        } catch {
            // A tab that is gone or left for a page out of reach holds no highlight of ours to clear.
        }
    }
}

/**
 * The tab is checked by its address and by the one it is loading, as a page that is leaving may land elsewhere. A tab
 * whose address the extension may not see is refused too.
 */
function tabRefusalOf(rules, tab) {
    const refusal = playRefusalFor(rules, tab?.url);
    if (null !== refusal || undefined === tab.pendingUrl) {
        return refusal;
    }

    return playRefusalFor(rules, tab.pendingUrl);
}

function uniqueSites(urls) {
    const sites = new Map();
    for (const site of urls.map(siteOf)) {
        if (null !== site) {
            sites.set(`${site.scheme}://${site.host}:${site.port}`, site);
        }
    }

    return [...sites.values()];
}
