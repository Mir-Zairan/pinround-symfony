import { Controller } from '@hotwired/stimulus';

const ANNOUNCE_DELAY_MS = 150;

// Writes the message into the empty toast (or its text target, beside an action such as Undo) a moment after connect,
// so the page's role="status" region sees it arrive and screen readers announce it, then removes the toast after
// shownForMs (3.6 s, longer for a toast with an action, which the server still checks). The toast has no enter or exit
// animation, so prefers-reduced-motion needs no special case; the region keeps its role for the next toast.
export default class extends Controller {
  static targets = ['text'];
  static values = { message: String, shownForMs: { type: Number, default: 3600 } };

  #timer = null;

  connect() {
    this.#timer = setTimeout(() => {
      (this.hasTextTarget ? this.textTarget : this.element).textContent = this.messageValue;
      this.#timer = setTimeout(() => this.element.remove(), this.shownForMsValue);
    }, ANNOUNCE_DELAY_MS);
  }

  disconnect() {
    clearTimeout(this.#timer);
  }
}
