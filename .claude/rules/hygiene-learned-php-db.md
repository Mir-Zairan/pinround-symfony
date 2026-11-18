---
paths:
  - "src/**/*.php"
---
<!-- managed by /hygiene-learn — delete a bullet to retire that rule -->
# Learned rules (from review findings)

- When a database step fails and you roll back or close the connection, log a warning with only the exception class, SQLSTATE and code (never the message), as `RequestTransaction` does, and leave the connection closed so the next request or message starts with no open transaction. <!-- class: error-handling, source: 20261106-e81e, 20261106-1bea, 20261106-32e3, 20261106-fa42, 20261106-609c, 20261106-a1d8 -->
- Never call a voter, `TicketVisibilities::of` or another per-id loader inside a loop over rows; load all ids with one `= ANY(:ids)` query, or work out the viewer's facts once per request and reuse them. <!-- class: n-plus-one, source: 20261114-936f, 20261115-1da4 -->
