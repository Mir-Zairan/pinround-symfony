// One press of "Capture this page": the tab with its overlay's pins goes to the chosen target. The pins leave the page
// only once the picture is saved there (closePinOverlay); a refused capture leaves them for the next try.
import { ApiError, NOT_FOUND } from '../shared/api.js';
import { MAX_SCREENSHOTS, addToDraftRound, upload } from './capture.js';
import { NEW_REPORT, TargetGoneError, goneMessageOf } from './capture-target.js';
import { addCaptureToNewReport, checkedNewReportCapture, loadNewReport } from './new-report.js';
import { captureToNotebook } from './notebook-capture.js';
import { capturePinnedTab, closePinOverlay } from './page-pins.js';

/**
 * @param {{api: import('../shared/api.js').PinroundApi, browser: object, storage: object, tabId: number,
 *   target: import('./capture-target.js').CaptureTarget, typed: {project: string, reportTitle: string},
 *   texts: {title?: string, summary?: string}}} capture
 * @returns {Promise<string>} what to tell the person
 * @throws {Error} a plain refusal; a TargetGoneError when the target is no longer the person's
 */
export async function captureToTarget({ api, browser, storage, tabId, target, typed, texts }) {
    if (NEW_REPORT === target.kind) {
        return captureToNewReport({ api, browser, storage, tabId, typed, texts });
    }
    if ('notebook' === target.kind) {
        return captureToNotebook(api, browser, target, tabId, texts);
    }
    const { file, pins } = await capturePinnedTab(browser, tabId);
    try {
        const uploadId = await upload(api, { ticket: target.ticketKey }, file);
        await addToDraftRound(api, target.ticketKey, uploadId, { ...texts, pins });
    } catch (error) {
        throw error instanceof ApiError && NOT_FOUND === error.outcome ? new TargetGoneError(goneMessageOf(target), target) : error;
    }
    await closePinOverlay(browser, tabId);

    return `Added to your draft round on ${target.ticketKey}. Only you see it.`;
}

async function captureToNewReport({ api, browser, storage, tabId, typed, texts }) {
    checkedNewReportCapture(await loadNewReport(storage), typed);
    const { file, pins } = await capturePinnedTab(browser, tabId);
    const saved = await addCaptureToNewReport(storage, api, typed, { file, texts, pins });
    await closePinOverlay(browser, tabId);

    return newReportSavedMessage(saved.screenshotCount);
}

/** @returns {string} the status once the report draft holds `screenshotCount` pictures */
export function newReportSavedMessage(screenshotCount) {
    if (1 === screenshotCount) {
        return 'Report draft made with screenshot 1. Capture more pages, or press Done to open it.';
    }
    if (screenshotCount >= MAX_SCREENSHOTS) {
        return `Screenshot ${screenshotCount} added. The report draft is full: press Done to open it.`;
    }

    return `Screenshot ${screenshotCount} added to the report draft. Capture more pages, or press Done to open it.`;
}
