// The captures saved for a new report, in the extension's session storage until "Make the report draft" sends them
// with POST /api/v1/reports in one request.
import { MAX_SCREENSHOTS, upload } from './capture.js';

export const REPORT_CAPTURES_KEY = 'pinroundReportCaptures';

/** @returns {Promise<{project: string, reportTitle: string, items: Array<object>, idempotencyKey: string}>} */
export async function loadReportCaptures(storage) {
    const stored = (await storage.get(REPORT_CAPTURES_KEY))[REPORT_CAPTURES_KEY];

    return stored ?? { project: '', reportTitle: '', items: [], idempotencyKey: crypto.randomUUID() };
}

export async function saveReportCaptures(storage, captures) {
    await storage.set({ [REPORT_CAPTURES_KEY]: captures });
}

export async function clearReportCaptures(storage) {
    await storage.remove(REPORT_CAPTURES_KEY);
}

/**
 * Uploads the picture for the report's project and saves it, with its texts and pins, as the report's next screenshot.
 * The project is the one typed until the first screenshot is saved, then the saved one.
 *
 * @param {{project: string, reportTitle: string}} typed what the person typed in the popup
 * @param {{file: Blob, texts: {title?: string, summary?: string}, pins: Array<{x_pct: number, y_pct: number, note: string}>}} screenshot
 * @returns {Promise<object>} the saved captures
 * @throws {Error} before any upload when the project is blank or the report is full
 */
export async function addCaptureToNewReport(storage, api, typed, { file, texts, pins }) {
    const captures = await loadReportCaptures(storage);
    const project = (captures.items.length > 0 ? captures.project : typed.project).trim().toUpperCase();
    if ('' === project) {
        throw new Error('Type the project key, for example SHOP.');
    }
    if (captures.items.length >= MAX_SCREENSHOTS) {
        throw new Error('A report holds at most 10 screenshots. Make the report draft first.');
    }
    const uploadId = await upload(api, { project }, file);
    const updated = { ...captures, project, reportTitle: typed.reportTitle, items: [...captures.items, { upload_id: uploadId, ...texts, pins }] };
    await saveReportCaptures(storage, updated);

    return updated;
}
