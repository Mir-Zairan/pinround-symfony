// Capture of the visible tab and its upload: the picture goes straight to storage through a presigned PUT, never in
// a request body (ADR-0011).
import { isId } from './capture-target.js';
import { unwrappedText } from './untrusted-text.js';

export const TITLE_MAX_LENGTH = 120;
export const SUMMARY_MAX_LENGTH = 600;
export const REPORT_TITLE_MAX_LENGTH = 200;
export const MAX_SCREENSHOTS = 10;
// A round holds at most 50 screenshots (Review's Round::MAX_SCREENSHOTS), so its list never answers more chains.
const DRAFT_SCREENSHOTS_LIMIT = 50;

/**
 * @param {string} dataUrl a data:image/png;base64,… URL as captureVisibleTab answers it
 * @returns {Blob}
 */
export function pngOf(dataUrl) {
    const prefix = 'data:image/png;base64,';
    if (!dataUrl.startsWith(prefix)) {
        throw new Error('The capture is not a PNG.');
    }
    const bytes = Uint8Array.from(atob(dataUrl.slice(prefix.length)), (character) => character.charCodeAt(0));

    return new Blob([bytes], { type: 'image/png' });
}

/** @returns {Promise<string>} the SHA-256 of the file in lowercase hex */
export async function sha256Of(file) {
    const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer());

    return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
}

/**
 * The texts a capture carries, checked against the server's caps (FR-RND-16) before anything is uploaded.
 *
 * @returns {{title?: string, summary?: string}}
 */
export function captureTexts(title, summary) {
    const trimmedTitle = title.trim();
    const trimmedSummary = summary.trim();
    if ([...trimmedTitle].length > TITLE_MAX_LENGTH) {
        throw new Error('Keep the title to 120 characters or fewer.');
    }
    if ([...trimmedSummary].length > SUMMARY_MAX_LENGTH) {
        throw new Error('Keep the summary to 600 characters or fewer.');
    }

    return { ...('' === trimmedTitle ? {} : { title: trimmedTitle }), ...('' === trimmedSummary ? {} : { summary: trimmedSummary }) };
}

/**
 * Begins the upload for the target, then PUTs the file to the URL pinround answered.
 *
 * @param {import('../shared/api.js').PinroundApi} api
 * @param {{ticket: string}|{project: string}} target
 * @param {Blob} file
 * @returns {Promise<string>} the upload_id
 */
export async function upload(api, target, file) {
    const slot = await api.call('POST', '/api/v1/uploads', { ...target, mime: 'image/png', bytes: file.size, sha256: await sha256Of(file) });
    await api.putFile(slot, file);

    return slot.upload_id;
}

/**
 * Adds an uploaded picture to the person's draft round on the ticket, asking again while it is still being processed.
 *
 * @param {{title?: string, summary?: string, pins?: Array<{x_pct: number, y_pct: number, note: string}>, retakeOf?: string|null}} screenshotFields
 *   `retakeOf`, a screenshot_id of listDraftScreenshots, makes the picture that screenshot's next try; null or left out
 *   adds a new screenshot
 * @returns {Promise<{status: string}>} added or added_before
 */
export async function addToDraftRound(api, ticketKey, uploadId, { retakeOf = null, ...screenshotFields }, wait = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds)), tries = 15) {
    const body = { upload_id: uploadId, ...screenshotFields, ...(null === retakeOf ? {} : { retake_of: retakeOf }) };
    for (let attempt = 1; attempt <= tries; attempt += 1) {
        const answer = await api.call('POST', `/api/v1/tickets/${encodeURIComponent(ticketKey)}/draft/screenshots`, body);
        if ('processing' !== answer.status) {
            return answer;
        }
        await wait(2000);
    }
    throw new Error('pinround is still processing the screenshot. It will show in your draft round soon.');
}

/**
 * The screenshots of the person's own open draft round on the ticket, one per chain of tries, in the order and with
 * the numbers the draft round shows; empty when there is no such draft. Rows of a shape pinround does not answer are
 * left out.
 *
 * @returns {Promise<Array<{screenshotId: string, number: number, title: string}>>} the title unwrapped, '' when none
 * @throws {import('../shared/api.js').ApiError} when pinround refuses or cannot be reached
 */
export async function listDraftScreenshots(api, ticketKey) {
    const answer = await api.call('GET', `/api/v1/tickets/${encodeURIComponent(ticketKey)}/draft/screenshots`);
    const screenshots = Array.isArray(answer.screenshots) ? answer.screenshots.slice(0, DRAFT_SCREENSHOTS_LIMIT) : [];

    return screenshots
        .filter((screenshot) => isId(screenshot?.screenshot_id) && Number.isInteger(screenshot.number) && screenshot.number >= 1 && ('string' === typeof screenshot.title || null === screenshot.title))
        .map((screenshot) => ({ screenshotId: screenshot.screenshot_id, number: screenshot.number, title: null === screenshot.title ? '' : unwrappedText(screenshot.title) }));
}
