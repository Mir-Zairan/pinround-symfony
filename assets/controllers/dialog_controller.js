import { Controller } from '@hotwired/stimulus';

// Opens the <dialog> in this controller's element as a modal. The browser makes the rest of the page inert,
// keeps Tab inside and closes on Escape; this controller adds the backdrop click and the focus hand-back.
export default class extends Controller {
  static targets = ['dialog'];

  opener = null;

  open(event) {
    if (this.dialogTarget.open) {
      return;
    }
    // Not document.activeElement: Safari does not focus a button on click.
    this.opener = event.currentTarget;
    this.dialogTarget.addEventListener('close', () => this.#restoreFocus(), { once: true });
    this.dialogTarget.showModal();
  }

  close() {
    this.dialogTarget.close();
  }

  // The dialog box has no padding of its own, so a click whose target is the <dialog> itself hit the backdrop.
  closeOnBackdrop(event) {
    if (event.target === this.dialogTarget) {
      this.close();
    }
  }

  disconnect() {
    if (this.hasDialogTarget && this.dialogTarget.open) {
      this.dialogTarget.close();
    }
  }

  #restoreFocus() {
    if (this.opener?.isConnected) {
      this.opener.focus();
    }
    this.opener = null;
  }
}
