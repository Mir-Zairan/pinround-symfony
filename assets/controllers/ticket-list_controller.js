import { Controller } from '@hotwired/stimulus';

// The tickets list page. TicketFilters sends the list's URL after each change; this loads it into the list frame and
// puts it in the address bar, so a reload or a shared link shows the same list. A chip adds a history entry, typing a
// search replaces the current one. Drive is off, so Back to an entry of this page that shows another list reloads it
// rather than leaving the list out of step with the address bar; a move that changes only the hash (the skip link)
// does not.
export default class extends Controller {
  static targets = ['frame', 'total'];

  connect() {
    this.shownList = listOf(window.location.href);
    this.reloadOnHistoryMove = () => {
      if (listOf(window.location.href) !== this.shownList) {
        window.location.reload();
      }
    };
    window.addEventListener('popstate', this.reloadOnHistoryMove);
  }

  disconnect() {
    window.removeEventListener('popstate', this.reloadOnHistoryMove);
  }

  show(event) {
    const url = new URL(event.detail.url, window.location.href);
    if (listOf(url.href) === this.shownList) {
      return;
    }
    if (event.detail.addsHistoryEntry) {
      window.history.pushState(window.history.state, '', url);
    } else {
      window.history.replaceState(window.history.state, '', url);
    }
    this.shownList = listOf(url.href);
    this.frameTarget.src = url.href;
  }

  // The count line sits outside the frame, in a live region, so each new list is announced once it has loaded.
  announce(event) {
    if (event.target !== this.frameTarget) {
      return;
    }
    const results = this.frameTarget.querySelector('[data-total-line]');
    if (results !== null) {
      this.totalTarget.textContent = results.dataset.totalLine;
    }
  }
}

// The list a URL shows: its path and query, without the hash.
function listOf(href) {
  const url = new URL(href, window.location.href);
  return url.pathname + url.search;
}
