// What the dock on a page (src/overlay/dock.js) asks the extension for, answered in the service worker: the page's
// isolated world may not capture a tab, call pinround with the token or read session storage. Each answer is what the
// popup does for the same press, with the same storage, so the popup and the dock always show one target and one draft.
import { ApiError } from '../shared/api.js';
import { pinroundApiOf } from '../shared/oauth-connection.js';
import { loadSettings } from '../shared/settings.js';
import { captureTexts, listDraftScreenshots } from '../capture/capture.js';
import { CHOOSE_TARGET_FIRST, NEW_REPORT, TargetGoneError, choiceValueOf, forgetCaptureTarget, loadCaptureTarget, saveCaptureTarget, targetChoices } from '../capture/capture-target.js';
import { captureToTarget } from '../capture/capture-to-target.js';
import { clearNewReport, loadNewReport } from '../capture/new-report.js';
import { pageTitleOf } from '../capture/page-context.js';
import { capturedMessage, draftSavedMessage, keepWaitingCapture, loadWaitingCaptures, saveWaitingCaptures } from '../capture/waiting-captures.js';
import { SYSTEM_CLOCK } from '../player/step-runner.js';
import { openStartPage, scenarioToPlay, taggedCaptureTexts } from './dock-scenario.js';

const runRules = globalThis.pinroundDockRun;

export const DOCK_ACTIONS = Object.freeze(['state', 'choose', 'capture', 'save-draft', 'scenario', 'open-start']);

export const DOCK_TEXTS = Object.freeze({
    notPaired: 'Connect the extension to your pinround first: open its options.',
    unknownRequest: 'The dock asked for something this extension does not do. Reload the page.',
    failed: 'Something went wrong in the extension. Try again.',
    choiceGone: 'That choice is no longer offered. Choose again.',
    nothingToSave: 'Capture into a new report first. Draft rounds and notebooks keep each capture as you take it.',
});
/** The longest title or project key the dock may send with Save draft; new-report.js holds the real caps. */
const TYPED_MAX_LENGTH = 1000;

/**
 * @typedef {{shots: Array<{pinSpots: Array<{x_pct: number, y_pct: number}>}>, needsTitle: boolean, needsProject: boolean}} WaitingReport
 *   the captures waiting for Save draft, with where their pins are; needsTitle while no draft is made, needsProject
 *   when the popup has no project key either
 * @typedef {{choices: Array<{value: string, label: string}>, chosen: string, count: number|null, canSave: boolean,
 *   newReport: WaitingReport|null}} DockState count is the captures waiting for a new report, the screenshots of the
 *   chosen draft round, or null when the dock cannot tell (a notebook, or a list that did not load); canSave is true
 *   only for a new report with a capture waiting or one to send again; newReport only while a new report is chosen
 * @typedef {{message?: string, isError?: boolean, state?: DockState}} DockAnswer
 */

/** True for a message the dock sends; the service worker leaves every other message alone. */
export function isDockRequest(message) {
    return null !== message && 'object' === typeof message && 'string' === typeof message.pinroundDock;
}

/**
 * @param {{pinroundDock: string, value?: unknown}} request
 * @param {{id?: number, url?: string, title?: string, width?: number, height?: number}} tab the tab whose dock asked,
 *   as Chrome names the sender
 * @param {{browser: object, apiOf?: (settings: {origin: string, token: string}) => import('../shared/api.js').PinroundApi,
 *   clock?: {now: () => number, wait: (ms: number) => Promise<void>}}} deps the clock, in ms, times the wait for the
 *   start page to load; the system's by default
 * @returns {Promise<DockAnswer>} never rejects: a refusal is an answer with isError. A scenario request answers
 *   {scenario} (dock-scenario.js) instead of a state
 */
export async function answerDockRequest(request, tab, { browser, apiOf = (settings) => pinroundApiOf(settings, browser.storage.local), clock = SYSTEM_CLOCK }) {
    if (!DOCK_ACTIONS.includes(request.pinroundDock) || !Number.isInteger(tab?.id) || !isReadableValue(request)) {
        return { message: DOCK_TEXTS.unknownRequest, isError: true };
    }
    const settings = await loadSettings(browser.storage.local).catch(() => undefined);
    if (null === settings) {
        return { message: DOCK_TEXTS.notPaired, isError: true };
    }
    if (undefined === settings) {
        return { message: DOCK_TEXTS.failed, isError: true };
    }
    const api = apiOf(settings);
    const session = browser.storage.session;
    try {
        if ('choose' === request.pinroundDock) {
            return await choose(api, session, request.value);
        }
        if ('capture' === request.pinroundDock) {
            return await capture({ api, browser, session, tab, stepTag: runRules.checkedTag(request.value ?? null) });
        }
        if ('save-draft' === request.pinroundDock) {
            return await saveDraft(api, session, request.value);
        }
        if ('scenario' === request.pinroundDock) {
            return await scenarioToPlay({ api, browser, session, pinroundOrigin: settings.origin }, request.value);
        }
        if ('open-start' === request.pinroundDock) {
            return await openStartPage({ browser, tab, pinroundOrigin: settings.origin, clock }, request.value);
        }

        return await stateAnswer(api, session);
    } catch (error) {
        if (error instanceof TargetGoneError) {
            await forgetIfRemembered(session, error.target).catch(() => undefined);
        }
        const state = await dockState(api, session).catch(() => undefined);

        return { message: error instanceof Error ? error.message : DOCK_TEXTS.failed, isError: true, ...(undefined === state ? {} : { state }) };
    }
}

/**
 * Whether the request's value is one its action takes: a step tag or none for a capture, a scenario's index for a
 * scenario, a web address within the run's cap for open-start. The dock's own script sends them, but the value is
 * checked before anything acts on it.
 */
