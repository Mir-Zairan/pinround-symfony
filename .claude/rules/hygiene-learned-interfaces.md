---
paths:
  - "src/**/*.php"
---
<!-- managed by /hygiene-learn — delete a bullet to retire that rule -->
# Learned rules (from review findings)

- When a method on an `Api` or port interface throws, reloads state or drops unsaved changes, say so in its docblock (for example: "Reloads admin memberships; unsaved changes are lost."), so callers know before they call it. <!-- class: api-contract, source: ledger, 2026-11-09 -->
