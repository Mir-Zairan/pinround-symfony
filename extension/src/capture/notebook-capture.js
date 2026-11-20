// A notebook as a capture target: a new one made from the popup, and a capture of the tab into one. The player's
// capture step (src/player/) calls captureToNotebook too.
import { ApiError, MISSING_SCOPE, NOT_FOUND } from '../shared/api.js';
import { sha256Of } from './capture.js';
import { TargetGoneError, goneMessageOf } from './capture-target.js';
import { capturePinnedTab, closePinOverlay } from './page-pins.js';

export const NOTEBOOK_TITLE_MAX_LENGTH = 120;
export const NOTEBOOKS_WRITE_MISSING = 'Your pinround token cannot write to notebooks. Make a token that may write to your notebooks on the API tokens page, then pair the extension again.';
const ADDED_BEFORE = 'already_in_notebook';

function refusalOf(error, notebook) {
    if (error instanceof ApiError && MISSING_SCOPE === error.outcome) {
        return new Error(NOTEBOOKS_WRITE_MISSING);
    }
    if (error instanceof ApiError && NOT_FOUND === error.outcome && undefined !== notebook) {
        const target = { kind: 'notebook', notebookId: notebook.notebookId, title: notebook.title };

        return new TargetGoneError(goneMessageOf(target), target);
    }

    return error;
}

/**
 * Makes a new, empty notebook of the person's.
 *
 * @param {string} typedTitle
 * @returns {Promise<{notebookId: string, title: string}>}
 * @throws {Error} a blank or too long title before any request; NOTEBOOKS_WRITE_MISSING; pinround's refusal
 */
export async function makeNotebook(api, typedTitle) {
    const title = typedTitle.trim();
    if ('' === title) {
        throw new Error('Give the notebook a title.');
    }
    if ([...title].length > NOTEBOOK_TITLE_MAX_LENGTH) {
        throw new Error('Keep the notebook title to 120 characters or fewer.');
    }
    let answer;
    try {
        answer = await api.call('POST', '/api/v1/notebooks', { title });
    } catch (error) {
        throw refusalOf(error);
    }
    if ('string' !== typeof answer.notebook_id) {
        throw new Error('pinround answered in a way this extension does not understand. Update the extension.');
    }

    return { notebookId: answer.notebook_id, title };
}

/**
 * Uploads the picture for the notebook, then adds it at the notebook's end with its texts and pins. pinround takes a
 * picture still being processed (it shows once ready), so unlike a draft round there is nothing to ask again.
 *
 * @param {{notebookId: string, title: string}} notebook
 * @param {Blob} file
 * @param {{title?: string, summary?: string, pins: Array<{x_pct: number, y_pct: number, note: string}>}} screenshotFields
 * @returns {Promise<{status: string, screenshot_count: number}>} status added, or already_in_notebook
 * @throws {Error} NOTEBOOKS_WRITE_MISSING; a TargetGoneError when the notebook is not the person's any more;
 *   pinround's refusal, such as a full notebook
 */
export async function addToNotebook(api, notebook, file, screenshotFields) {
    const notebookPath = `/api/v1/notebooks/${encodeURIComponent(notebook.notebookId)}`;
    try {
        const slot = await api.call('POST', `${notebookPath}/uploads`, { mime: 'image/png', bytes: file.size, sha256: await sha256Of(file) });
        await api.putFile(slot, file);

        return await api.call('POST', `${notebookPath}/screenshots`, { upload_id: slot.upload_id, ...screenshotFields });
    } catch (error) {
        throw refusalOf(error, notebook);
    }
}

/**
 * Captures the tab with its overlay's pins into the notebook; the pins leave the page once the picture is saved, and
 * stay on it when pinround refuses.
 *
 * @param {{notebookId: string, title: string}} notebook
 * @param {{title?: string, summary?: string}} texts
 * @returns {Promise<string>} what to tell the person
 */
export async function captureToNotebook(api, browser, notebook, tabId, texts = {}) {
    const { file, pins } = await capturePinnedTab(browser, tabId);
    const answer = await addToNotebook(api, notebook, file, { ...texts, pins });
    await closePinOverlay(browser, tabId);

    if (ADDED_BEFORE === answer.status) {
        return 'This screenshot is in the notebook already.';
    }

    return Number.isInteger(answer.screenshot_count) ? `Screenshot ${answer.screenshot_count} added to the notebook. Only you see it.` : 'Screenshot added to the notebook. Only you see it.';
}
