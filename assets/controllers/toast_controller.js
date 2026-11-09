import { Controller } from '@hotwired/stimulus';

const ANNOUNCE_DELAY_MS = 150;
const SHOWN_FOR_MS = 3600;

// Writes the message into the empty toast a moment after connect, so the page's role="status" region sees it
// arrive and screen readers announce it, then removes the toast 3.6 s later. The toast has no enter or exit
// animation, so prefers-reduced-motion needs no special case; the region keeps its role for the next toast.
export default class extends Controller {
  static values = { message: String };

  #timer = null;

  connect() {
    this.#timer = setTimeout(() => {
      this.element.textContent = this.messageValue;
      this.#timer = setTimeout(() => this.element.remove(), SHOWN_FOR_MS);
    }, ANNOUNCE_DELAY_MS);
  }

  disconnect() {
    clearTimeout(this.#timer);
  }
}
