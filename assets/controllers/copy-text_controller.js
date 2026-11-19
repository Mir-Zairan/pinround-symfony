import { Controller } from '@hotwired/stimulus';

const COPIED_SHOWN_FOR_MS = 2000;

// Copies the source field's value to the clipboard, exactly as the page rendered it, and says "Copied" in the status
// live region for COPIED_SHOWN_FOR_MS. The controls (button and status) come hidden and show only where the Clipboard API
// exists (a secure context), so a page without JavaScript or without the API has no button that does nothing. When the
// browser refuses, the text is selected for a manual copy and the status says so until the next try.
// data-copy-text-connected on the element marks that the Clipboard check has run, since hidden controls alone cannot
// tell a missing API from a controller that never connected.
export default class extends Controller {
  static targets = ['source', 'controls', 'status'];

  #clearTimer = null;
  #sayFrame = null;

  connect() {
    this.controlsTarget.hidden = !navigator.clipboard?.writeText;
    this.element.dataset.copyTextConnected = '';
  }

  disconnect() {
    clearTimeout(this.#clearTimer);
    cancelAnimationFrame(this.#sayFrame);
    delete this.element.dataset.copyTextConnected;
  }

  async copy() {
    try {
      await navigator.clipboard.writeText(this.sourceTarget.value);
    } catch {
      this.sourceTarget.select();
      this.say('Could not copy. Select the text and copy it.');
      return;
    }
    this.say('Copied');
    this.#clearTimer = setTimeout(() => {
      this.statusTarget.textContent = '';
    }, COPIED_SHOWN_FOR_MS);
  }

  // The text is cleared first and set on the next frame: a live region does not announce a message equal to the last,
  // so a second copy is announced again.
  say(message) {
    clearTimeout(this.#clearTimer);
    cancelAnimationFrame(this.#sayFrame);
    this.statusTarget.textContent = '';
    this.#sayFrame = requestAnimationFrame(() => {
      this.statusTarget.textContent = message;
    });
  }
}
