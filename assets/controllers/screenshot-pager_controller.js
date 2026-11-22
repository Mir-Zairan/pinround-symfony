import { Controller } from '@hotwired/stimulus';

// Set before an arrow key's visit and read by the next page's pager, which Turbo connects in the same document.
let focusShownAfterVisit = false;

// The left and right arrow keys of the round page (round.html.twig): each follows the tab link before or after the
// shown one, which the server built, so the tabs work without JavaScript; past either end there is no such link and the
// key does nothing. After the visit the shown tab takes the focus, so a keyboard user keeps their place in the tab row.
// A key typed into a field or a radio chip, or pressed with a modifier, is left to the page.
export default class extends Controller {
  static targets = ['back', 'next', 'shown'];

  connect() {
    if (focusShownAfterVisit && this.hasShownTarget) {
      this.shownTarget.focus();
    }
    // A cached preview connects before the page itself, which must focus its tab too.
    if (!document.documentElement.hasAttribute('data-turbo-preview')) {
      focusShownAfterVisit = false;
    }
  }

  follow(event) {
    if (event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
      return;
    }
    if (event.target instanceof Element && event.target.closest('input, textarea, select, [contenteditable]')) {
      return;
    }
    const link = { ArrowLeft: this.hasBackTarget ? this.backTarget : null, ArrowRight: this.hasNextTarget ? this.nextTarget : null }[event.key];
    if (link instanceof HTMLAnchorElement && link.hasAttribute('href')) {
      event.preventDefault();
      focusShownAfterVisit = true;
      link.click();
    }
  }
}
