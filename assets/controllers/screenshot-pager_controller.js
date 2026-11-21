import { Controller } from '@hotwired/stimulus';

// The round page's Back and Next (round.html.twig) on the left and right arrow keys. Both are links the server built,
// so the pager works without JavaScript; a key only follows one. At either end the link has no href, and the key does
// nothing. A key typed into a field or a radio chip, or pressed with a modifier, is left to the page.
export default class extends Controller {
  static targets = ['back', 'next'];

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
      link.click();
    }
  }
}
