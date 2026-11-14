import { Controller } from '@hotwired/stimulus';

// An Answer or Reply box: a <details> around the form that writes under one message of the thread.
export default class extends Controller {
  static targets = ['body', 'opener'];

  // toggle: an opened box takes the focus to its text, so the keyboard user writes at once.
  focus() {
    if (this.element.open) {
      this.bodyTarget.focus();
    }
  }

  // Cancel folds the box away, keeps what was typed and puts focus back on its button, which would otherwise drop to
  // the body with the hidden Cancel (WCAG 2.4.3).
  close() {
    this.element.open = false;
    this.openerTarget.focus();
  }

  // turbo:submit-end: a refused reply answers with only a toast and leaves the box open, but Turbo disabled the
  // submit button it was sent with, which dropped focus to the body, so focus goes back to the kept text.
  settle(event) {
    if (!event.detail.success && this.element.open) {
      this.bodyTarget.focus();
    }
  }
}
