import { Controller } from '@hotwired/stimulus';

// The Share draft dialog arrives in a Turbo Stream with no button that opens it: open it on connect, and take it off
// the page again once it is closed, by Cancel, Escape or the answer to the move.
export default class extends Controller {
  connect() {
    const dialog = this.element.querySelector('dialog');
    dialog.addEventListener('close', () => this.element.remove(), { once: true });
    dialog.showModal();
  }

  close() {
    this.element.querySelector('dialog').close();
  }
}
