// Captures the dock takes for "A new report": each waits in the extension's session storage, picture and pins, until
// the person presses Save draft and writes what is wrong in one line. Only then is anything sent: each picture is
// uploaded and the first makes the report draft (new-report.js), so no draft exists without its title. Session
// storage is the extension's own (trusted contexts only), so the page cannot read a waiting picture or its notes.
import { checkedPins } from '../overlay/pins.js';
import { base64OfBlob } from '../shared/base64.js';
import { MAX_SCREENSHOTS, pngOf } from './capture.js';
import { WAITING_CAPTURES_KEY, addCaptureToNewReport, checkedNewReportCapture, loadNewReport, saveTypedReport, sendPendingCapture } from './new-report.js';
import { capturePinnedTab, closePinOverlay } from './page-pins.js';

export const NO_ROOM = 'The extension has no room for another capture. Press Save draft first.';
export const WAITING_FULL = 'A report holds at most 10 captures. Press Save draft, then start a new report for more.';
export const NOTHING_WAITING = 'There is no capture to save. Press Capture first.';
const PNG_DATA_URL_PREFIX = 'data:image/png;base64,';

export const capturedMessage = (waitingCount) => `Captured. ${waitingCount} waiting.`;
export const draftSavedMessage = (screenshotCount) => `Draft saved with ${screenshotCount} ${1 === screenshotCount ? 'capture' : 'captures'}. Open it in pinround to send it.`;

/**
 * @typedef {{id: string, pngDataUrl: string, texts: {title?: string, summary?: string},
 *   pins: Array<{x_pct: number, y_pct: number, note: string}>}} WaitingCapture
 */

const isOptionalString = (value) => undefined === value || 'string' === typeof value;

/** @returns {WaitingCapture|null} a copy with only these fields, or null for anything this version cannot read */
function checkedWaitingCapture(stored) {
    if (null === stored || 'object' !== typeof stored || 'string' !== typeof stored.id || 'string' !== typeof stored.pngDataUrl
        || !stored.pngDataUrl.startsWith(PNG_DATA_URL_PREFIX) || null === stored.texts || 'object' !== typeof stored.texts
        || !isOptionalString(stored.texts.title) || !isOptionalString(stored.texts.summary)) {
        return null;
    }
    let pins;
    try {
        pins = checkedPins(stored.pins);
    } catch {
        return null;
    }
    const texts = {
        ...(undefined === stored.texts.title ? {} : { title: stored.texts.title }),
        ...(undefined === stored.texts.summary ? {} : { summary: stored.texts.summary }),
    };

    return { id: stored.id, pngDataUrl: stored.pngDataUrl, texts, pins };
}

/**
 * @returns {Promise<Array<WaitingCapture>>} in the order taken; a capture this version cannot read is left out, and
 *   no more than MAX_SCREENSHOTS are read
 */
export async function loadWaitingCaptures(storage) {
    const stored = (await storage.get(WAITING_CAPTURES_KEY))[WAITING_CAPTURES_KEY];
    if (!Array.isArray(stored)) {
        return [];
    }

    return stored.slice(0, MAX_SCREENSHOTS).map(checkedWaitingCapture).filter((capture) => null !== capture);
}

async function saveWaitingList(storage, captures) {
    if (0 === captures.length) {
        await storage.remove(WAITING_CAPTURES_KEY);

        return;
    }
    try {
        await storage.set({ [WAITING_CAPTURES_KEY]: captures });
    } catch {
        // Chrome refuses a write past session storage's quota (10 MB), and the pictures are what fill it.
        throw new Error(NO_ROOM);
    }
}

async function dropWaitingCapture(storage, id) {
    await saveWaitingList(storage, (await loadWaitingCaptures(storage)).filter((capture) => capture.id !== id));
}

async function dataUrlOf(file) {
    return `${PNG_DATA_URL_PREFIX}${await base64OfBlob(file)}`;
}

/**
 * Captures the tab with its overlay's pins and keeps it waiting for Save draft; the pins leave the page once it is
 * kept. Refused before the capture when the report could not take one more.
 *
 * The dock asks one request at a time, so two captures never read and write the list at once.
 *
 * @param {{title?: string, summary?: string}} texts
 * @returns {Promise<number>} how many captures wait now
 * @throws {Error} WAITING_FULL, NO_ROOM, or capturePinnedTab's refusal; nothing is kept then
 */
export async function keepWaitingCapture(browser, storage, tabId, texts) {
    const newReport = await loadNewReport(storage);
    const waiting = await loadWaitingCaptures(storage);
    const pendingCount = null === newReport.pendingCapture ? 0 : 1;
    if (newReport.screenshotCount + pendingCount + waiting.length >= MAX_SCREENSHOTS) {
        throw new Error(WAITING_FULL);
    }
    const { file, pins } = await capturePinnedTab(browser, tabId);
    const kept = [...waiting, { id: crypto.randomUUID(), pngDataUrl: await dataUrlOf(file), texts, pins }];
    await saveWaitingList(storage, kept);
    await closePinOverlay(browser, tabId);

    return kept.length;
}

/**
 * Sends every waiting capture to the new report, oldest first: a capture whose answer never came goes first, then the
 * first waiting one makes the draft with the typed title and project, and each next one is added to it. A capture
 * leaves the list once pinround has it, or once it is the one kept to send again, so a retry never sends one twice.
 *
 * @param {{project: string, reportTitle: string}} typed
 * @returns {Promise<import('./new-report.js').NewReport>} the report with every capture saved
 * @throws {Error} NOTHING_WAITING; checkedNewReportCapture's refusal of the title or project before anything is sent;
 *   any refusal of addCaptureToNewReport or sendPendingCapture, with the captures not yet sent still waiting
 */
export async function saveWaitingCaptures(storage, api, typed) {
    let newReport = await loadNewReport(storage);
    const waiting = await loadWaitingCaptures(storage);
    if (0 === waiting.length && null === newReport.pendingCapture) {
        throw new Error(NOTHING_WAITING);
    }
    if (null === newReport.draftId && null === newReport.pendingCapture) {
        checkedNewReportCapture(newReport, typed);
        await saveTypedReport(storage, typed);
    }
    if (null !== newReport.pendingCapture) {
        newReport = await sendPendingCapture(storage, api);
    }
    for (const capture of waiting) {
        try {
            newReport = await addCaptureToNewReport(storage, api, typed, { file: pngOf(capture.pngDataUrl), texts: capture.texts, pins: capture.pins });
        } catch (error) {
            if (null !== (await loadNewReport(storage)).pendingCapture) {
                await dropWaitingCapture(storage, capture.id);
            }
            throw error;
        }
        await dropWaitingCapture(storage, capture.id);
    }

    return newReport;
}
