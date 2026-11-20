// "A new report" as a target: the first capture makes the report draft with POST /api/v1/reports, each later one adds
// its picture with POST /api/v1/reports/{id}/screenshots. What the popup must remember between captures lives in the
// extension's session storage: the typed project and title, the draft made, and a capture whose answer never came.
import { ApiError, MISSING_SCOPE, NOT_FOUND } from '../shared/api.js';
import { MAX_SCREENSHOTS, REPORT_TITLE_MAX_LENGTH, upload } from './capture.js';
import { TargetGoneError } from './capture-target.js';

export const NEW_REPORT_KEY = 'pinroundNewReport';
/** Where an earlier version kept upload ids for one "Make the report draft" request; dropped, never sent. */
export const OLD_REPORT_CAPTURES_KEY = 'pinroundReportCaptures';
const PROJECT_KEY_MAX_LENGTH = 20;
// pinround refuses a request body over 1 MB.
const MAX_JSON_BODY_BYTES = 1_000_000;

export const REPORTS_WRITE_MISSING = 'Your pinround token cannot make reports. Make a token that may prepare report drafts on the API tokens page, then pair the extension again.';
export const SEND_AGAIN_FIRST = 'A screenshot has not reached pinround yet. Press Send it again, or Start over.';
export const NOT_SENT = 'The screenshot did not reach pinround. Check your connection and press Send it again.';
export const REPORT_FULL = 'A report holds at most 10 screenshots. Press Done, then start a new report for more.';
export const REPORT_GONE = 'Your report draft is gone from pinround, so the screenshot was not added. Choose where to put it.';
export const OLD_CAPTURES_DROPPED = 'Screenshots captured with an earlier version of the extension were not made into a report. Capture them again.';

/**
 * @typedef {{idempotencyKey: string, path: string, bodyJson: string}} PendingCapture a POST sent, or about to be, whose
 *   answer has not come. bodyJson is the exact text sent: pinround matches a repeat to its key by the bytes.
 * @typedef {{project: string, reportTitle: string, draftId: string|null, draftUrl: string|null, screenshotCount: number,
 *   pendingCapture: PendingCapture|null}} NewReport
 */

function refusalOfUpload(error, project) {
    if (error instanceof ApiError && MISSING_SCOPE === error.outcome) {
        return new Error(REPORTS_WRITE_MISSING);
    }
    if (error instanceof ApiError && NOT_FOUND === error.outcome) {
        return new Error(`pinround has no project ${project} you may report in. Check the project key.`);
    }

    return error;
}

/** @returns {NewReport} */
export const emptyNewReport = () => ({ project: '', reportTitle: '', draftId: null, draftUrl: null, screenshotCount: 0, pendingCapture: null });

const isStringOrNull = (value) => null === value || 'string' === typeof value;

function isPendingCapture(value) {
    return null !== value && 'object' === typeof value && 'string' === typeof value.idempotencyKey && 'string' === typeof value.bodyJson
        && 'string' === typeof value.path && value.path.startsWith('/api/v1/reports');
}

/** @returns {NewReport} what is stored, or an empty new report when the stored shape is not this version's */
function checkedNewReport(stored) {
    if (null === stored || 'object' !== typeof stored || 'string' !== typeof stored.project || 'string' !== typeof stored.reportTitle
        || !isStringOrNull(stored.draftId) || !isStringOrNull(stored.draftUrl)
        || !Number.isInteger(stored.screenshotCount) || stored.screenshotCount < 0 || stored.screenshotCount > MAX_SCREENSHOTS
        || (null !== stored.pendingCapture && !isPendingCapture(stored.pendingCapture))) {
        return emptyNewReport();
    }

    return {
        project: stored.project,
        reportTitle: stored.reportTitle,
        draftId: stored.draftId,
        draftUrl: stored.draftUrl,
        screenshotCount: stored.screenshotCount,
        pendingCapture: null === stored.pendingCapture ? null : { idempotencyKey: stored.pendingCapture.idempotencyKey, path: stored.pendingCapture.path, bodyJson: stored.pendingCapture.bodyJson },
    };
}

/** @returns {Promise<NewReport>} */
export async function loadNewReport(storage) {
    return checkedNewReport((await storage.get(NEW_REPORT_KEY))[NEW_REPORT_KEY] ?? null);
}

async function saveNewReport(storage, newReport) {
    await storage.set({ [NEW_REPORT_KEY]: newReport });
}

export async function clearNewReport(storage) {
    await storage.remove(NEW_REPORT_KEY);
}

/**
 * Removes what an earlier version stored for "Make the report draft"; its upload ids are never sent.
 *
 * @returns {Promise<boolean>} true when it held captures the person made, so they can be told
 */
export async function dropOldReportCaptures(storage) {
    const old = (await storage.get(OLD_REPORT_CAPTURES_KEY))[OLD_REPORT_CAPTURES_KEY];
    if (undefined === old) {
        return false;
    }
    await storage.remove(OLD_REPORT_CAPTURES_KEY);

    return Array.isArray(old?.items) && old.items.length > 0;
}

/** Keeps what the person typed for a report not made yet, so it is there when the popup opens again. */
export async function saveTypedReport(storage, typed) {
    const newReport = await loadNewReport(storage);
    if (null !== newReport.draftId || null !== newReport.pendingCapture) {
        return;
    }
    await saveNewReport(storage, { ...newReport, project: typed.project, reportTitle: typed.reportTitle });
}

/**
 * Refuses, before anything is captured or uploaded, a capture the report cannot take.
 *
 * @param {NewReport} newReport
 * @param {{project: string, reportTitle: string}} typed what the person typed in the popup
 * @returns {{project: string, reportTitle: string}} the project and title the capture is for
 * @throws {Error} with the plain reason
 */
