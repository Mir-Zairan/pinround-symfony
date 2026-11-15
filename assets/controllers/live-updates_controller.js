import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage, visit } from '@hotwired/turbo';

// The subscriber cookie lasts 10 min (RealtimeSubscriptions::LIFETIME_SECONDS); renewing at 8 leaves room for a slow
// answer, and every renewal checks the session, the membership and the ticket again.
const RENEW_EVERY_MS = 8 * 60 * 1000;
// ux-principles.md: after 60 s with no connection the page says updates are paused.
const PAUSED_AFTER_MS = 60 * 1000;
// Spreads the re-fetches of everyone looking at the ticket over a second instead of one spike.
const MAX_JITTER_MS = 1000;
// A stream that drops or that the hub refuses (an expired cookie answers 401) is opened again after a step of 5 s,
// doubling to at most a minute, so a signed-out tab does not ask for a cookie every few seconds. Each wait is a random
// time between half its step and the whole, so after a hub restart the tabs do not all reconnect at once.
const FIRST_REOPEN_STEP_MS = 5000;
const MAX_REOPEN_STEP_MS = 60 * 1000;
// Every tab of the browser shares one subscriber cookie, set by whichever tab asked last, and the hub reads it only as a
// stream connects. A tab holds this lock from its ask until its stream has connected, so no other tab's ask, for other
// topics, lands in between. The ask and the connect each wait at most MAX_CONNECT_WAIT_MS, so the lock is held at most
// twice that, and a token route or a hub that never answers stops no other tab.
const COOKIE_LOCK = 'pinround-live-updates-cookie';
const MAX_CONNECT_WAIT_MS = 5000;
// RefreshTicketPagesHandler::SIGNAL_TYPE. An update sent with no type comes as a 'message' and carries Turbo Streams
// (RefreshBellHandler), rendered as they come, hidden tab or not.
const SIGNAL_TYPE = 'refresh';
const STREAM_MESSAGE_TYPE = 'message';
// Marks what a signal fetches again: a turbo-frame with a src reloads; any other element with an id is swapped for the
// element of the same id in the page fetched again.
const REFRESHED_PARTS = '[data-live-refresh]';
// Every fetch a signal sends carries it, so base.html.twig leaves the session's toast for the page the person's own
// action redirects to: a signal of that action can come between its redirect and the redirected page's fetch.
const LIVE_REFRESH_HEADER = 'X-Live-Refresh';
const FORM_FIELDS = 'input, textarea, select';
// An open dialog, or an open disclosure with text to type in (a message's edit form), keeps what the person wrote
// there after they click away; a refresh would close it and lose the text.
const OPEN_EDITORS = 'dialog[open], details[open] :is(textarea, input[type="text"])';
// A box whose Cancel folds it away and keeps the text (the thread's Answer and Reply boxes) is still in use while that
// text is changed, open or not.
const KEPT_TEXT = '[data-live-keeps-text] :is(textarea, input[type="text"])';

// Events after which a held part or visit may be free again: focus moved, a disclosure opened or closed, a dialog closed. None
// of toggle and close bubbles, so they are heard in the capture phase.
const HOLD_ENDING_EVENTS = ['focusin', 'focusout', 'toggle', 'close'];

// A part with an open editor, with a field being typed in, or with a folded box that promises to keep its text, is held
// rather than lose the text: a swapped part loses both, a reloaded frame keeps only what sits in a data-turbo-permanent
// element. A focused button or link holds nothing: its copy in the fresh part takes the focus back.
function isPartInUse(part) {
  const focused = document.activeElement;
  if (part.querySelector(OPEN_EDITORS) !== null || [...part.querySelectorAll(KEPT_TEXT)].some(isFieldChanged)) {
    return true;
  }
  if (focused === null || !part.contains(focused) || !focused.matches(FORM_FIELDS)) {
    return false;
  }
  return part.localName !== 'turbo-frame' || focused.closest('[data-turbo-permanent]') === null;
}

// A select-one with no option marked selected shows its first, so that is its default too.
function isSelectChanged(select) {
  const options = [...select.options];
  if (select.multiple) {
    return options.some((option) => option.selected !== option.defaultSelected);
  }
  const defaultIndex = options.findLastIndex((option) => option.defaultSelected);
  return select.selectedIndex !== (defaultIndex === -1 && select.size <= 1 ? 0 : defaultIndex);
}

