// What the dock's Play needs from the service worker, which alone may call pinround with the token and move the tab:
// one scenario of the chosen notebook, checked and refused on a host not allowed as the popup's player refuses it
// (notebook-player.js), the start page opened with the dock again on it, and a capture's texts tagged with the step
// "Found a problem" named. The run itself (step, results, tag) is kept by the dock in the extension's local storage
// (src/overlay/dock-run.js); nothing here holds it.
import '../overlay/dock-run.js';
import { SUMMARY_MAX_LENGTH, TITLE_MAX_LENGTH as CAPTURE_TITLE_MAX_LENGTH } from '../capture/capture.js';
import { loadCaptureTarget } from '../capture/capture-target.js';
import { cutToFit, oneLinePageText } from '../capture/page-context.js';
import { openPageDock } from '../capture/page-pins.js';
import { shownText } from '../capture/untrusted-text.js';
import { NOTEBOOK_PLAYER_TEXTS, loadNotebookScenarios } from '../player/notebook-player.js';
import { PLAYER_REFUSALS } from '../player/player.js';
import { checkScenarios } from '../player/scenario-rules.js';
import { PAGE_STEP_TIMEOUT_MS, loadedTab } from '../player/step-runner.js';
import { loadAllowedHosts, playRefusalFor } from '../shared/allowed-hosts.js';

const runRules = globalThis.pinroundDockRun;

export const DOCK_SCENARIO_TEXTS = Object.freeze({
    chooseNotebook: 'Choose a notebook in "Put it in" to play its scenarios.',
    noSuchScenario: 'The notebook has no more scenarios. Press Play to start again.',
    startNotLoaded: `The start page did not finish loading in ${PAGE_STEP_TIMEOUT_MS / 1000} seconds. Open the dock again once it shows.`,
});

/**
 * @param {{api: object, browser: object, session: object, pinroundOrigin: string}} deps session holds the chosen target
 * @param {number} scenarioIndex counted from 0, below MAX_SCENARIOS
 * @returns {Promise<{scenario: object} | {message: string, isError: true}>} the scenario as dock-run.js checkedScenario
 *   reads it, or the refusal; pinround's own refusal rejects as an ApiError
 */
export async function scenarioToPlay({ api, browser, session, pinroundOrigin }, scenarioIndex) {
    const target = await loadCaptureTarget(session);
    if (null === target || 'notebook' !== target.kind) {
        return { message: DOCK_SCENARIO_TEXTS.chooseNotebook, isError: true };
    }
    const checked = checkScenarios(await loadNotebookScenarios(api, target.notebookId));
    if (undefined !== checked.refusal) {
        return { message: NOTEBOOK_PLAYER_TEXTS.brokenScenarios(checked.refusal), isError: true };
    }
    if (0 === checked.scenarios.length) {
        return { message: PLAYER_REFUSALS.noScenarios, isError: true };
    }
    if (scenarioIndex >= checked.scenarios.length) {
        return { message: DOCK_SCENARIO_TEXTS.noSuchScenario, isError: true };
    }
    const scenario = checked.scenarios[scenarioIndex];
    const rules = { allowedHosts: await loadAllowedHosts(browser.storage.local), pinroundOrigin };
    for (const url of [scenario.start_url, ...scenario.steps.filter((step) => 'go_to' === step.type).map((step) => step.url)]) {
        const refusal = playRefusalFor(rules, url);
        if (null !== refusal) {
            return { message: refusal, isError: true };
        }
    }

    return {
        scenario: {
            notebookId: target.notebookId,
            scenarioIndex,
            scenarioCount: checked.scenarios.length,
            title: shownText(scenario.title, runRules.TITLE_MAX_LENGTH),
            startUrl: scenario.start_url,
            steps: scenario.steps.map((step) => ({ say: shownText(step.say, runRules.SAY_MAX_LENGTH), expected: expectedTextOf(step) })),
        },
    };
}

function targetTextOf(target) {
    if (Object.hasOwn(target, 'role')) {
        return Object.hasOwn(target, 'name') ? `The ${target.role} "${target.name}"` : `The ${target.role}`;
    }

    return Object.hasOwn(target, 'label') ? `The field labelled "${target.label}"` : `The text "${target.text}"`;
}

/**
 * What the step card shows as Expected: the check an expect_text, expect_visible or wait_for step makes, and '' for a
 * step that checks nothing. The person checks it by eye; the dock runs no step.
 *
 * @param {object} step a step that checkScenarios accepted
 * @returns {string} at most EXPECTED_MAX_LENGTH characters, its control and direction characters written out
 */
export function expectedTextOf(step) {
    let expected = '';
    if ('expect_text' === step.type) {
        expected = undefined === step.target ? `The page shows "${step.text}".` : `${targetTextOf(step.target)} shows "${step.text}".`;
    } else if ('expect_visible' === step.type || 'wait_for' === step.type) {
        expected = `${targetTextOf(step.target)} is visible.`;
    }

    return shownText(expected, runRules.EXPECTED_MAX_LENGTH);
}

/**
 * The capture's texts with the step "Found a problem" was pressed on: the step as its title, the scenario before the
 * page's own summary, each on one line and cut to pinround's caps.
 *
 * @param {{title?: string, summary?: string}} pageTexts as captureTexts made them from the page
 * @param {import('../overlay/dock-run.js').StepTag} tag checked with dock-run.js checkedTag
 * @returns {{title: string, summary: string}}
 */
export function taggedCaptureTexts(pageTexts, tag) {
    const scenarioLine = `Found a problem in "${oneLinePageText(tag.scenarioTitle)}", step ${tag.stepNumber}.`;

    return {
        title: cutToFit(`Step ${tag.stepNumber}: ${oneLinePageText(tag.say)}`, CAPTURE_TITLE_MAX_LENGTH),
        summary: cutToFit(undefined === pageTexts.summary ? scenarioLine : `${scenarioLine} ${pageTexts.summary}`, SUMMARY_MAX_LENGTH),
    };
}

/**
 * Opens the scenario's start page in the dock's tab, after the same host checks as Play, and opens the dock again on
 * it once it has loaded, where it shows the run's first step from storage. A start page on another origin may be out
 * of the extension's reach (activeTab ends there); then the person opens the dock from the popup.
 *
 * @param {{browser: object, tab: {id: number}, pinroundOrigin: string, clock: {now: () => number, wait: (ms: number) => Promise<void>}}} deps
 * @param {string} url
 * @returns {Promise<{message?: string, isError?: boolean}>}
 * @throws {Error} CANNOT_DOCK_PAGE when the loaded page is out of reach
 */
export async function openStartPage({ browser, tab, pinroundOrigin, clock }, url) {
    const refusal = playRefusalFor({ allowedHosts: await loadAllowedHosts(browser.storage.local), pinroundOrigin }, url);
    if (null !== refusal) {
        return { message: refusal, isError: true };
    }
    await browser.tabs.update(tab.id, { url });
    if (null === await loadedTab(browser, tab.id, clock, clock.now() + PAGE_STEP_TIMEOUT_MS)) {
        return { message: DOCK_SCENARIO_TEXTS.startNotLoaded, isError: true };
    }
    await openPageDock(browser, tab.id);

    return {};
}
