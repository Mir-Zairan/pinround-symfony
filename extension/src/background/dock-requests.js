// What the dock on a page (src/overlay/dock.js) asks the extension for, answered in the service worker: the page's
// isolated world may not capture a tab, call pinround with the token or read session storage. Each answer is what the
// popup does for the same press, with the same storage, so the popup and the dock always show one target and one draft.
import { ApiError, PinroundApi } from '../shared/api.js';
import { loadSettings } from '../shared/settings.js';
import { captureTexts, listDraftScreenshots } from '../capture/capture.js';
import { CHOOSE_TARGET_FIRST, NEW_REPORT, TargetGoneError, choiceValueOf, forgetCaptureTarget, loadCaptureTarget, saveCaptureTarget, targetChoices } from '../capture/capture-target.js';
import { captureToTarget } from '../capture/capture-to-target.js';
import { REPORT_KEPT_IN_PINROUND, REPORT_OPENED, finishNewReport, loadNewReport } from '../capture/new-report.js';
import { browserNameAndVersion, pageContext } from '../capture/page-context.js';

export const DOCK_ACTIONS = Object.freeze(['state', 'choose', 'capture', 'save-draft']);

export const DOCK_TEXTS = Object.freeze({
    notPaired: 'Connect the extension to your pinround first: open its options.',
    unknownRequest: 'The dock asked for something this extension does not do. Reload the page.',
    failed: 'Something went wrong in the extension. Try again.',
    choiceGone: 'That choice is no longer offered. Choose again.',
    nothingToSave: 'Capture into a new report first. Draft rounds and notebooks keep each capture as you take it.',
});

/**
 * @typedef {{choices: Array<{value: string, label: string}>, chosen: string, count: number|null, canSave: boolean}} DockState
 *   count is the screenshots the chosen draft holds, null when the dock cannot tell (a notebook, or a list that did not
 *   load); canSave is true only for a new report that has its draft and no capture still on its way
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
 * @param {{browser: object, navigator: object, apiOf?: (settings: {origin: string, token: string}) => PinroundApi}} deps
 * @returns {Promise<DockAnswer>} never rejects: a refusal is an answer with isError
 */
export async function answerDockRequest(request, tab, { browser, navigator, apiOf = (settings) => new PinroundApi(settings.origin, settings.token) }) {
    if (!DOCK_ACTIONS.includes(request.pinroundDock) || !Number.isInteger(tab?.id)) {
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
            return await capture({ api, browser, session, tab, navigator });
        }
        if ('save-draft' === request.pinroundDock) {
            return await saveDraft(api, browser, session, settings.origin);
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
    const { count, canSave } = await draftCountOf(api, session, target);

    return {
        state: { choices: choices.map(({ value, label }) => ({ value, label })), chosen: null === target ? '' : choiceValueOf(target), count, canSave },
        problems: [droppedMessage, ...loadProblems].filter((problem) => null !== problem),
    };
}

async function draftCountOf(api, session, target) {
    if (null === target) {
        return { count: null, canSave: false };
    }
    if (NEW_REPORT === target.kind) {
        const newReport = await loadNewReport(session);

        return { count: newReport.screenshotCount, canSave: null !== newReport.draftId && null === newReport.pendingCapture };
    }
    if ('ticket' === target.kind) {
        const screenshots = await listDraftScreenshots(api, target.ticketKey).catch((error) => {
            if (error instanceof ApiError) {
                return null;
            }
            throw error;
        });

        return { count: null === screenshots ? null : screenshots.length, canSave: false };
    }

    return { count: null, canSave: false };
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

async function capture({ api, browser, session, tab, navigator }) {
    const target = await loadCaptureTarget(session);
    if (null === target) {
        return { message: CHOOSE_TARGET_FIRST, isError: true, state: await dockState(api, session) };
    }
    // The dock has no fields to type in: a new report takes what the popup kept, and the texts start from the page.
    const newReport = await loadNewReport(session);
    const typed = { project: newReport.project, reportTitle: newReport.reportTitle };
    const { title, summary } = pageContext(tab, await browserNameAndVersion(navigator).catch(() => ''));
    const message = await captureToTarget({ api, browser, storage: session, tabId: tab.id, target, typed, texts: captureTexts(title, summary) });

    return { message, state: await dockState(api, session) };
}

async function saveDraft(api, browser, session, pinroundOrigin) {
    const target = await loadCaptureTarget(session);
    if (null === target || NEW_REPORT !== target.kind || null === (await loadNewReport(session)).draftId) {
        return { message: DOCK_TEXTS.nothingToSave, isError: true, state: await dockState(api, session) };
    }
    const isOpened = await finishNewReport(session, browser, pinroundOrigin);

    return { message: isOpened ? REPORT_OPENED : REPORT_KEPT_IN_PINROUND, state: await dockState(api, session) };
}

async function forgetIfRemembered(session, goneTarget) {
    const remembered = await loadCaptureTarget(session);
    if (null !== remembered && choiceValueOf(remembered) === choiceValueOf(goneTarget)) {
        await forgetCaptureTarget(session);
    }
}
