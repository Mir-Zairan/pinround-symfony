// Text people typed, as pinround's GET routes answer it, made fit to show in the popup with textContent.

const OPEN = '<untrusted_text>';
const CLOSE = '</untrusted_text>';
// C0 and C1 controls (CR, LF, ESC among them), the line and paragraph separators, and the bidi overrides and isolates,
// which could make a title read as another one.
const UNSHOWABLE = /[\p{Cc}\u2028\u2029\u202A-\u202E\u2066-\u2069]/gu;

/**
 * The text inside pinround's <untrusted_text> markers, unescaped as docs/api/v1.yaml says: markers off, then &lt; and
 * &gt;, and &amp; last so "&amp;lt;" stays "&lt;". A text without both markers is answered as it is.
 *
 * @param {string} text
 * @returns {string}
 */
export function unwrappedText(text) {
    if (!text.startsWith(OPEN) || !text.endsWith(CLOSE) || text.length < OPEN.length + CLOSE.length) {
        return text;
    }

    return text.slice(OPEN.length, -CLOSE.length).replaceAll('&lt;', '<').replaceAll('&gt;', '>').replaceAll('&amp;', '&');
}

/**
 * The text with every control or direction character written as \uXXXX, cut to `maxCharacters` characters (code
 * points) with an ellipsis.
 *
 * @param {string} text
 * @param {number} maxCharacters
 * @returns {string}
 */
export function shownText(text, maxCharacters) {
    const escaped = text.replace(UNSHOWABLE, (character) => `\\u${character.codePointAt(0).toString(16).toUpperCase().padStart(4, '0')}`);
    const characters = [...escaped];

    return characters.length > maxCharacters ? `${characters.slice(0, maxCharacters - 1).join('')}…` : escaped;
}
