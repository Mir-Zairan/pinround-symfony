// Where keyboard focus goes when the control that had it is hidden: a hidden control drops its focus to the page body,
// where a keyboard or screen reader user loses their place.

/**
 * Focuses the first candidate the person can see.
 *
 * @param {Array<HTMLElement|null>} candidates in order of preference; null ones are skipped
 * @returns {HTMLElement|null} the one focused, or null when none can be seen
 */
export function focusFirstVisible(candidates) {
    const visible = candidates.find((candidate) => null !== candidate && candidate.checkVisibility()) ?? null;
    visible?.focus();

    return visible;
}
