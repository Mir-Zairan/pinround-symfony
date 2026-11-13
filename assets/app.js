import './stimulus_bootstrap.js';
import * as Turbo from '@hotwired/turbo';

// Frames work with Drive off. Pages keep full loads until a change turns Drive on with an e2e sweep (ADR-0007).
Turbo.session.drive = false;
// A frame link whose response has no matching frame (login redirect, a 400, an error page) gets a real page load (a Turbo visit would swap in scripts whose CSP nonce differs and are blocked), not "Content missing".
document.addEventListener('turbo:frame-missing', (event) => {
  event.preventDefault();
  window.location.assign(event.detail.response.url);
});
// A page-level Turbo form (the ticket's move forms) expects a Turbo Stream. Any other answer (a plain 403/404/500,
// the login page after the session expired) would be swapped in as a Turbo visit whose scripts the CSP blocks, so load
// a real page instead: the redirect's target, or this page again, as the answered URL may be POST-only.
document.addEventListener('turbo:before-fetch-response', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement) || form.closest('turbo-frame') || form.hasAttribute('data-turbo-frame')) {
    return;
  }
  const { fetchResponse } = event.detail;
  if ((fetchResponse.contentType ?? '').startsWith('text/vnd.turbo-stream.html')) {
    return;
  }
  event.preventDefault();
  if (fetchResponse.redirected) {
    window.location.assign(fetchResponse.response.url);
  } else {
    window.location.reload();
  }
});
// Stylesheets are linked from the base template, not imported here: a CSS import
// becomes a data: URL in the importmap, which the CSP forbids.
