import { Controller } from '@hotwired/stimulus';

// The tickets list page. TicketFilters sends the list's URL after each change; this loads it into the list frame and
// puts it in the address bar, so a reload or a shared link shows the same list. A chip adds a history entry, typing a
// search replaces the current one. Drive is off, so Back to an entry of this page reloads it rather than leaving the
// list out of step with the address bar.
export default class extends Controller {
  static targets = ['frame'];

  connect() {
    this.reloadOnHistoryMove = () => window.location.reload();
    window.addEventListener('popstate', this.reloadOnHistoryMove);
  }

  disconnect() {
    window.removeEventListener('popstate', this.reloadOnHistoryMove);
  }

  show(event) {
    const url = new URL(event.detail.url, window.location.href);
    if (url.href === window.location.href) {
      return;
    }
    if (event.detail.addsHistoryEntry) {
      window.history.pushState(window.history.state, '', url);
    } else {
      window.history.replaceState(window.history.state, '', url);
    }
    this.frameTarget.src = url.href;
  }
}
