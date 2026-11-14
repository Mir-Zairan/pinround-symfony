import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';

// The subscriber cookie lasts 10 min (RealtimeSubscriptions::LIFETIME_SECONDS); renewing at 8 leaves room for a slow
// answer, and every renewal checks the session, the membership and the ticket again.
const RENEW_EVERY_MS = 8 * 60 * 1000;
// ux-principles.md: after 60 s with no connection the page says updates are paused.
const PAUSED_AFTER_MS = 60 * 1000;
// Spreads the re-fetches of everyone looking at the ticket over a second instead of one spike.
const MAX_JITTER_MS = 1000;
// A stream the hub closed (an expired cookie answers 401) is not reopened by the browser; this one tries again after
// 5 s, doubling to at most a minute, so a signed-out tab does not ask for a cookie every few seconds.
const FIRST_REOPEN_AFTER_MS = 5000;
const MAX_REOPEN_AFTER_MS = 60 * 1000;
// RefreshTicketPagesHandler::SIGNAL_TYPE.
const SIGNAL_TYPE = 'refresh';
// Marks what a signal fetches again: a turbo-frame with a src reloads; any other element with an id is swapped for the
// element of the same id in the page fetched again.
const REFRESHED_PARTS = '[data-live-refresh]';
const FORM_FIELDS = 'input, textarea, select';

// A frame with an open dialog, or with a field being typed in outside a data-turbo-permanent element (which a frame
// reload keeps), waits for the next signal rather than lose the text.
function isFrameInUse(frame) {
  const focused = document.activeElement;
  const isTyping = focused !== null && frame.contains(focused) && focused.matches(FORM_FIELDS) && focused.closest('[data-turbo-permanent]') === null;
  return isTyping || frame.querySelector('dialog[open]') !== null;
}

// A swapped part loses its focus and open dialogs, so a part that holds either waits for the next signal.
function isPartInUse(part) {
  return part.contains(document.activeElement) || part.querySelector('dialog[open]') !== null;
}

// Follows the live updates of a page (realtime-and-notifications.md): asks POST /realtime/token for the subscriber
// cookie first, as the page sets none, then opens the hub's stream. A refresh signal carries no content, so on one the
// page fetches again, with the viewer's own session, what carries data-live-refresh, or else the whole page through
// Turbo. Signals that come while the tab is hidden are remembered and fetched once when it is shown again.
export default class extends Controller {
  static targets = ['paused', 'reload'];
  static values = { subscribeUrl: String, tokenUrl: String, ticketIds: Array };

  #source = null;
  #renewTimer = null;
  #pausedTimer = null;
  #reopenTimer = null;
  #refetchTimer = null;
  #stopTimer = null;
  #reopenAfterMs = FIRST_REOPEN_AFTER_MS;
  #isSignalMissed = false;
  #onSignal = () => this.#refetchWhenVisible();
  #onVisibilityChange = () => this.#refetchMissedSignal();

