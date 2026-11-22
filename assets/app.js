import './stimulus_bootstrap.js';
import * as Turbo from '@hotwired/turbo';
import { showToast } from './toast_region.js';

// A toast a page leaves for the next page load, which shows it once in the toast region.
const PENDING_TOAST_KEY = 'pinround.pendingToast';

// sessionStorage throws when the browser blocks storage; the toast is then lost, never the reload.
function queuePendingToast(message) {
  try {
    window.sessionStorage.setItem(PENDING_TOAST_KEY, message);
  } catch {}
}

function takePendingToast() {
  try {
    const message = window.sessionStorage.getItem(PENDING_TOAST_KEY);
    window.sessionStorage.removeItem(PENDING_TOAST_KEY);
    return message;
  } catch {
    return null;
  }
}

showToast(takePendingToast());

// Frames work with Drive off. Pages keep full loads until a change turns Drive on with an e2e sweep (ADR-0007).
Turbo.session.drive = false;
// A frame link whose response has no matching frame (login redirect, a 400, an error page) gets a real page load (a Turbo visit would swap in scripts whose CSP nonce differs and are blocked), not "Content missing".
document.addEventListener('turbo:frame-missing', (event) => {
  event.preventDefault();
  window.location.assign(event.detail.response.url);
});
// A form whose answer is a Turbo Stream: a page-level Turbo form (the ticket's move forms), or one inside a frame that
// posts to _top and says so with data-failed-toast (the checklist's). The other _top forms in a frame (the draft
// round's, the queue's) answer with a redirect whose page shows the flashed toast, which only Turbo's own visit keeps,
// and a form that targets a frame is that frame's to render.
function expectsTurboStream(form) {
  const frameTarget = form.getAttribute('data-turbo-frame');
  if (frameTarget === '_top') {
    return form.hasAttribute('data-failed-toast');
  }
  return frameTarget === null && !form.closest('turbo-frame');
}
// The fetch that followed the redirect already took the flashed toast out of the session, so the real load of the same
// page would show none: the toast the fetched page holds is left for that load instead (as "Make it a new ticket" on
// the draft page does). DOMParser runs none of the page's scripts.
async function carryToastThenLoad(fetchResponse) {
  try {
    const html = (await fetchResponse.responseHTML) ?? '';
    const toast = new DOMParser().parseFromString(html, 'text/html').querySelector('#toast-region [data-toast-message-value]');
    if (toast instanceof HTMLElement && toast.dataset.toastMessageValue) {
      queuePendingToast(toast.dataset.toastMessageValue);
    }
  } finally {
    window.location.assign(fetchResponse.response.url);
  }
}
// Any answer to such a form but a stream (a plain 403/404/500, the login page after the session expired) would be
// swapped in as a Turbo visit whose scripts the CSP blocks, so load a real page instead: the redirect's target, or this
// page again, as the answered URL may be POST-only. Before that reload, a form with data-failed-toast leaves its text
// as the next page's toast; a form without one reloads silently.
document.addEventListener('turbo:before-fetch-response', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement) || !expectsTurboStream(form)) {
    return;
  }
  const { fetchResponse } = event.detail;
  if ((fetchResponse.contentType ?? '').startsWith('text/vnd.turbo-stream.html')) {
    return;
  }
  event.preventDefault();
  if (fetchResponse.redirected) {
    carryToastThenLoad(fetchResponse);
  } else {
    const failedToast = form.dataset.failedToast;
    if (failedToast) {
      queuePendingToast(failedToast);
    }
    window.location.reload();
  }
});
// Stylesheets are linked from the base template, not imported here: a CSS import
// becomes a data: URL in the importmap, which the CSP forbids.
