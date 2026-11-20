// pin-rules.js as a module, for the popup and node --test; the overlay in the page reads the same global.
import './pin-rules.js';

export const { MAX_PINS, NOTE_MAX_LENGTH, REFUSALS, percentOf, accessibleNameOf, checkedPins } = globalThis.pinroundPinRules;
