---
paths:
  - "docs/**/*.md"
  - "README.md"
---
<!-- managed by /hygiene-learn — delete a bullet to retire that rule -->
# Learned rules (from review findings)

- When code changes behaviour that a doc, ADR, README status line or phase hand-off note describes, update that text in the same commit. <!-- class: docs, source: 20261106-7562, 20261106-0d44, 20261106-b4e9, 20261106-d987 -->
- A doc or ADR that names a check must state exactly what the script enforces (same values, same names). <!-- class: docs, source: 20261106-b4e7, 20261106-d988 -->
- A decision that lives only in a code comment (why we build from source, why a setting was renamed) gets an ADR note or a dated hand-off line. <!-- class: docs, source: 20261106-f3ba, 20261106-7562 -->
- Before ticking a phase criterion, make its wording, the task's Files line and its steps match what was built and what the test proves. <!-- class: docs, source: 20261107-21a5, 20261106-609e, 20261107-231e, 20261107-1e8f -->
- Never reword a phase criterion so it can be ticked, and never tick it when the only proof is a fake; leave it open and add a dated hand-off line that says which proof is still owed. <!-- class: framing, source: 20261108-bdc5, 20261115-28e1 -->
