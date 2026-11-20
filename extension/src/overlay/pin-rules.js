// The rules of a pin, for the overlay in the page and for the popup that sends the pins. The popup injects this file as
// a classic script before overlay.js, and a classic script cannot export, so the rules hang on the global of the
// extension's isolated world, which the page never sees; the popup and node --test import them through pins.js. The
// whole file is one expression, so a second injection into the same page declares nothing twice and keeps the first.
globalThis.pinroundPinRules ??= (() => {
    /** The API's caps (RoundCommands::MAX_PINS_PER_SCREENSHOT and MAX_PIN_NOTE_LENGTH). */
    const MAX_PINS = 100;
    const NOTE_MAX_LENGTH = 1000;

    const REFUSALS = Object.freeze({
        tooManyPins: 'A picture holds at most 100 pins.',
        pinOutsidePicture: 'A pin is outside the picture. Pin the page again.',
        noteTooLong: 'Keep each pin note to 1000 characters or fewer.',
        noteUnkeepable: 'A pin note holds a character pinround cannot keep. Write the note again.',
        pinsUnreadable: 'The pins on this page could not be read. Pin the page again.',
    });

    // PHP's trim(), which pinround counts after: a BOM or a no-break space stays and counts.
    const SPACE_PINROUND_TRIMS = /^[ \t\n\r\v]+|[ \t\n\r\v]+$/g;

    const roundedToHundredths = (value) => Math.round(value * 100) / 100;
    const isPercent = (value) => 'number' === typeof value && Number.isFinite(value) && value >= 0 && value <= 100;

    /**
     * Where a point lies along the viewport, in percent of its size: what captureVisibleTab's picture measures, whatever
     * the devicePixelRatio. Clamped to 0..100 and rounded to hundredths, as pinround keeps it.
     *
     * @param {number} offset CSS pixels from the viewport's left or top edge
     * @param {number} size the viewport's width or height in CSS pixels, above 0
     * @returns {number}
     */
    function percentOf(offset, size) {
        if (!(size > 0) || !Number.isFinite(offset)) {
            throw new RangeError(`No percent of a ${size}px viewport at ${offset}px.`);
        }

        return roundedToHundredths(Math.min(100, Math.max(0, (offset / size) * 100)));
    }

    /**
     * A pin's accessible name: "Pin 2: Price is wrong", or "Pin 2" while its note is blank.
     *
     * @param {number} number the pin's place, from 1
     * @param {string} note
     */
    function accessibleNameOf(number, note) {
        const trimmedNote = note.trim();

        return '' === trimmedNote ? `Pin ${number}` : `Pin ${number}: ${trimmedNote}`;
    }

    /**
     * The pins as the API takes them, checked again in the extension: the overlay's answer comes from a page that may
     * have changed it. Notes are trimmed and counted in characters, as pinround counts them.
     *
     * @param {unknown} pins what the overlay answered
     * @returns {Array<{x_pct: number, y_pct: number, note: string}>}
     * @throws {Error} with one of REFUSALS as its message
     */
    function checkedPins(pins) {
        if (!Array.isArray(pins)) {
            throw new Error(REFUSALS.pinsUnreadable);
        }
        if (pins.length > MAX_PINS) {
            throw new Error(REFUSALS.tooManyPins);
        }

        return pins.map((pin) => {
            if (null === pin || 'object' !== typeof pin || 'string' !== typeof pin.note) {
                throw new Error(REFUSALS.pinsUnreadable);
            }
            if (!isPercent(pin.x_pct) || !isPercent(pin.y_pct)) {
                throw new Error(REFUSALS.pinOutsidePicture);
            }
            const note = pin.note.replace(SPACE_PINROUND_TRIMS, '');
            if ([...note].length > NOTE_MAX_LENGTH) {
                throw new Error(REFUSALS.noteTooLong);
            }
            if (!note.isWellFormed() || note.includes('\u0000')) {
                throw new Error(REFUSALS.noteUnkeepable);
            }

            return { x_pct: roundedToHundredths(pin.x_pct), y_pct: roundedToHundredths(pin.y_pct), note };
        });
    }

    return Object.freeze({ MAX_PINS, NOTE_MAX_LENGTH, REFUSALS, percentOf, accessibleNameOf, checkedPins });
})();
