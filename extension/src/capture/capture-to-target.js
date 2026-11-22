// One press of "Capture this page": the tab with its overlay's pins goes to the chosen target. The pins leave the page
// only once the picture is saved there (closePinOverlay); a refused capture leaves them for the next try.
import { ApiError, NOT_FOUND } from '../shared/api.js';
import { MAX_SCREENSHOTS, addToDraftRound, listDraftScreenshots, upload } from './capture.js';
import { NEW_REPORT, TargetGoneError, goneMessageOf } from './capture-target.js';
import { addCaptureToNewReport, checkedNewReportCapture, loadNewReport } from './new-report.js';
import { captureToNotebook } from './notebook-capture.js';
import { capturePinnedTab, closePinOverlay } from './page-pins.js';
import { captureToReportDraft } from './report-draft-capture.js';
import { shownText } from './untrusted-text.js';

/** The retake picker's value for a new screenshot; every other value is the screenshot_id to retake. */
export const NEW_SCREENSHOT = '';
export const NEW_SCREENSHOT_CHOICE = Object.freeze({ value: NEW_SCREENSHOT, label: 'New screenshot' });
const MAX_SHOWN_TITLE_LENGTH = 120;

/**
 * @param {{api: import('../shared/api.js').PinroundApi, browser: object, storage: object, tabId: number,
 *   target: import('./capture-target.js').CaptureTarget, typed: {project: string, reportTitle: string},
 *   texts: {title?: string, summary?: string}, retakeOf?: string|null}} capture `retakeOf` is the screenshot_id the
 *   picture retakes in a draft round target, null for a new screenshot; other targets do not use it
 * @returns {Promise<string>} what to tell the person
 * @throws {Error} a plain refusal, pinround's own reason for a refused retake among them; a TargetGoneError when the
 *   target is no longer the person's
 */
export async function captureToTarget({ api, browser, storage, tabId, target, typed, texts, retakeOf = null }) {
    if (NEW_REPORT === target.kind) {
        return captureToNewReport({ api, browser, storage, tabId, typed, texts });
    }
    if ('notebook' === target.kind) {
        return captureToNotebook(api, browser, target, tabId, texts);
    }
    if ('report' === target.kind) {
        return captureToReportDraft(api, browser, target, tabId, texts);
    }
    const { file, pins } = await capturePinnedTab(browser, tabId);
    const goneOr = (error) => (error instanceof ApiError && NOT_FOUND === error.outcome ? new TargetGoneError(goneMessageOf(target), target) : error);
    let uploadId;
    try {
        uploadId = await upload(api, { ticket: target.ticketKey }, file);
    } catch (error) {
        throw goneOr(error);
    }
    try {
        await addToDraftRound(api, target.ticketKey, uploadId, { ...texts, pins, retakeOf });
    } catch (error) {
        // pinround answers a retake of a screenshot no longer in the draft as it answers a gone draft: the upload just
        // found the ticket, so the target stays and the person sees pinround's reason.
        throw null === retakeOf ? goneOr(error) : error;
    }
    await closePinOverlay(browser, tabId);

    return null === retakeOf ? `Added to your draft round on ${target.ticketKey}. Only you see it.` : `Retake added to your draft round on ${target.ticketKey}. Only you see it.`;
}

/**
 * The retake picker's options for the person's draft round on the ticket: "New screenshot" first, then one per
 * screenshot of the draft in its order. A list that cannot be loaded leaves only "New screenshot", with what went
 * wrong in `loadProblem`; a new screenshot needs no list.
 *
 * @returns {Promise<{choices: Array<{value: string, label: string}>, loadProblem: string|null}>} labels are plain
 *   text, for an option's text only
 */
export async function retakeChoices(api, ticketKey) {
    let screenshots;
    try {
        screenshots = await listDraftScreenshots(api, ticketKey);
    } catch (error) {
        return { choices: [NEW_SCREENSHOT_CHOICE], loadProblem: `Your screenshots to retake could not be loaded. ${error instanceof ApiError ? error.message : ''}`.trim() };
    }
    const retakes = screenshots.map((screenshot) => ({
        value: screenshot.screenshotId,
        label: `Retake of screenshot ${screenshot.number}${'' === screenshot.title ? '' : ` · ${shownText(screenshot.title, MAX_SHOWN_TITLE_LENGTH)}`}`,
    }));

    return { choices: [NEW_SCREENSHOT_CHOICE, ...retakes], loadProblem: null };
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
