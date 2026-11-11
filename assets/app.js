import './stimulus_bootstrap.js';
import * as Turbo from '@hotwired/turbo';

// Frames work with Drive off. Pages keep full loads until a change turns Drive on with an e2e sweep (ADR-0007).
Turbo.session.drive = false;
// A frame link whose response has no matching frame (login redirect, a 400, an error page) gets a real page load (a Turbo visit would swap in scripts whose CSP nonce differs and are blocked), not "Content missing".
document.addEventListener('turbo:frame-missing', (event) => {
  event.preventDefault();
  window.location.assign(event.detail.response.url);
});
// Stylesheets are linked from the base template, not imported here: a CSS import
// becomes a data: URL in the importmap, which the CSP forbids.
