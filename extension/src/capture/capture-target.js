// Where the popup's captures go: chosen once and kept in the extension's session storage until the person changes it
// or the browser closes. A remembered target is checked against what pinround offers each time the popup opens.
import { ApiError } from '../shared/api.js';
import { shownText, unwrappedText } from './untrusted-text.js';

export const CAPTURE_TARGET_KEY = 'pinroundCaptureTarget';
export const NEW_REPORT = 'new-report';
export const NEW_NOTEBOOK = 'new-notebook';
// list_my_tickets answers at most 50; list_my_notebooks at most 20 a page.
export const TICKETS_LIMIT = 50;
const NOTEBOOKS_PAGE_SIZE = 20;
const MAX_ID_LENGTH = 64;
const MAX_SHOWN_TITLE_LENGTH = 120;

/** A remembered target pinround no longer offers; the popup forgets it and asks again. */
export class TargetGoneError extends Error {
    /**
     * @param {string} message
     * @param {CaptureTarget} target the target the capture was sent to, which may no longer be the one the picker shows
     */
    constructor(message, target) {
        super(message);
        this.target = target;
    }
}

/**
 * @typedef {{kind: 'new-report'} | {kind: 'ticket', ticketKey: string} | {kind: 'notebook', notebookId: string, title: string}} CaptureTarget
 * @typedef {{value: string, label: string, target: CaptureTarget}} TargetChoice
 */

/** @returns {string} the picker's option value for the target */
export function choiceValueOf(target) {
    if ('ticket' === target.kind) {
        return `ticket:${target.ticketKey}`;
    }

    return 'notebook' === target.kind ? `notebook:${target.notebookId}` : NEW_REPORT;
}

export const ticketChoice = (ticketKey) => ({ value: `ticket:${ticketKey}`, label: `My draft round on ${shownText(ticketKey, MAX_ID_LENGTH)}`, target: { kind: 'ticket', ticketKey } });
export const notebookChoice = (notebookId, title) => ({ value: `notebook:${notebookId}`, label: `Notebook: ${shownText(title, MAX_SHOWN_TITLE_LENGTH)}`, target: { kind: 'notebook', notebookId, title } });

export const isId = (value) => 'string' === typeof value && '' !== value && value.length <= MAX_ID_LENGTH;

/** @returns {CaptureTarget|null} null when nothing is remembered or what is stored is not a target this version knows */
export function checkedTarget(stored) {
    if (null === stored || 'object' !== typeof stored) {
        return null;
    }
    if (NEW_REPORT === stored.kind) {
        return { kind: NEW_REPORT };
    }
    if ('ticket' === stored.kind && isId(stored.ticketKey)) {
        return { kind: 'ticket', ticketKey: stored.ticketKey };
    }
    if ('notebook' === stored.kind && isId(stored.notebookId) && 'string' === typeof stored.title) {
        return { kind: 'notebook', notebookId: stored.notebookId, title: stored.title };
    }

    return null;
}

/** @returns {Promise<CaptureTarget|null>} */
export async function loadCaptureTarget(storage) {
    return checkedTarget((await storage.get(CAPTURE_TARGET_KEY))[CAPTURE_TARGET_KEY] ?? null);
}

export async function saveCaptureTarget(storage, target) {
    await storage.set({ [CAPTURE_TARGET_KEY]: target });
}

export async function forgetCaptureTarget(storage) {
    await storage.remove(CAPTURE_TARGET_KEY);
}

/**
 * The tickets pinround offers for a draft round, and whether that is all of them.
 *
 * @returns {Promise<{choices: TargetChoice[], isComplete: boolean}>}
 */
async function ticketChoices(api) {
    const answer = await api.call('GET', `/api/v1/me/tickets?limit=${TICKETS_LIMIT}`);
    const tickets = Array.isArray(answer.tickets) ? answer.tickets.slice(0, TICKETS_LIMIT) : [];

    return { choices: tickets.filter((ticket) => isId(ticket?.key)).map((ticket) => ticketChoice(ticket.key)), isComplete: true !== answer.has_more };
}

/**
 * The person's notebooks from the first page of GET /api/v1/notebooks only: it holds the 20 used last, and a capture
 * moves its notebook to the front, so a notebook in use stays on it.
 *
 * @returns {Promise<{choices: TargetChoice[], isComplete: boolean}>}
 */
async function notebookChoices(api) {
    const answer = await api.call('GET', '/api/v1/notebooks');
    const notebooks = Array.isArray(answer.notebooks) ? answer.notebooks.slice(0, NOTEBOOKS_PAGE_SIZE) : [];

    return {
        choices: notebooks.filter((notebook) => isId(notebook?.notebook_id) && 'string' === typeof notebook.title).map((notebook) => notebookChoice(notebook.notebook_id, unwrappedText(notebook.title))),
        isComplete: null === (answer.next_cursor ?? null),
    };
}

/**
 * Every target to offer, in the picker's order, with the remembered one checked: a ticket or notebook that pinround
 * no longer lists, on a list it answered in full, is dropped with `droppedMessage`. One it could not check (the list
 * did not load, or has more pages than were read) stays offered, and pinround refuses it at the capture if it is gone.
 *
 * @param {CaptureTarget|null} remembered
 * @returns {Promise<{choices: TargetChoice[], target: CaptureTarget|null, droppedMessage: string|null, loadProblems: string[]}>}
 */
export async function targetChoices(api, remembered) {
    const loadProblems = [];
    const loaded = async (load, problem) => {
        try {
            return await load(api);
        } catch (error) {
            loadProblems.push(`${problem} ${error instanceof ApiError ? error.message : ''}`.trim());

            return { choices: [], isComplete: false };
        }
    };
    const tickets = await loaded(ticketChoices, 'Your tickets could not be loaded.');
    const notebooks = await loaded(notebookChoices, 'Your notebooks could not be loaded.');
    const choices = [{ value: NEW_REPORT, label: 'A new report', target: { kind: NEW_REPORT } }, ...tickets.choices, ...notebooks.choices];
    if (null === remembered || NEW_REPORT === remembered.kind) {
        return { choices, target: remembered, droppedMessage: null, loadProblems };
    }
    const rememberedValue = choiceValueOf(remembered);
    const listed = choices.find((choice) => choice.value === rememberedValue);
    if (undefined !== listed) {
        return { choices, target: listed.target, droppedMessage: null, loadProblems };
    }
    const list = 'ticket' === remembered.kind ? tickets : notebooks;
    if (!list.isComplete) {
        const unchecked = 'ticket' === remembered.kind ? ticketChoice(remembered.ticketKey) : notebookChoice(remembered.notebookId, remembered.title);

        return { choices: [...choices, unchecked], target: remembered, droppedMessage: null, loadProblems };
    }

    return { choices, target: null, droppedMessage: goneMessageOf(remembered), loadProblems };
}

/** @returns {string} the plain message for a remembered target pinround no longer offers */
export function goneMessageOf(target) {
    if ('ticket' === target.kind) {
        return `You can no longer add screenshots to your draft round on ${shownText(target.ticketKey, MAX_ID_LENGTH)}. Choose where to put the next one.`;
    }
    if ('notebook' === target.kind) {
        return `The notebook "${shownText(target.title, MAX_SHOWN_TITLE_LENGTH)}" is gone. Choose where to put the next screenshot.`;
    }

    return 'Your report draft is gone from pinround. Choose where to put the next screenshot.';
}
