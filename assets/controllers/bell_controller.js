import { Controller } from '@hotwired/stimulus';

// The bell's non-modal popover (Bell.html.twig). Its cards hold forms in the Tab order, so it is a disclosure, not a
// menu: Tab moves into it, Escape and Close put focus back on the bell.
export default class extends Controller {
  static targets = ['trigger', 'popover', 'frame'];

  connect() {
    // A snapshot Turbo restores may hold it open.
    this.close();
  }

  get isOpen() {
    return this.triggerTarget.getAttribute('aria-expanded') === 'true';
  }

  toggle() {
    if (this.isOpen) {
      this.close();
      return;
    }
    this.open();
  }

  open() {
    this.popoverTarget.hidden = false;
    this.triggerTarget.setAttribute('aria-expanded', 'true');
    // The lazy frame loads the first time it shows; later opens load it again, as the cards may have changed.
    if (this.frameTarget.complete) {
      this.frameTarget.reload();
    }
    this.popoverTarget.focus();
  }

  close() {
    this.popoverTarget.hidden = true;
    this.triggerTarget.setAttribute('aria-expanded', 'false');
  }

  closeToTrigger() {
    this.close();
    this.triggerTarget.focus();
  }

  // Shift+Tab out of the open popover lands on the trigger, so Escape closes from there too.
  triggerKey(event) {
    if (event.key === 'Escape' && this.isOpen) {
      event.preventDefault();
      this.close();
    }
  }

  closeOnEscape(event) {
    if (event.key === 'Escape') {
      event.preventDefault();
      this.closeToTrigger();
    }
  }

  closeOnFocusLeave(event) {
    if (this.isOpen && event.relatedTarget && !this.element.contains(event.relatedTarget)) {
      this.close();
    }
  }

  closeOnOutsideClick(event) {
    if (this.isOpen && event.target.isConnected && !this.element.contains(event.target)) {
      this.close();
    }
  }

  // Done's stream replaces the card that held focus; focus goes back to the popover instead of the page's start.
  keepFocusAfterStream(event) {
    if (this.isOpen && this.isFrameSwap(event.target) && this.hasFrameLoaded()) {
      event.preventDefault();
      this.reloadFrameKeepingFocus();
      return;
    }
    const render = event.detail.render;
    event.detail.render = async (streamElement) => {
      await render(streamElement);
      if (this.isOpen && !this.element.contains(document.activeElement)) {
        this.popoverTarget.focus();
      }
    };
  }

  // The bell push replaces the frame with a fresh lazy one, which would wipe the open cards for "Loading pings…" and
  // drop focus; an open popover loads its cards in place instead.
  isFrameSwap(streamElement) {
    return streamElement.getAttribute('action') === 'replace' && streamElement.getAttribute('target') === this.frameTarget.id;
  }

  // While the lazy first load runs, a reload would cancel it and Turbo never loads the frame again.
  hasFrameLoaded() {
    return this.frameTarget.querySelector('.bell-popover__loading') === null;
  }

  // Each card's forms post to their own group's URL, so the form's action finds the same control in the new cards.
  async reloadFrameKeepingFocus() {
    const hadFocus = this.element.contains(document.activeElement);
    const focusedAction = this.frameTarget.contains(document.activeElement) ? document.activeElement.closest('form')?.action : undefined;
    await this.frameTarget.reload();
    if (!hadFocus || !this.isOpen || this.element.contains(document.activeElement)) {
      return;
    }
    const sameForm = [...this.frameTarget.querySelectorAll('form')].find((form) => form.action === focusedAction);
    (sameForm?.querySelector('button') ?? this.popoverTarget).focus();
  }
}
