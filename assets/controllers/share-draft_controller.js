import { Controller } from '@hotwired/stimulus';

// The Share draft dialog arrives in a Turbo Stream with no button that opens it: the Dialog's openOnConnect prop opens
// it on connect (and hands focus back to the move button), and this controller takes it off the page again once it is
// closed, by Cancel, Escape or the answer to the move.
export default class extends Controller {
  connect() {
    this.element.querySelector('dialog').addEventListener('close', () => this.element.remove(), { once: true });
  }

  close() {
    this.element.querySelector('dialog').close();
  }
}
