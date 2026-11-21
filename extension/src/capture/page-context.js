// What a capture's title starts from: the page's title, made plain one-line text, since the page chooses it. The
// summary ("What this picture shows") is the person's own words and starts empty: pinround's picture has no place for
// the page's address, window size or browser, and the summary is not one.
import { TITLE_MAX_LENGTH } from './capture.js';

/** The most code units of a page's title read at all. */
export const PAGE_TEXT_READ_MAX_LENGTH = 8192;
const ELLIPSIS = '…';

// Invisible marks that change how the rest reads: the BOM and the bidi embeddings, overrides and isolates.
const DROPPED_MARKS = /[\uFEFF\u202A-\u202E\u2066-\u2069]/gu;
// Controls (C0, DEL, C1: CR, LF and ESC among them) and the line and paragraph separators break the line.
const LINE_BREAKING = /[\p{Cc}\u2028\u2029]/gu;
const graphemes = new Intl.Segmenter('en', { granularity: 'grapheme' });

/**
 * Page text on one line: well-formed, the BOM and direction marks gone, every control and white-space run one space,
 * none at either end. Reads no more than PAGE_TEXT_READ_MAX_LENGTH code units.
 *
 * @param {string} text
 * @returns {string}
 */
export function oneLinePageText(text) {
    return text.slice(0, PAGE_TEXT_READ_MAX_LENGTH).toWellFormed().replace(DROPPED_MARKS, '').replace(LINE_BREAKING, ' ').replace(/\s+/gu, ' ').trim();
}

/**
 * At most `maxLength` UTF-16 code units, cut between whole characters as a person sees them (an emoji, a letter with
 * its accents), the last one an ellipsis when the text was longer. Code units, not code points: the popup's maxlength
 * counts them, and pinround counts code points, which are never more.
 *
 * @param {string} text
 * @param {number} maxLength
 * @returns {string}
 */
export function cutToFit(text, maxLength) {
    if (text.length <= maxLength) {
        return text;
    }
    let kept = '';
    for (const { segment } of graphemes.segment(text)) {
        if (kept.length + segment.length > maxLength - ELLIPSIS.length) {
            break;
        }
        kept += segment;
    }

    return `${kept.trimEnd()}${ELLIPSIS}`;
}

/**
 * The capture's starting title for the tab, within pinround's cap. A tab whose address the extension may not read (no
 * activeTab grant) gives none, as its title is then out of reach too.
 *
 * @param {{url?: string, title?: string}} tab as tabs.query answers it
 * @returns {string} '' when not known
 */
export function pageTitleOf(tab) {
    if ('string' !== typeof tab.url) {
        return '';
    }

    return cutToFit(oneLinePageText(tab.title ?? ''), TITLE_MAX_LENGTH);
}

/**
 * pageTitleOf the tab the popup was opened on.
 *
 * @returns {Promise<string>} '' when there is no such tab or it is out of reach
 */
export async function activePageTitle(browser) {
    const [tab] = await browser.tabs.query({ active: true, currentWindow: true });

    return undefined === tab ? '' : pageTitleOf(tab);
}
