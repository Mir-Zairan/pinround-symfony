// The service worker's requests, sent from the extension's offscreen document (offscreen.html) instead. Chrome cancels a
// request whose certificate it does not trust when no page stands behind it, as with the service worker's, even for a
// host whose warning the person accepted in a tab; a request from an extension page gets that acceptance as the tab
// does. On a local pinround, whose certificate comes from Caddy's own authority, the dock's requests then work as the
// popup's do, the picture's PUT to the storage's other port too. A trusted certificate needs none of this, and the
// requests are the same either way.
import { base64OfBlob, bytesOfBase64 } from '../shared/base64.js';

/** The message key the offscreen document answers; any other message is left to other listeners. */
export const EXTENSION_PAGE_FETCH = 'pinroundExtensionPageFetch';
export const OFFSCREEN_DOCUMENT_PATH = 'offscreen.html';
const OFFSCREEN_REASONS = ['BLOBS'];
const OFFSCREEN_JUSTIFICATION = 'Sends the dock\'s requests and screenshots to pinround from an extension page, so a certificate the person accepted in a tab applies to them.';
/** Fetch's own words for a request that got no answer; the callers catch any error, so only the kind matters. */
const NOT_FETCHED = 'Failed to fetch';
// A Response with one of these statuses must have no body.
const NULL_BODY_STATUSES = new Set([204, 205, 304]);
const REQUEST_OPTIONS = ['credentials', 'redirect', 'cache'];

/**
 * @typedef {{url: string, method: string, headers: Object<string, string>, credentials?: string, redirect?: string,
 *   cache?: string, bodyText?: string, bodyBase64?: string, bodyType?: string}} PageFetchRequest a fetch as JSON carries it
 * @typedef {{status: number, bodyText: string}|{isUnanswered: true}} PageFetchAnswer
 */

/**
 * @param {{method?: string, headers?: Object<string, string>, body?: string|Blob}} init the fetch options the
 *   extension's callers use: plain headers, a body of text or a Blob
 * @returns {Promise<PageFetchRequest>}
 */
async function requestOf(url, init) {
    const request = { url: String(url), method: init.method ?? 'GET', headers: { ...(init.headers ?? {}) } };
    for (const option of REQUEST_OPTIONS) {
        if (undefined !== init[option]) {
            request[option] = init[option];
        }
    }
    if ('string' === typeof init.body) {
        request.bodyText = init.body;
    } else if (init.body instanceof Blob) {
        request.bodyBase64 = await base64OfBlob(init.body);
        request.bodyType = init.body.type;
    } else if (undefined !== init.body) {
        throw new TypeError('The extension sends a body of text or a Blob only.');
    }

    return request;
}

/** @returns {Response} @throws {TypeError} as fetch does, when the request got no answer */
function responseOf(answer) {
    if (null === answer || 'object' !== typeof answer || !Number.isInteger(answer.status) || answer.status < 200
        || answer.status > 599 || 'string' !== typeof answer.bodyText) {
        throw new TypeError(NOT_FETCHED);
    }

    return new Response(NULL_BODY_STATUSES.has(answer.status) ? null : answer.bodyText, { status: answer.status });
}

const hasOffscreenDocument = async (browser) => (await browser.runtime.getContexts({ contextTypes: ['OFFSCREEN_DOCUMENT'] })).length > 0;

async function openOffscreenDocument(browser) {
    if (await hasOffscreenDocument(browser)) {
        return;
    }
    try {
        await browser.offscreen.createDocument({ url: OFFSCREEN_DOCUMENT_PATH, reasons: OFFSCREEN_REASONS, justification: OFFSCREEN_JUSTIFICATION });
    } catch (error) {
        // Chrome keeps one offscreen document per extension: another request may have opened it meanwhile.
        if (!(await hasOffscreenDocument(browser))) {
            throw error;
        }
    }
}

/**
 * A fetch for the service worker that sends the request from the offscreen document, which it opens when none is.
 *
 * @returns {(url: string, init?: object) => Promise<Response>} rejects with a TypeError, as fetch does, when the
 *   request got no answer or the document could not be opened
 */
export function extensionPageFetchOf(browser) {
    return async (url, init = {}) => {
        const request = await requestOf(url, init);
        await openOffscreenDocument(browser);
        const answer = await browser.runtime.sendMessage({ [EXTENSION_PAGE_FETCH]: request }).catch(() => null);

        return responseOf(answer ?? null);
    };
}

const isStringMap = (value) => null !== value && 'object' === typeof value && Object.values(value).every((entry) => 'string' === typeof entry);

/** @returns {{url: string, init: object}|null} the fetch to make, or null for a request of another shape */
function fetchOf(request) {
    if (null === request || 'object' !== typeof request || 'string' !== typeof request.url || 'string' !== typeof request.method
        || !isStringMap(request.headers) || REQUEST_OPTIONS.some((option) => !['undefined', 'string'].includes(typeof request[option]))
        || !['undefined', 'string'].includes(typeof request.bodyText) || !['undefined', 'string'].includes(typeof request.bodyBase64)) {
        return null;
    }
    const init = { method: request.method, headers: request.headers };
    for (const option of REQUEST_OPTIONS) {
        if (undefined !== request[option]) {
            init[option] = request[option];
        }
    }
    if (undefined !== request.bodyText) {
        init.body = request.bodyText;
    } else if (undefined !== request.bodyBase64) {
        try {
            init.body = new Blob([bytesOfBase64(request.bodyBase64)], { type: 'string' === typeof request.bodyType ? request.bodyType : '' });
        } catch {
            return null;
        }
    }

    return { url: request.url, init };
}

/**
 * The offscreen document's answer to a message: the request made, its status and body as text. Only this extension's
 * own pages and service worker may ask; a content script, which runs in a tab, may not.
 *
 * @param {{id?: string, tab?: object}} sender as Chrome names it
 * @param {{extensionId: string, fetchFn?: typeof fetch}} deps
 * @returns {Promise<PageFetchAnswer>|null} null for a message that is not a page fetch, or not from this extension
 */
export function answerExtensionPageFetch(message, sender, { extensionId, fetchFn = (...args) => fetch(...args) }) {
    if (null === message || 'object' !== typeof message || !Object.hasOwn(message, EXTENSION_PAGE_FETCH)
        || sender?.id !== extensionId || undefined !== sender.tab) {
        return null;
    }
    const wanted = fetchOf(message[EXTENSION_PAGE_FETCH]);
    if (null === wanted) {
        return Promise.resolve({ isUnanswered: true });
    }

    return (async () => {
        try {
            const response = await fetchFn(wanted.url, wanted.init);

            return { status: response.status, bodyText: await response.text() };
        } catch {
            return { isUnanswered: true };
        }
    })();
}
