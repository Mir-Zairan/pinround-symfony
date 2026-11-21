import { Controller } from '@hotwired/stimulus';

// The "⋯" menu uses the ARIA menu pattern (role="menu" with role="menuitem" buttons) because every item
// is an action, as in the prototype. Items stay out of the Tab order; arrow keys move focus between them.
// The avatar menu is a disclosure instead (links, a switch and a form, all in the Tab order): it binds
// closeOnEscape on its list where the "⋯" menu binds navigate.
// A list that only some screen widths collapse (the phone main menu, the phone filters) names a "closed" class instead
// of taking [hidden], which base.css hides at every width.
export default class extends Controller {
  static targets = ['trigger', 'list', 'item'];
  static classes = ['closed'];

  connect() {
    this.close();
  }

  get isOpen() {
    return this.triggerTarget.getAttribute('aria-expanded') === 'true';
  }

  toggle(event) {
    if (this.isOpen) {
      this.close();
      return;
    }
    this.open();
    // A keyboard activation of the button reports detail 0; a mouse click keeps focus on the trigger.
    if (event.detail === 0) {
      this.#focusItem(0);
    }
  }

  open() {
    this.#showList(true);
    this.triggerTarget.setAttribute('aria-expanded', 'true');
  }

  close() {
    this.#showList(false);
    this.triggerTarget.setAttribute('aria-expanded', 'false');
  }

  // Focus stays on the trigger after a mouse open, so the trigger needs Escape and Tab handling too. Tab closes
  // only a menu whose items are out of the Tab order; in a disclosure it moves on into the open list.
  triggerKey(event) {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      this.open();
      this.#focusItem(0);
    } else if (event.key === 'Escape' && this.isOpen) {
      event.preventDefault();
      this.close();
    } else if (event.key === 'Tab' && this.isOpen && !this.itemTargets.some((item) => item.tabIndex >= 0)) {
      this.close();
    }
  }

  closeOnFocusLeave(event) {
    if (this.isOpen && event.relatedTarget && !this.element.contains(event.relatedTarget)) {
      this.close();
    }
  }

  navigate(event) {
    const items = this.itemTargets;
    const index = items.indexOf(document.activeElement);
    const moves = {
      ArrowDown: (index + 1) % items.length,
      ArrowUp: (index - 1 + items.length) % items.length,
      Home: 0,
      End: items.length - 1,
    };

    if (event.key in moves) {
      event.preventDefault();
      this.#focusItem(moves[event.key]);
    } else if (event.key === 'Escape') {
      event.preventDefault();
      this.#closeToTrigger();
    } else if (event.key === 'Tab') {
      // Focus leaves from the trigger, so Tab continues after the menu and Shift+Tab before it.
      this.#closeToTrigger();
    }
  }

  // A nested menu that already took the Escape (the Teams list inside the phone menu) keeps the outer one open.
  closeOnEscape(event) {
    if (event.key === 'Escape' && !event.defaultPrevented) {
      event.preventDefault();
      this.#closeToTrigger();
    }
  }

  closeOnSelect(event) {
    if (event.target.closest('[role="menuitem"]')) {
      this.#closeToTrigger();
    }
  }

  closeOnOutsideClick(event) {
    if (this.isOpen && !this.element.contains(event.target)) {
      this.close();
    }
  }

  #closeToTrigger() {
    this.triggerTarget.focus();
    this.close();
  }

  #showList(isShown) {
    if (this.hasClosedClass) {
      this.listTarget.classList.toggle(this.closedClass, !isShown);
    } else {
      this.listTarget.hidden = !isShown;
    }
  }

  #focusItem(index) {
    this.itemTargets[index]?.focus();
  }
}
