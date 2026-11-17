import { Controller } from '@hotwired/stimulus';

// The full-screen review (focus.html.twig): one pin at a time. Every pin's bar is in the page and the server shows the
// one its "?pin=" names, so Previous and Next are links that also work without JavaScript. Here they switch bars in
// place, a click on a ring picks its pin, the left and right arrow keys walk (wrapping at both ends) and Escape goes
// back to the round page. The address keeps the shown pin, so a reload or a verdict form's answer stays on it.
export default class extends Controller {
  static targets = ['stop', 'exit'];
  static values = { parameter: String };

  walk(event) {
    if (event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
      return;
    }
    if (event.target instanceof Element && event.target.closest('input, textarea, select, [contenteditable]')) {
      return;
    }
    if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
      event.preventDefault();
      this.#step(event.key === 'ArrowRight' ? 1 : -1);
    } else if (event.key === 'Escape' && this.hasExitTarget) {
      event.preventDefault();
      window.location.assign(this.exitTarget.href);
    }
  }

  previous(event) {
    event.preventDefault();
    this.#step(-1);
  }

  next(event) {
    event.preventDefault();
    this.#step(1);
  }

  // A click anywhere in the page; only the pin rings carry a pin number.
  selectPin(event) {
    const ring = event.target instanceof Element ? event.target.closest('.pin[data-pin-number]') : null;
    if (!ring) {
      return;
    }
    const index = this.stopTargets.findIndex((stop) => stop.dataset.pinNumber === ring.dataset.pinNumber);
    if (index >= 0) {
      this.#show(index);
    }
  }

  #step(delta) {
    const count = this.stopTargets.length;
    if (count > 0) {
      this.#show((this.#currentIndex() + delta + count) % count);
    }
  }

  #currentIndex() {
    return Math.max(0, this.stopTargets.findIndex((stop) => !stop.hidden));
  }

  #show(index) {
    const shown = this.stopTargets[this.#currentIndex()];
    const focused = document.activeElement;
    // The control that had focus is about to be hidden: its twin in the new bar takes it (Fixed and Undo have none in
    // a bar of the other kind, so the bar's first button does).
    const focusedControl = shown.contains(focused) ? focused.dataset.pinWalkerControl : null;
    const stop = this.stopTargets[index];
    this.stopTargets.forEach((other, otherIndex) => {
      other.hidden = otherIndex !== index;
    });
    for (const ring of this.element.querySelectorAll('.pin[data-pin-number]')) {
      const isSelected = ring.dataset.pinNumber === stop.dataset.pinNumber;
      ring.classList.toggle('pin--selected', isSelected);
      ring.setAttribute('aria-pressed', String(isSelected));
    }
    if (focusedControl !== null && focusedControl !== undefined) {
      (stop.querySelector(`[data-pin-walker-control="${focusedControl}"]`) ?? stop.querySelector('button[data-pin-walker-control]') ?? stop.querySelector('[data-pin-walker-control]'))?.focus();
    }
    const url = new URL(window.location.href);
    url.searchParams.set(this.parameterValue, stop.dataset.pinPosition);
    window.history.replaceState(window.history.state, '', url);
  }
}
