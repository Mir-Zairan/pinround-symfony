// A report draft pinround already has as a capture target: each capture is uploaded for the draft's project and added
// at the draft's end with POST /api/v1/reports/{id}/screenshots, as add_report_screenshot does. pinround holds the
// cap of 10 pictures and the owner rule; its refusal is shown as it words it.
import { ApiError, MISSING_SCOPE, NOT_FOUND } from '../shared/api.js';
import { MAX_SCREENSHOTS, upload } from './capture.js';
import { TargetGoneError, goneMessageOf } from './capture-target.js';
import { REPORTS_WRITE_MISSING } from './new-report.js';
import { capturePinnedTab, closePinOverlay } from './page-pins.js';
import { shownText } from './untrusted-text.js';

const MAX_SHOWN_TITLE_LENGTH = 120;

function refusalOf(error, target) {
    if (error instanceof ApiError && MISSING_SCOPE === error.outcome) {
        return new Error(REPORTS_WRITE_MISSING);
    }
    // The upload finds no project, or the add no draft of the person's: either way the draft takes no more captures.
    if (error instanceof ApiError && NOT_FOUND === error.outcome) {
        return new TargetGoneError(goneMessageOf(target), target);
    }

    return error;
}

/**
 * Uploads the picture for the draft's project, then adds it at the draft's end with its texts and pins.
 *
 * @param {{kind: 'report', draftId: string, title: string, project: string}} target
 * @param {Blob} file
 * @param {{title?: string, summary?: string, pins: Array<{x_pct: number, y_pct: number, note: string}>}} screenshotFields
 * @returns {Promise<number>} how many pictures the draft holds now
 * @throws {Error} REPORTS_WRITE_MISSING; a TargetGoneError when the draft is not the person's any more; pinround's
 *   refusal as it words it, such as a full draft
 */
export async function addToReportDraft(api, target, file, screenshotFields) {
    let answer;
    try {
        const uploadId = await upload(api, { project: target.project }, file);
        answer = await api.call('POST', `/api/v1/reports/${encodeURIComponent(target.draftId)}/screenshots`, { upload_id: uploadId, ...screenshotFields });
    } catch (error) {
        throw refusalOf(error, target);
    }
    if (!Number.isInteger(answer.screenshot_count)) {
        throw new Error('pinround answered in a way this extension does not understand. Update the extension.');
    }

    return Math.min(answer.screenshot_count, MAX_SCREENSHOTS);
}

/**
 * Captures the tab with its overlay's pins into the report draft; the pins leave the page once the picture is saved,
 * and stay on it when pinround refuses.
 *
 * @returns {Promise<string>} what to tell the person
 */
export async function captureToReportDraft(api, browser, target, tabId, texts) {
    const { file, pins } = await capturePinnedTab(browser, tabId);
    const screenshotCount = await addToReportDraft(api, target, file, { ...texts, pins });
    await closePinOverlay(browser, tabId);

    return reportDraftSavedMessage(target.title, screenshotCount);
}

/** @returns {string} the status once the report draft titled `title` holds `screenshotCount` pictures */
export function reportDraftSavedMessage(title, screenshotCount) {
    const shown = shownText(title, MAX_SHOWN_TITLE_LENGTH);
    if (screenshotCount >= MAX_SCREENSHOTS) {
        return `Added to "${shown}". The report draft is full: open it in pinround to send it.`;
    }

    return `Added to "${shown}". It holds ${screenshotCount} of ${MAX_SCREENSHOTS} captures.`;
}