  connect() {
    // The element is data-turbo-permanent: a Turbo visit moves it into the new page, which Stimulus sees as a
    // disconnect and a connect of the same controller. Keeping the stream then loses no signal and asks for no cookie.
    if (this.#stopTimer !== null) {
      clearTimeout(this.#stopTimer);
      this.#stopTimer = null;
      return;
    }
    document.addEventListener('visibilitychange', this.#onVisibilityChange);
    this.#renewTimer = setInterval(() => this.#renewCookie(), RENEW_EVERY_MS);
    this.#open();
  }

  disconnect() {
    this.#stopTimer = setTimeout(() => this.#stop(), 0);
  }

  #stop() {
    this.#stopTimer = null;
    document.removeEventListener('visibilitychange', this.#onVisibilityChange);
    clearInterval(this.#renewTimer);
    clearTimeout(this.#pausedTimer);
    clearTimeout(this.#reopenTimer);
    clearTimeout(this.#refetchTimer);
    this.#pausedTimer = null;
    this.#refetchTimer = null;
    this.#closeSource();
  }

  async #open() {
    this.#startPausedTimer();
    await this.#renewCookie();
    if (!this.element.isConnected) {
      return;
    }
    this.#closeSource();
    this.#source = new EventSource(this.subscribeUrlValue, { withCredentials: true });
    this.#source.addEventListener(SIGNAL_TYPE, this.#onSignal);
    this.#source.addEventListener('open', () => this.#showConnected());
    this.#source.addEventListener('error', () => this.#showDisconnected());
  }

  async #renewCookie() {
    const body = new FormData();
    this.ticketIdsValue.forEach((ticketId) => body.append('ticketIds[]', ticketId));
    try {
      await fetch(this.tokenUrlValue, { method: 'POST', body, credentials: 'same-origin' });
    } catch {
      // Offline: the stream fails to open or drops, and the paused banner says so after 60 s.
    }
  }

  #showConnected() {
    clearTimeout(this.#pausedTimer);
    this.#pausedTimer = null;
    this.#reopenAfterMs = FIRST_REOPEN_AFTER_MS;
    this.pausedTarget.hidden = true;
  }

  #showDisconnected() {
    this.#startPausedTimer();
    if (this.#source?.readyState === EventSource.CLOSED) {
      clearTimeout(this.#reopenTimer);
      this.#reopenTimer = setTimeout(() => this.#open(), this.#reopenAfterMs);
      this.#reopenAfterMs = Math.min(this.#reopenAfterMs * 2, MAX_REOPEN_AFTER_MS);
    }
  }

  #startPausedTimer() {
    if (this.#pausedTimer === null) {
      this.#pausedTimer = setTimeout(() => {
        // The element outlives Turbo visits, so the link the server rendered may name an earlier page.
        this.reloadTarget.href = window.location.href;
        this.pausedTarget.hidden = false;
      }, PAUSED_AFTER_MS);
    }
  }

  #closeSource() {
    this.#source?.close();
    this.#source = null;
  }

  #refetchWhenVisible() {
    if (document.visibilityState !== 'visible') {
      this.#isSignalMissed = true;
      return;
    }
    if (this.#refetchTimer === null) {
      this.#refetchTimer = setTimeout(() => this.#refetch(), Math.random() * MAX_JITTER_MS);
    }
  }

  #refetchMissedSignal() {
    if (this.#isSignalMissed) {
      this.#refetchWhenVisible();
    }
  }

  #refetch() {
    this.#refetchTimer = null;
    if (document.visibilityState !== 'visible') {
      this.#isSignalMissed = true;
      return;
    }
    this.#isSignalMissed = false;
    const parts = [...document.querySelectorAll(REFRESHED_PARTS)];
    if (parts.length === 0) {
      visit(window.location.href, { action: 'replace' });
      return;
    }
    parts
      .filter((part) => part.localName === 'turbo-frame' && part.src && !isFrameInUse(part))
      .forEach((frame) => frame.reload());
    const pageParts = parts.filter((part) => part.localName !== 'turbo-frame' && part.id !== '');
    if (pageParts.length > 0) {
      this.#swapPageParts(pageParts);
    }
  }

  async #swapPageParts(parts) {
    let response;
    try {
      response = await fetch(window.location.href, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
    } catch {
      return;
    }
    // A session that ended redirects to the login page, and a ticket the viewer may no longer see answers 404: load
    // that page for real. Any other failure keeps the page as it is until the next signal.
    if (response.redirected || response.status === 404) {
      window.location.assign(response.url);
      return;
    }
    if (!response.ok) {
      return;
    }
    const page = new DOMParser().parseFromString(await response.text(), 'text/html');
    parts.forEach((part) => {
      const freshPart = page.getElementById(part.id);
      if (freshPart !== null && part.isConnected && !isPartInUse(part)) {
        part.replaceWith(document.importNode(freshPart, true));
      }
    });
  }
}
