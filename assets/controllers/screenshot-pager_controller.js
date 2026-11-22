import { Controller } from '@hotwired/stimulus';

// The left and right arrow keys of the round page (round.html.twig): each follows the tab link before or after the
// shown one, which the server built, so the tabs work without JavaScript; past either end there is no such link and the
// key does nothing. After the visit the shown tab takes the focus, so a keyboard user keeps their place in the tab row.
// With Drive off the visit is a full page load, so the key adds the fragment value to the address, which the next page's
// shown tab reads through fragment-focus. The fragment names no element, so the browser does not scroll to it.
// A key typed into a field or a radio chip, or pressed with a modifier, is left to the page.
export default class extends Controller {
  static targets = ['back', 'next'];
  static values = { fragment: String };

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
      const destination = new URL(link.href);
      destination.hash = this.fragmentValue;
      window.location.assign(destination);
    }
  }
}