function isReadableValue({ pinroundDock: action, value }) {
    if ('capture' === action) {
        return undefined === value || null === value || null !== runRules.checkedTag(value);
    }
    if ('scenario' === action) {
        return Number.isInteger(value) && value >= 0 && value < runRules.MAX_SCENARIOS;
    }
    if ('open-start' === action) {
        return 'string' === typeof value && value.length <= runRules.URL_MAX_LENGTH;
    }
    if ('save-draft' === action) {
        return null !== value && 'object' === typeof value && isTypedText(value.reportTitle) && isTypedText(value.project);
    }

    return true;
}

const isTypedText = (value) => 'string' === typeof value && value.length <= TYPED_MAX_LENGTH;

/** The dock's shorter words for the popup's choices, as its narrow picker shows them. */
function dockLabelOf({ value, label }) {
    if (NEW_REPORT === value) {
        return 'New report';
    }

    return label.replace(/^My draft round on /u, 'My draft round · ').replace(/^Notebook: /u, 'Notebook · ');
}

async function stateAnswer(api, session) {
    const { state, problems } = await dockStateWithProblems(api, session);

    return { state, ...(problems.length > 0 ? { message: problems.join(' '), isError: true } : {}) };
}

/** @returns {Promise<DockState>} */
async function dockState(api, session) {
    return (await dockStateWithProblems(api, session)).state;
}

async function dockStateWithProblems(api, session) {
    const { choices, target, droppedMessage, loadProblems } = await targetChoices(api, await loadCaptureTarget(session));
    if (null !== droppedMessage) {
        await forgetCaptureTarget(session);
    }
    const { count, canSave, newReport } = await draftCountOf(api, session, target);

    return {
        state: { choices: choices.map((choice) => ({ value: choice.value, label: dockLabelOf(choice) })), chosen: null === target ? '' : choiceValueOf(target), count, canSave, newReport },
        problems: [droppedMessage, ...loadProblems].filter((problem) => null !== problem),
    };
}

async function draftCountOf(api, session, target) {
    if (null === target) {
        return { count: null, canSave: false, newReport: null };
    }
    if (NEW_REPORT === target.kind) {
        const newReport = await loadNewReport(session);
        const waiting = await loadWaitingCaptures(session);
        const needsTitle = null === newReport.draftId && null === newReport.pendingCapture;

        return {
            count: waiting.length,
            canSave: waiting.length > 0 || null !== newReport.pendingCapture,
            newReport: {
                shots: waiting.map((shot) => ({ pinSpots: shot.pins.map(({ x_pct, y_pct }) => ({ x_pct, y_pct })) })),
                needsTitle,
                needsProject: needsTitle && '' === newReport.project.trim(),
            },
        };
    }
    if ('ticket' === target.kind) {
        const screenshots = await listDraftScreenshots(api, target.ticketKey).catch((error) => {
            if (error instanceof ApiError) {
                return null;
            }
            throw error;
        });

        return { count: null === screenshots ? null : screenshots.length, canSave: false, newReport: null };
    }

    return { count: null, canSave: false, newReport: null };
}

/** Remembers the picked target when pinround still offers it; '' forgets the choice. */
async function choose(api, session, value) {
    if ('' === value) {
        await forgetCaptureTarget(session);

        return stateAnswer(api, session);
    }
    const { choices } = await targetChoices(api, await loadCaptureTarget(session));
    const picked = choices.find((choice) => choice.value === value);
    if (undefined === picked) {
        return { message: DOCK_TEXTS.choiceGone, isError: true, state: await dockState(api, session) };
    }
    await saveCaptureTarget(session, picked.target);

    return stateAnswer(api, session);
}

/** stepTag, from the dock's run, names the step "Found a problem" was pressed on; null for a plain capture. */
async function capture({ api, browser, session, tab, stepTag }) {
    const target = await loadCaptureTarget(session);
    if (null === target) {
        return { message: CHOOSE_TARGET_FIRST, isError: true, state: await dockState(api, session) };
    }
    // The title starts from the page; the summary is the person's own words, which the dock does not ask for.
    const pageTexts = captureTexts(pageTitleOf(tab), '');
    const texts = null === stepTag ? pageTexts : taggedCaptureTexts(pageTexts, stepTag);
    if (NEW_REPORT === target.kind) {
        const waitingCount = await keepWaitingCapture(browser, session, tab.id, texts);

        return { message: capturedMessage(waitingCount), state: await dockState(api, session) };
    }
    const message = await captureToTarget({ api, browser, storage: session, tabId: tab.id, target, typed: { project: '', reportTitle: '' }, texts });

    return { message, state: await dockState(api, session) };
}

/**
 * Saves the captures waiting for the new report as its draft, with the title typed in the dock's Save draft panel and
 * its project key, or the popup's when the panel asked for none. The draft is then forgotten here, as it is done: the
 * person opens and sends it in pinround.
 */
async function saveDraft(api, session, typed) {
    const target = await loadCaptureTarget(session);
    if (null === target || NEW_REPORT !== target.kind) {
        return { message: DOCK_TEXTS.nothingToSave, isError: true, state: await dockState(api, session) };
    }
    const kept = await loadNewReport(session);
    const project = '' === typed.project.trim() ? kept.project : typed.project;
    const saved = await saveWaitingCaptures(session, api, { project, reportTitle: typed.reportTitle });
    await clearNewReport(session);

    return { message: draftSavedMessage(saved.screenshotCount), state: await dockState(api, session) };
}

async function forgetIfRemembered(session, goneTarget) {
    const remembered = await loadCaptureTarget(session);
    if (null !== remembered && choiceValueOf(remembered) === choiceValueOf(goneTarget)) {
        await forgetCaptureTarget(session);
    }
}
