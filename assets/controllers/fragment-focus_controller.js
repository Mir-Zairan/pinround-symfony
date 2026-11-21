import { Controller } from '@hotwired/stimulus';

// A link another page sends people back to: the Full screen links of the round page and of the report's card
// (round.html.twig, rounds_frame.html.twig). The full-screen review's exit names in the address's fragment the link that
// opened it, or, for the report's card, the ticket page's Rounds frame, which loads only once the browser has scrolled
// to it. The link takes focus when it appears and the fragment is its fragment value, else its own id. The fragment is
// then dropped from the address: a live update that loads the frame again must not take focus back.
export default class extends Controller {
  static values = { fragment: String };

  connect() {
    const fragment = this.fragmentValue === '' ? this.element.id : this.fragmentValue;
    if (fragment === '' || window.location.hash.slice(1) !== fragment) {
      return;
    }
    this.element.focus();
    const url = new URL(window.location.href);
    url.hash = '';
    window.history.replaceState(window.history.state, '', url);
  }
}
