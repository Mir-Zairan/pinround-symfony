// What the popup's "Put the screenshot in" picker does when pinround says a target is gone.
import { NEW_REPORT, choiceValueOf, forgetCaptureTarget, loadCaptureTarget } from '../capture/capture-target.js';

export const NO_CHOICE = '';

/**
 * Takes the target a capture was sent to out of the picker and out of storage once pinround says it is gone. A choice
 * the person made while that capture ran stays shown and remembered; "A new report" stays offered.
 *
 * @param {object} storage the extension's session storage
 * @param {HTMLSelectElement} picker
 * @param {Map<string, import('../capture/capture-target.js').CaptureTarget>} offeredTargets the picker's targets by option value
 * @param {import('../capture/capture-target.js').CaptureTarget} goneTarget
 */
export async function forgetGoneTarget(storage, picker, offeredTargets, goneTarget) {
    const goneValue = choiceValueOf(goneTarget);
    const isShown = goneValue === picker.value;
    const remembered = await loadCaptureTarget(storage);
    if (null !== remembered && goneValue === choiceValueOf(remembered)) {
        await forgetCaptureTarget(storage);
    }
    if (NEW_REPORT !== goneTarget.kind) {
        offeredTargets.delete(goneValue);
        [...picker.options].find((option) => goneValue === option.value)?.remove();
    }
    if (isShown) {
        picker.value = NO_CHOICE;
    }
}
