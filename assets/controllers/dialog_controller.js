import { Controller } from '@hotwired/stimulus';

// Opens the <dialog> in this controller's element as a modal. The browser makes the rest of the page inert,
// keeps Tab inside and closes on Escape; this controller adds the backdrop click and the focus hand-back.
export default class extends Controller {
  static targets = ['dialog'];

  opener = null;
  dialogElement = null;
  pressStartedOnBackdrop = false;
  pressEndedOnBackdrop = false;

  connect() {
    // Kept for disconnect(): once the controller element loses data-controller, Stimulus no longer finds targets.
    this.dialogElement = this.dialogTarget;
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
    this.pressEndedOnBackdrop = false;
  }

  rememberRelease(event) {
    this.pressEndedOnBackdrop = event.target === this.dialogTarget;
  }

  // The dialog box has no padding of its own, so an event whose target is the <dialog> itself hit the backdrop.
  // Press and release must both be there: a drag between the box and the backdrop, in either direction, still
  // fires a click on the <dialog> (the common ancestor) and must not close it.
  closeOnBackdrop(event) {
    const pressedAndReleasedOnBackdrop = this.pressStartedOnBackdrop && this.pressEndedOnBackdrop;
    this.pressStartedOnBackdrop = false;
    this.pressEndedOnBackdrop = false;
    if (pressedAndReleasedOnBackdrop && event.target === this.dialogTarget) {
      this.close();
    }
  }

  disconnect() {
    if (this.dialogElement?.open) {
      this.dialogElement.close();
    }
  }

  #restoreFocus() {
    if (this.opener?.isConnected) {
      this.opener.focus();
    }
    this.opener = null;
  }
}
