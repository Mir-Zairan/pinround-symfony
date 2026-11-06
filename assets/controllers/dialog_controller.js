import { Controller } from '@hotwired/stimulus';

// Opens the <dialog> in this controller's element as a modal. The browser makes the rest of the page inert,
// keeps Tab inside and closes on Escape; this controller adds the backdrop click and the focus hand-back.
export default class extends Controller {
  static targets = ['dialog'];

  opener = null;
  pressStartedOnBackdrop = false;

  connect() {
    // A dialog restored open from a page snapshot is not modal, so the page behind it would stay usable.
    if (this.dialogTarget.open && !this.dialogTarget.matches(':modal')) {
      this.dialogTarget.close();
    }
  }

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

  rememberPress(event) {
    this.pressStartedOnBackdrop = event.target === this.dialogTarget;
  }

  // The dialog box has no padding of its own, so an event whose target is the <dialog> itself hit the backdrop.
  // Both the press and the click must: a drag that starts inside the box and ends outside must not close it.
  closeOnBackdrop(event) {
    const startedOnBackdrop = this.pressStartedOnBackdrop;
    this.pressStartedOnBackdrop = false;
    if (startedOnBackdrop && event.target === this.dialogTarget) {
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
