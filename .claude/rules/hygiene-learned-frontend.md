---
paths:
  - "src/**/templates/**/*.twig"
  - "assets/controllers/**/*.js"
  - "assets/styles/**/*.css"
---
<!-- managed by /hygiene-learn — delete a bullet to retire that rule -->
# Learned rules (from review findings)

- A disabled button rendered as a link gets no `href` (use `role="link" aria-disabled="true"`), and hover styles apply only to enabled buttons (`:hover:not(:disabled,[aria-disabled="true"])`). <!-- class: ui-behaviour, source: 20261107-9389, 20261107-938a, 20261107-140b -->
- Close a modal `<dialog>` on a backdrop click only when the press and the release both land on the `<dialog>` element itself; a drag that starts inside the box must not close it. <!-- class: ui-behaviour, source: 20261107-7b29, 20261107-088a -->
- A menu closes on Escape and Tab from its trigger as well as from its items, because focus stays on the trigger after a mouse open. <!-- class: ui-behaviour, source: 20261107-7b2a, 20261107-088c -->
- In a Stimulus controller's `connect()`, close any dialog or menu that a page snapshot restored open, so a non-modal dialog never leaves the page behind it usable. <!-- class: ui-behaviour, source: 20261107-088b -->