function isFieldChanged(field) {
  if (field.localName === 'select') {
    return isSelectChanged(field);
  }
  if (field.type === 'checkbox' || field.type === 'radio') {
    return field.checked !== field.defaultChecked;
  }
  return field.value !== field.defaultValue;
}

// A whole-page visit would lose any field the person changed and close any open editor, wherever it sits on the page.
function isPageInUse() {
  return document.querySelector(OPEN_EDITORS) !== null || [...document.querySelectorAll(FORM_FIELDS)].some(isFieldChanged);
}

// Web Locks exist only in a secure context (HTTPS or localhost); without them a tab asks and connects unguarded.
function whileHoldingCookieLock(work) {
  return navigator.locks === undefined ? work() : navigator.locks.request(COOKIE_LOCK, work);
}

// Turbo's own fetches take their headers from turbo:before-fetch-request; only a GET is a refresh, as a form in a
// reloading frame may be sent meanwhile.
function markLiveRefresh(event) {
  if (event.detail.fetchOptions.method.toUpperCase() === 'GET') {
    event.detail.fetchOptions.headers[LIVE_REFRESH_HEADER] = '1';
  }
}

// Going to the page's own URL with a hash only scrolls to the anchor (as after "Skip to main content" left #main in
// it); without the hash the page loads for real.
function pageUrlWithoutHash() {
  const url = new URL(window.location.href);
  url.hash = '';
  return url.href;
}

function connectionSettled(source) {
  return new Promise((resolve) => {
    const settle = () => {
      clearTimeout(timer);
      source.removeEventListener('open', settle);
      source.removeEventListener('error', settle);
      resolve();
    };
    const timer = setTimeout(settle, MAX_CONNECT_WAIT_MS);
    source.addEventListener('open', settle);
    source.addEventListener('error', settle);
  });
}

function labelOf(control) {
  return control.getAttribute('aria-label') ?? control.textContent.replace(/\s+/g, ' ').trim();
}

function controlsLike(anchor, localName, label) {
  return [...anchor.getElementsByTagName(localName)].filter((control) => labelOf(control) === label);
}

// How to find the focused control of a part again once the part is replaced (WCAG 2.4.3): by the nearest id around it
// and its place among the controls there with the same tag and label, as most controls have no id of their own. Null
// when the part has no focus.
function focusedControlOf(part) {
  const focused = document.activeElement;
  if (focused === null || focused === part || !part.contains(focused)) {
    return null;
  }
  const anchor = focused.parentElement.closest('[id]');
  const label = labelOf(focused);
  const index = controlsLike(anchor, focused.localName, label).indexOf(focused);
  return { anchorId: anchor.id, localName: focused.localName, label, index, tabIndex: focused.getAttribute('tabindex') };
}

// Replacing a part drops the focus it held to the body; this gives it to the same control in the fresh part, unless
// focus has meanwhile moved on to something that is still on the page.
function refocus(focusedControl, part) {
  const focused = document.activeElement;
  if (focusedControl === null || (focused !== null && focused !== document.body)) {
    return;
  }
  const anchor = document.getElementById(focusedControl.anchorId);
  const control = anchor === null ? null : (controlsLike(anchor, focusedControl.localName, focusedControl.label)[focusedControl.index] ?? null);
  if (control === null || !part.contains(control)) {
    return;
  }
  // A heading or message that got focus after an action (tabindex="-1", from a script or the redirected page's
  // autofocus) has no tabindex in the fresh copy, and focus() on it would do nothing.
  if (focusedControl.tabIndex !== null && !control.hasAttribute('tabindex')) {
    control.setAttribute('tabindex', focusedControl.tabIndex);
  }
  control.focus({ preventScroll: true });
}

// Follows the live updates of a page (realtime-and-notifications.md): asks POST /realtime/token for the subscriber
// cookie first, as the page sets none, then opens the hub's stream. A refresh signal carries no content, so on one the
// page fetches again, with the viewer's own session, what carries data-live-refresh, or else the whole page through
// Turbo. Signals that come while the tab is hidden are remembered and fetched once when it is shown again; a part held
// because it is in use is fetched once the hold ends.
export default class extends Controller {
  static targets = ['paused', 'reload'];
  static values = { subscribeUrl: String, tokenUrl: String, ticketIds: Array };

