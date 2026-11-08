import { Controller } from '@hotwired/stimulus';

// Home's focus chips. A click presses the chip and shows or hides the sections at once, then saves the choice in the
// background. Each section names the chip that shows it in data-focus-group; every section shows under "all".
export default class extends Controller {
  static targets = ['form', 'chip', 'section'];

  // Only the newest save may undo its change when it fails; an older one would undo a later pick.
  #latestSave = 0;

  async choose(event) {
    const chip = event.target.closest('button[value]');
    if (!chip || !this.formTarget.contains(chip)) {
      return;
    }
    // The form posts and reloads Home only without this script.
    event.preventDefault();
    const previousFocus = this.#pressedFocus();
    if (chip.value === previousFocus) {
      return;
    }

    this.#apply(chip.value);
    const save = ++this.#latestSave;
    try {
      const body = new FormData(this.formTarget);
      body.set('focus', chip.value);
      // Only 204 means saved; a redirect is the firewall sending an ended session to the login page.
      const response = await fetch(this.formTarget.action, {
        method: 'POST',
        body,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        redirect: 'manual',
      });
      if (response.status !== 204) {
        throw new Error(`The focus was not saved (${response.status}).`);
      }
    } catch (error) {
      if (save === this.#latestSave) {
        this.#apply(previousFocus);
      }
      console.warn(error);
    }
  }

  #pressedFocus() {
    return this.chipTargets.find((chip) => chip.getAttribute('aria-pressed') === 'true')?.value ?? 'all';
  }

  #apply(focus) {
    for (const chip of this.chipTargets) {
      chip.setAttribute('aria-pressed', String(chip.value === focus));
    }
    for (const section of this.sectionTargets) {
      section.hidden = focus !== 'all' && section.dataset.focusGroup !== focus;
    }
  }
}
