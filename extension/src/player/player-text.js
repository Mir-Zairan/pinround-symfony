// How the scenario player compares and shows text, for page-player.js in the page and for the extension that reads
// its answers. Injected as a classic script before page-player.js, which cannot import, so the rules hang on the
// global of the extension's isolated world, which the page never sees; step-runner.js imports this file for its side
// effect. The whole file is one expression, so a second injection declares nothing twice and keeps the first.
globalThis.pinroundPlayerText ??= (() => {
    /** The most characters of page text a stop shows, as found or expected. */
    const SHOWN_MAX_LENGTH = 200;
    /** The most characters of a stop's message, which quotes a target or option of at most SHOWN_MAX_LENGTH. */
    const MESSAGE_MAX_LENGTH = 600;
    const ELLIPSIS = '…';

    // Characters that move the cursor, break a line or turn the text's direction around where the text is shown:
    // controls (C0, DEL, C1), the line and paragraph separators, and the bidi marks, embeddings and isolates.
    const UNSHOWABLE = /[\p{Cc}\u{61C}\u{200E}\u{200F}\u{2028}\u{2029}\u{202A}-\u{202E}\u{2066}-\u{2069}]/gu;

    /** Text as a person reads it: every run of white space one space, none at either end. */
    const collapsedWhitespace = (text) => text.replace(/\s+/gu, ' ').trim();

    /**
     * At most `maxLength` characters (code points, so an emoji is never cut in two), the last one an ellipsis when
     * the text was longer. Reads no further than the cap, so a long page costs no more than a short one.
     */
    function cappedText(text, maxLength = SHOWN_MAX_LENGTH) {
        const kept = [];
        for (const character of text) {
            if (kept.length === maxLength) {
                kept[maxLength - 1] = ELLIPSIS;

                return kept.join('');
            }
            kept.push(character);
        }

        return kept.join('');
    }

    /** Text from a page, capped and safe to show on one line: each unshowable character written as \u{1b}. */
    function shownText(text, maxLength = SHOWN_MAX_LENGTH) {
        return cappedText(text.toWellFormed(), maxLength).replace(UNSHOWABLE, (character) => `\\u{${character.codePointAt(0).toString(16)}}`);
    }

    return Object.freeze({ SHOWN_MAX_LENGTH, MESSAGE_MAX_LENGTH, collapsedWhitespace, cappedText, shownText });
})();