export function checkedNewReportCapture(newReport, typed) {
    if (null !== newReport.pendingCapture) {
        throw new Error(SEND_AGAIN_FIRST);
    }
    if (newReport.screenshotCount >= MAX_SCREENSHOTS) {
        throw new Error(REPORT_FULL);
    }
    if (null !== newReport.draftId) {
        return { project: newReport.project, reportTitle: newReport.reportTitle };
    }
    const project = typed.project.trim().toUpperCase();
    const reportTitle = typed.reportTitle.trim();
    if ('' === project || [...project].length > PROJECT_KEY_MAX_LENGTH) {
        throw new Error('Type the project key, for example SHOP.');
    }
    if ('' === reportTitle || [...reportTitle].length > REPORT_TITLE_MAX_LENGTH) {
        throw new Error('Write what is wrong in one line, at most 200 characters.');
    }

    return { project, reportTitle };
}

/**
 * Uploads the picture for the report's project, then makes the report draft with it (the first capture) or adds it
 * at the draft's end. The token's reports:write scope is checked by that upload's begin: POST /api/v1/uploads with a
 * project refuses with missing_scope before it makes an upload intent, and no route tells a token's scopes for less.
 *
 * @param {{project: string, reportTitle: string}} typed
 * @param {{file: Blob, texts: {title?: string, summary?: string}, pins: Array<{x_pct: number, y_pct: number, note: string}>}} screenshot
 * @returns {Promise<NewReport>} the report with the capture saved
 * @throws {Error} a plain refusal, REPORTS_WRITE_MISSING, NOT_SENT (the capture is kept to send again), or a
 *   TargetGoneError when the draft is no longer the person's
 */
export async function addCaptureToNewReport(storage, api, typed, { file, texts, pins }) {
    const newReport = await loadNewReport(storage);
    const { project, reportTitle } = checkedNewReportCapture(newReport, typed);
    let uploadId;
    try {
        uploadId = await upload(api, { project }, file);
    } catch (error) {
        throw refusalOfUpload(error, project);
    }
    const screenshot = { upload_id: uploadId, ...texts, pins };
    const [path, body] = null === newReport.draftId
        ? ['/api/v1/reports', { project, title: reportTitle, screenshots: [screenshot] }]
        : [`/api/v1/reports/${encodeURIComponent(newReport.draftId)}/screenshots`, screenshot];
    const bodyJson = JSON.stringify(body);
    if (new TextEncoder().encode(bodyJson).length > MAX_JSON_BODY_BYTES) {
        throw new Error('The pins and texts of this capture are too long to send. Shorten the notes.');
    }
    // Stored before the request goes, so a popup closed while it is under way still has the key to send it again with.
    await saveNewReport(storage, { ...newReport, project, reportTitle, pendingCapture: { idempotencyKey: crypto.randomUUID(), path, bodyJson } });

    return sendPendingCapture(storage, api);
}

/**
 * Sends the capture whose answer has not come, with the same Idempotency-Key and the same bytes, so pinround answers
 * what it did with it the first time rather than making a second draft or picture.
 *
 * @returns {Promise<NewReport>}
 * @throws {Error} as addCaptureToNewReport; with nothing to send, a plain message
 */
export async function sendPendingCapture(storage, api) {
    const newReport = await loadNewReport(storage);
    const pending = newReport.pendingCapture;
    if (null === pending) {
        throw new Error('There is no screenshot to send again.');
    }
    let answer;
    try {
        answer = await api.call('POST', pending.path, JSON.parse(pending.bodyJson), pending.idempotencyKey);
    } catch (error) {
        if (error instanceof ApiError && error.mayHaveBeenSaved) {
            throw new Error(NOT_SENT);
        }
        // A refusal saved nothing, and pinround answers the same refusal to the same key: the next capture gets a new one.
        await saveNewReport(storage, { ...newReport, pendingCapture: null });
        if (error instanceof ApiError && NOT_FOUND === error.outcome && null !== newReport.draftId) {
            await clearNewReport(storage);
            throw new TargetGoneError(REPORT_GONE);
        }
        throw error instanceof ApiError && MISSING_SCOPE === error.outcome ? new Error(REPORTS_WRITE_MISSING) : error;
    }
    const draftId = null === newReport.draftId ? answer.draft_id : newReport.draftId;
    if ('string' !== typeof draftId || !Number.isInteger(answer.screenshot_count)) {
        throw new Error('pinround answered in a way this extension does not understand. Update the extension.');
    }
    const saved = {
        ...newReport,
        draftId,
        draftUrl: null === newReport.draftId && 'string' === typeof answer.url ? answer.url : newReport.draftUrl,
        screenshotCount: Math.min(answer.screenshot_count, MAX_SCREENSHOTS),
        pendingCapture: null,
    };
    await saveNewReport(storage, saved);

    return saved;
}

/**
 * Ends the new report: opens its draft in a new tab when its address is on the paired pinround, and forgets it there.
 * The draft stays in pinround either way.
 *
 * @returns {Promise<boolean>} true when the draft was opened
 * @throws {Error} while a capture has not reached pinround, so it is not forgotten unsaved
 */
export async function finishNewReport(storage, browser, pinroundOrigin) {
    const newReport = await loadNewReport(storage);
    if (null !== newReport.pendingCapture) {
        throw new Error(SEND_AGAIN_FIRST);
    }
    const isOnPinround = null !== newReport.draftUrl && URL.canParse(newReport.draftUrl) && new URL(newReport.draftUrl).origin === pinroundOrigin;
    if (isOnPinround) {
        await browser.tabs.create({ url: newReport.draftUrl });
    }
    await clearNewReport(storage);

    return isOnPinround;
}
