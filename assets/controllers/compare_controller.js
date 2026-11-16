import { Controller } from '@hotwired/stimulus';

// The compare control of the review round page: shows the "before" picture, the "after" picture or both, by setting
// data-compare-mode on its element (review.css hides the other figure). The control starts hidden, so without
// JavaScript the page shows both pictures and no control that does nothing. A round with no screen areas has no
// control at all.
export default class extends Controller {
  static targets = ['control'];

  connect() {
    if (!this.hasControlTarget) {
      return;
    }
    this.controlTarget.hidden = false;
    this.#show(this.element.dataset.compareMode || 'both');
  }

  // A click anywhere in the SegmentedControl; only its buttons carry a mode as their value.
  choose(event) {
    const option = event.target.closest('button[value]');
    if (option && this.controlTarget.contains(option)) {
      this.#show(option.value);
    }
  }

  #show(mode) {
    this.element.dataset.compareMode = mode;
    for (const option of this.controlTarget.querySelectorAll('button[value]')) {
      option.setAttribute('aria-pressed', String(option.value === mode));
    }
  }
}
