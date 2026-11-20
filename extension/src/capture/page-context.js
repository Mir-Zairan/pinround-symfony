// What the popup fills a capture's title and summary with: the page's title, and its address, window size and browser.
// The page's title and address are the page's to choose, so they are made plain one-line text, and the address loses
// what can carry a secret (credentials, query, fragment) before anything else sees it.
import { SUMMARY_MAX_LENGTH, TITLE_MAX_LENGTH } from './capture.js';

/** The most code units of a page's title or address read at all; a data: address can run to megabytes. */
export const PAGE_TEXT_READ_MAX_LENGTH = 8192;
const BROWSER_MAX_LENGTH = 100;
const ELLIPSIS = '…';
const SEPARATOR = ' · ';

// Invisible marks that change how the rest reads: the BOM and the bidi embeddings, overrides and isolates.
const DROPPED_MARKS = /[\uFEFF\u202A-\u202E\u2066-\u2069]/gu;
// Controls (C0, DEL, C1: CR, LF and ESC among them) and the line and paragraph separators break the line.
const LINE_BREAKING = /[\p{Cc}\u2028\u2029]/gu;
// Chrome's made-up brand in userAgentData ("Not_A Brand", "Not)A;Brand"), there to keep sniffers honest.
const GREASE_BRAND = /not.a.brand/iu;
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
 * The address with no user name, password, query or fragment, as one line.
 *
 * @param {string} url
 * @returns {string|null} null when it is no address
 */
export function addressWithoutSecrets(url) {
    let address;
    try {
        address = new URL(url.slice(0, PAGE_TEXT_READ_MAX_LENGTH));
    } catch {
        return null;
    }
    address.username = '';
    address.password = '';
    address.search = '';
    address.hash = '';

    return oneLinePageText(address.href);
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
 * The capture's starting title and summary for the tab, within pinround's caps. A tab whose address the extension may
 * not read (no activeTab grant) gives neither.
 *
 * @param {{url?: string, title?: string, width?: number, height?: number}} tab as tabs.query answers it
 * @param {string} browserVersion as browserNameAndVersion answers it; '' leaves the browser out
 * @returns {{title: string, summary: string}} '' for what is not known
 */
export function pageContext(tab, browserVersion) {
    if ('string' !== typeof tab.url) {
        return { title: '', summary: '' };
    }
    const title = cutToFit(oneLinePageText(tab.title ?? ''), TITLE_MAX_LENGTH);
    const address = addressWithoutSecrets(tab.url);
    if (null === address || '' === address) {
        return { title, summary: '' };
    }
    const tail = [
        Number.isInteger(tab.width) && Number.isInteger(tab.height) ? `Window ${tab.width}×${tab.height}` : '',
        cutToFit(oneLinePageText(browserVersion), BROWSER_MAX_LENGTH),
    ].filter((part) => '' !== part).map((part) => `${SEPARATOR}${part}`).join('');
    const lead = 'Page: ';

    return { title, summary: `${lead}${cutToFit(address, SUMMARY_MAX_LENGTH - lead.length - tail.length)}${tail}` };
}

/**
 * The browser's own brand and version, "Google Chrome 131.0.6778.86", from userAgentData: the full version when the
 * browser gives it, else the major one. Chromium only when no other brand is named.
 *
 * @param {{userAgentData?: {brands?: Array<{brand: string, version: string}>, getHighEntropyValues?: Function}}} navigator
 * @returns {Promise<string>} '' when the browser names none
 */
export async function browserNameAndVersion(navigator) {
    const userAgentData = navigator.userAgentData;
    if (undefined === userAgentData) {
        return '';
    }
    let brands = userAgentData.brands ?? [];
    try {
        const { fullVersionList } = await userAgentData.getHighEntropyValues(['fullVersionList']);
        brands = Array.isArray(fullVersionList) && fullVersionList.length > 0 ? fullVersionList : brands;
    } catch {
        // The browser may refuse the full version; the major one in brands still names it.
    }
    const named = brands.filter((brand) => 'string' === typeof brand?.brand && 'string' === typeof brand.version && !GREASE_BRAND.test(brand.brand));
    const chosen = named.find((brand) => 'Chromium' !== brand.brand) ?? named[0];

    return undefined === chosen ? '' : oneLinePageText(`${chosen.brand} ${chosen.version}`);
}

/**
 * pageContext of the tab the popup was opened on.
 *
 * @returns {Promise<{title: string, summary: string}>} both '' when there is no such tab or it is out of reach
 */
export async function activePageContext(browser, navigator) {
    const [tab] = await browser.tabs.query({ active: true, currentWindow: true });
    if (undefined === tab) {
        return { title: '', summary: '' };
    }

    return pageContext(tab, await browserNameAndVersion(navigator));
}
