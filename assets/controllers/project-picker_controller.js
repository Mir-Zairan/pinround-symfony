import { Controller } from '@hotwired/stimulus';

// The "Add a project" dialog of the team page: filters the list of projects by key or name and keeps the submit
// button in step with the checked boxes. The button starts enabled in the HTML and is disabled on connect, so the
// form still works without JavaScript. Hidden options stay checked and are still sent.
export default class extends Controller {
  static targets = ['search', 'option', 'noMatch', 'submit'];

  connect() {
    this.filter();
    this.update();
  }

  filter() {
    if (!this.hasSearchTarget) {
      return;
    }
    const words = this.searchTarget.value.toLowerCase().split(/\s+/).filter(Boolean);
    let shown = 0;
    for (const option of this.optionTargets) {
      const matches = words.every((word) => option.dataset.search.includes(word));
      option.hidden = !matches;
      if (matches) {
        shown += 1;
      }
    }
    if (this.hasNoMatchTarget) {
      this.noMatchTarget.hidden = shown > 0;
    }
  }

  // Enter in the search field would submit the link form with whatever is ticked.
  ignoreEnter(event) {
    event.preventDefault();
  }

  update() {
    if (!this.hasSubmitTarget) {
      return;
    }
    const chosen = this.optionTargets.filter((option) => option.querySelector('input[type="checkbox"]').checked).length;
    this.submitTarget.disabled = chosen === 0;
    this.submitTarget.textContent = chosen > 1 ? `Add ${chosen} projects` : 'Add project';
  }
}
