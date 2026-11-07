import { Controller } from '@hotwired/stimulus';

const THEMES_ON_THE_PAGE = ['light', 'dark'];

// The avatar menu's System | Light | Dark switch. A click applies the theme at once and saves it in the
// background; "system" removes data-theme from <html>, so prefers-color-scheme decides again.
export default class extends Controller {
  static targets = ['choice'];

  // Only the newest save may undo its change when it fails; an older one would undo a later pick.
  #latestSave = 0;

  async choose(event) {
    const option = event.target.closest('button[value]');
    if (!option || !this.element.contains(option) || option.value === this.choiceTarget.value) {
      return;
    }

    const previousTheme = this.choiceTarget.value;
    this.#apply(option.value);
    const save = ++this.#latestSave;
    try {
      // Only 204 means saved; a redirect is the firewall sending an ended session to the login page.
      const response = await fetch(this.element.action, {
        method: 'POST',
        body: new FormData(this.element),
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        redirect: 'manual',
      });
      if (response.status !== 204) {
        throw new Error(`The theme was not saved (${response.status}).`);
      }
    } catch (error) {
      if (save === this.#latestSave) {
        this.#apply(previousTheme);
      }
      console.warn(error);
    }
  }

  #apply(theme) {
    this.choiceTarget.value = theme;
    for (const button of this.element.querySelectorAll('button[value]')) {
      button.setAttribute('aria-pressed', String(button.value === theme));
    }
    // The profile page's theme radios follow, so saving that form next does not undo this pick.
    for (const radio of document.querySelectorAll('input[type="radio"][name="theme"]')) {
      radio.checked = radio.value === theme;
    }
    if (THEMES_ON_THE_PAGE.includes(theme)) {
      document.documentElement.dataset.theme = theme;
    } else {
      delete document.documentElement.dataset.theme;
    }
  }
}
