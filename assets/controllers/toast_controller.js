import { Controller } from '@hotwired/stimulus';

const SHOWN_FOR_MS = 3600;

// Removes the toast after 3.6 s. The toast has no enter or exit animation, so prefers-reduced-motion
// needs no special case; the region around it keeps its role="status" for the next toast.
export default class extends Controller {
  #timer = null;

  connect() {
    this.#timer = setTimeout(() => this.element.remove(), SHOWN_FOR_MS);
  }

  disconnect() {
    clearTimeout(this.#timer);
  }
}
