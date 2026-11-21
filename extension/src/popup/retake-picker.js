// The popup's "Add it as" picker: for a draft round target, "New screenshot" or a retake of one of that draft's
// screenshots (ADR-0048), listed by pinround each time the target changes or a capture lands.
import { NEW_SCREENSHOT, NEW_SCREENSHOT_CHOICE, retakeChoices } from '../capture/capture-to-target.js';

export class RetakePicker {
    #element;
    #api;
    #optionOf;
    /** The ticket whose draft screenshots the picker lists; null while it offers only "New screenshot". */
    #ticketKey = null;
    /** Counts the list loads, so an answer for a target the person has since left is dropped. */
    #loadCount = 0;

    /**
     * @param {(id: string) => HTMLElement} element the popup's element by id
     * @param {import('../shared/api.js').PinroundApi} api
     * @param {(label: string, value: string) => HTMLOptionElement} optionOf makes an option whose label is text, never
     *   markup: draft titles are text other people typed
     */
    constructor(element, api, optionOf = (label, value) => new Option(label, value)) {
        this.#element = element;
        this.#api = api;
        this.#optionOf = optionOf;
    }

    /**
     * Shows the picker for a draft round target, filled from pinround's list of that draft's screenshots; any other
     * target, or none, hides it. `keptValue` stays chosen when the list still offers it.
     *
     * @param {import('../capture/capture-target.js').CaptureTarget|undefined} target
     * @returns {Promise<string|null>} why the list could not be loaded, for the status line; null when it loaded, was
     *   not needed, or was overtaken by a later call
     */
    async showFor(target, keptValue = NEW_SCREENSHOT) {
        this.#loadCount += 1;
        const load = this.#loadCount;
        const isDraftRound = undefined !== target && 'ticket' === target.kind;
        this.#ticketKey = null;
        this.#fill([NEW_SCREENSHOT_CHOICE], NEW_SCREENSHOT);
        this.#element('retake-fields').hidden = !isDraftRound;
        if (!isDraftRound) {
            return null;
        }
        const { choices, loadProblem } = await retakeChoices(this.#api, target.ticketKey);
        if (load !== this.#loadCount) {
            return null;
        }
        this.#fill(choices, keptValue);
        this.#ticketKey = target.ticketKey;

        return loadProblem;
    }

    /** @returns {string|null} the screenshot_id a capture into `target` retakes, null for a new screenshot */
    retakeOfFor(target) {
        const value = this.#element('retake-of').value;

        return 'ticket' === target.kind && target.ticketKey === this.#ticketKey && NEW_SCREENSHOT !== value ? value : null;
    }

    #fill(choices, keptValue) {
        const picker = this.#element('retake-of');
        picker.replaceChildren(...choices.map((choice) => this.#optionOf(choice.label, choice.value)));
        picker.value = choices.some((choice) => keptValue === choice.value) ? keptValue : NEW_SCREENSHOT;
    }
}
