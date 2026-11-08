import { Controller } from '@hotwired/stimulus';

// Home's focus chips. A click presses the chip and shows or hides the sections at once, then saves the choice in the
// background. Each section names the chip that shows it in data-focus-group; every section shows under "all".
//
// One save is in flight at a time, so two quick picks cannot reach the server in the wrong order: the newest pick waits
// for the running save, and a failed newest pick puts back the last chip the server answered 204 for.
export default class extends Controller {
  static targets = ['form', 'chip', 'section'];

  // The last chip the server saved, and the newest chip the person picked.
  #confirmedFocus = 'all';
  #wantedFocus = 'all';
  #isSaving = false;

  connect() {
    this.#confirmedFocus = this.#pressedFocus();
    this.#wantedFocus = this.#confirmedFocus;
  }

  choose(event) {
    const chip = event.target.closest('button[value]');
    if (!chip || !this.formTarget.contains(chip)) {
      return;
    }
    // The form posts and reloads Home only without this script.
    event.preventDefault();
    if (chip.value === this.#wantedFocus) {
      return;
    }

    this.#wantedFocus = chip.value;
    this.#apply(chip.value);
    if (!this.#isSaving) {
      this.#saveWantedFocus();
    }
  }

  async #saveWantedFocus() {
    this.#isSaving = true;
    while (this.#wantedFocus !== this.#confirmedFocus) {
      const focus = this.#wantedFocus;
      try {
        await this.#post(focus);
        this.#confirmedFocus = focus;
      } catch (error) {
        console.warn(error);
        // A newer pick is waiting and is sent next; only the newest pick's failure undoes the page.
        if (this.#wantedFocus === focus) {
          this.#wantedFocus = this.#confirmedFocus;
          this.#apply(this.#confirmedFocus);
        }
      }
    }
    this.#isSaving = false;
  }

  async #post(focus) {
    const body = new FormData(this.formTarget);
    body.set('focus', focus);
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
