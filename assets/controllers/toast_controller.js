import { Controller } from '@hotwired/stimulus';

const ANNOUNCE_DELAY_MS = 150;

// Writes the message into the empty toast (or its text target, beside an action such as Undo) a moment after connect,
// so the page's role="status" region sees it arrive and screen readers announce it, then removes the toast after
// shownForMs (3.6 s, longer for a toast with an action, which the server still checks). The toast has no enter or exit
// animation, so prefers-reduced-motion needs no special case; the region keeps its role for the next toast.
//
// A toast with an action stays while the pointer is on it or focus is in it (WCAG 2.2.1), and its time runs on when
// both leave. The streamed parts of a move replace the button that sent it, which drops focus to the body; the action
// then takes the focus, so a keyboard user reaches Undo without tabbing through the page (WCAG 2.4.3). Focus that is
// still somewhere on the page stays there.
export default class extends Controller {
  static targets = ['text', 'action'];
  static values = { message: String, shownForMs: { type: Number, default: 3600 } };

  #timer = null;
  #remainingMs = 0;
  #shownUntil = 0;
  #isHovered = false;
  #hasFocusWithin = false;
  #isShown = false;

  connect() {
    this.#timer = setTimeout(() => {
      (this.hasTextTarget ? this.textTarget : this.element).textContent = this.messageValue;
      if (this.hasActionTarget && this.#isFocusLost()) {
        this.actionTarget.focus();
      }
      this.#remainingMs = this.shownForMsValue;
      this.#isShown = true;
      this.#runRemovalTimer();
    }, ANNOUNCE_DELAY_MS);
  }

  disconnect() {
    clearTimeout(this.#timer);
  }

  pointerEntered() {
    this.#isHovered = true;
    this.#pause();
  }

  pointerLeft() {
    this.#isHovered = false;
    this.#runRemovalTimer();
  }

  focusEntered() {
    this.#hasFocusWithin = true;
    this.#pause();
  }

  focusLeft(event) {
    if (this.element.contains(event.relatedTarget)) {
      return;
    }
    this.#hasFocusWithin = false;
    this.#runRemovalTimer();
  }

  #isFocusLost() {
    const focused = document.activeElement;

    return focused === null || focused === document.body || !focused.isConnected;
  }

  #pause() {
    if (this.#shownUntil === 0) {
      return;
    }
    clearTimeout(this.#timer);
    this.#remainingMs = Math.max(0, this.#shownUntil - Date.now());
    this.#shownUntil = 0;
  }

  #runRemovalTimer() {
    if (this.#isShown && (!this.hasActionTarget || (!this.#isHovered && !this.#hasFocusWithin))) {
      clearTimeout(this.#timer);
      this.#shownUntil = Date.now() + this.#remainingMs;
      this.#timer = setTimeout(() => this.element.remove(), this.#remainingMs);
    }
  }
}
