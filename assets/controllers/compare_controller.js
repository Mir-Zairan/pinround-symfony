import { Controller } from '@hotwired/stimulus';

// The compare control of the review round page: shows the "before" picture, the "after" picture or both, by setting
// data-compare-mode on its element (review.css hides the other figure). The buttons start hidden, so without
// JavaScript the page shows both pictures and no control that does nothing.
export default class extends Controller {
  static targets = ['control', 'choice'];

  connect() {
    this.controlTarget.hidden = false;
    this.#show(this.element.dataset.compareMode || 'both');
  }

  choose(event) {
    this.#show(event.currentTarget.dataset.mode);
  }

  #show(mode) {
    this.element.dataset.compareMode = mode;
    for (const choice of this.choiceTargets) {
      choice.setAttribute('aria-pressed', choice.dataset.mode === mode ? 'true' : 'false');
    }
  }
}