  #source = null;
  #renewTimer = null;
  #pausedTimer = null;
  #reopenTimer = null;
  #refetchTimer = null;
  #stopTimer = null;
  #reopenStepMs = FIRST_REOPEN_STEP_MS;
  #releaseTimer = null;
  #isSignalMissed = false;
  #hasConnected = false;
  // Ids of the parts a signal skipped because they were in use.
  #heldPartIds = new Set();
  // A page with no parts that a signal would have visited again while it was in use.
  #isVisitHeld = false;
  #onSignal = () => this.#refetchWhenVisible();
  #onStreamMessage = (event) => renderStreamMessage(event.data);
  #onVisibilityChange = () => this.#refetchMissedSignal();
  #onHoldMayEnd = () => this.#refreshReleasedPartsSoon();

  connect() {
    // The element is data-turbo-permanent: a Turbo visit moves it into the new page, which Stimulus sees as a
    // disconnect and a connect of the same controller. Keeping the stream then loses no signal and asks for no cookie.
    if (this.#stopTimer !== null) {
      clearTimeout(this.#stopTimer);
      this.#stopTimer = null;
      return;
    }
    document.addEventListener('visibilitychange', this.#onVisibilityChange);
    HOLD_ENDING_EVENTS.forEach((type) => document.addEventListener(type, this.#onHoldMayEnd, true));
    this.#renewTimer = setInterval(() => whileHoldingCookieLock(() => this.#renewCookie()), RENEW_EVERY_MS);
    this.#open();
  }

  disconnect() {
    // The page around the element is new, so what was held belonged to the page before.
    this.#heldPartIds.clear();
    this.#isVisitHeld = false;
    this.#stopTimer = setTimeout(() => this.#stop(), 0);
  }

  #stop() {
    this.#stopTimer = null;
    document.removeEventListener('visibilitychange', this.#onVisibilityChange);
    HOLD_ENDING_EVENTS.forEach((type) => document.removeEventListener(type, this.#onHoldMayEnd, true));
    clearInterval(this.#renewTimer);
    clearTimeout(this.#pausedTimer);
    clearTimeout(this.#reopenTimer);
    clearTimeout(this.#refetchTimer);
    clearTimeout(this.#releaseTimer);
    this.#pausedTimer = null;
    this.#refetchTimer = null;
    this.#releaseTimer = null;
    this.#closeSource();
  }

  async #open() {
    this.#startPausedTimer();
    await whileHoldingCookieLock(async () => {
      await this.#renewCookie();
      if (!this.element.isConnected) {
        return;
      }
      this.#closeSource();
      const source = new EventSource(this.subscribeUrlValue, { withCredentials: true });
      this.#source = source;
      source.addEventListener(SIGNAL_TYPE, this.#onSignal);
      source.addEventListener(STREAM_MESSAGE_TYPE, this.#onStreamMessage);
      source.addEventListener('open', () => this.#showConnected());
      source.addEventListener('error', () => this.#reopenLater());
      await connectionSettled(source);
    });
  }

  async #renewCookie() {
    const body = new FormData();
    this.ticketIdsValue.forEach((ticketId) => body.append('ticketIds[]', ticketId));
    try {
      await fetch(this.tokenUrlValue, { method: 'POST', body, credentials: 'same-origin', signal: AbortSignal.timeout(MAX_CONNECT_WAIT_MS) });
    } catch {
      // Offline, or no answer in time: the stream fails to open or drops, and the paused banner says so after 60 s.
    }
  }

  // A reopened stream is a new EventSource that asks the hub for nothing it sent meanwhile, so a signal sent while the
  // stream was down is lost: the first open of every stream after the first fetches the page's parts again once.
  #showConnected() {
    clearTimeout(this.#pausedTimer);
    this.#pausedTimer = null;
    this.#reopenStepMs = FIRST_REOPEN_STEP_MS;
    this.pausedTarget.hidden = true;
    if (this.#hasConnected) {
      this.#refetchWhenVisible();
    }
    this.#hasConnected = true;
  }

  // The browser would reconnect a dropped stream by itself, with whatever cookie another tab asked for last, which may
  // not hold this page's ticket; closing it and opening it through #open asks for this page's cookie first.
  #reopenLater() {
    this.#startPausedTimer();
    this.#closeSource();
    clearTimeout(this.#reopenTimer);
    this.#reopenTimer = setTimeout(() => this.#open(), (this.#reopenStepMs / 2) * (1 + Math.random()));
    this.#reopenStepMs = Math.min(this.#reopenStepMs * 2, MAX_REOPEN_STEP_MS);
  }

  #startPausedTimer() {
    if (this.#pausedTimer === null) {
      this.#pausedTimer = setTimeout(() => {
        // The element outlives Turbo visits, so the link the server rendered may name an earlier page.
        this.reloadTarget.href = pageUrlWithoutHash();
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
      this.#visitPageUnlessInUse();
      return;
    }
    this.#refreshParts(parts);
  }

  #visitPageUnlessInUse() {
    this.#isVisitHeld = isPageInUse();
    if (this.#isVisitHeld) {
      return;
    }
    // Turbo sends the visit's fetch within visit(), so the listener marks that fetch and no later one.
    document.addEventListener('turbo:before-fetch-request', markLiveRefresh);
    try {
      visit(window.location.href, { action: 'replace' });
    } finally {
      document.removeEventListener('turbo:before-fetch-request', markLiveRefresh);
    }
  }

  // A held part's id stays in #heldPartIds until a refresh finds it free; a frame reloads, any other part is swapped.
  #refreshParts(parts) {
    const freeParts = parts.filter((part) => {
      if (isPartInUse(part)) {
        this.#heldPartIds.add(part.id);
        return false;
      }
      this.#heldPartIds.delete(part.id);
      return true;
    });
    freeParts.filter((part) => part.localName === 'turbo-frame' && part.src).forEach((frame) => this.#reloadFrame(frame));
    const pageParts = freeParts.filter((part) => part.localName !== 'turbo-frame' && part.id !== '');
    if (pageParts.length > 0) {
      this.#swapPageParts(pageParts);
    }
  }

  // After the event that may end a hold, focus has moved on and a closed dialog or disclosure reads as closed.
  #refreshReleasedPartsSoon() {
    if ((this.#heldPartIds.size > 0 || this.#isVisitHeld) && this.#releaseTimer === null) {
      this.#releaseTimer = setTimeout(() => this.#refreshReleasedParts(), 0);
    }
  }

  #refreshReleasedParts() {
    this.#releaseTimer = null;
    if (this.#isVisitHeld) {
      this.#visitPageUnlessInUse();
      return;
    }
    const heldParts = [...this.#heldPartIds].map((id) => document.getElementById(id));
    this.#heldPartIds = new Set(heldParts.filter((part) => part !== null && isPartInUse(part)).map((part) => part.id));
    const releasedParts = heldParts.filter((part) => part !== null && !this.#heldPartIds.has(part.id));
    if (releasedParts.length > 0) {
      this.#refreshParts(releasedParts);
    }
  }

  async #swapPageParts(parts) {
    let response;
    try {
      response = await fetch(window.location.href, {
        headers: { Accept: 'text/html', [LIVE_REFRESH_HEADER]: '1' },
        credentials: 'same-origin',
      });
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
      if (freshPart === null || !part.isConnected) {
        return;
      }
      // Put in use while the page was fetched.
      if (isPartInUse(part)) {
        this.#heldPartIds.add(part.id);
        return;
      }
      const focusedControl = focusedControlOf(part);
      const importedPart = document.importNode(freshPart, true);
      part.replaceWith(importedPart);
      refocus(focusedControl, importedPart);
    });
  }

  // The focused control is noted as the frame renders, not as it is asked for, as focus may move while it loads.
  // Turbo's reload settles once its answer is rendered or its fetch fails. A frame answer of 404 loads this page for
  // real, as #swapPageParts does, not the frame's own URL that app.js's turbo:frame-missing loads: whichever of the two
  // lands last would decide the page the tab ends on.
  async #reloadFrame(frame) {
    let focusedControl = null;
    const noteFocusedControl = () => {
      focusedControl = focusedControlOf(frame);
    };
    const loadPageOnNotFound = (event) => {
      const { response } = event.detail;
      if (response.status === 404 && !response.redirected) {
        event.preventDefault();
        event.stopPropagation();
        window.location.assign(pageUrlWithoutHash());
      }
    };
    frame.addEventListener('turbo:before-frame-render', noteFocusedControl);
    frame.addEventListener('turbo:before-fetch-request', markLiveRefresh);
    frame.addEventListener('turbo:frame-missing', loadPageOnNotFound);
    try {
      await frame.reload();
    } finally {
      frame.removeEventListener('turbo:before-frame-render', noteFocusedControl);
      frame.removeEventListener('turbo:before-fetch-request', markLiveRefresh);
      frame.removeEventListener('turbo:frame-missing', loadPageOnNotFound);
    }
    refocus(focusedControl, frame);
  }
}
