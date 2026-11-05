# ADR-NNNN — Short title in present tense ("Use X for Y")

Copy this file to `NNNN-short-slug.md`, fill every section, delete the hints in *italics*.

## Index

- [Status](#status) — Proposed, Accepted, Deprecated or Superseded, with the date.
- [Context and problem statement](#context-and-problem-statement) — a short scene, then the problem in plain words.
- [Decision drivers](#decision-drivers) — the forces that matter, linked to NFR and constraint ids.
- [Considered options](#considered-options) — the real options we looked at.
- [Decision outcome](#decision-outcome) — the chosen option, why, and its consequences.
- [Confirmation](#confirmation) — how we check that the code follows this decision.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — the trade-off analysis for each option.
- [More information](#more-information) — links, related ADRs, revisit triggers.

## Status

*Proposed | Accepted | Deprecated | Superseded by ADR-XXXX (link to the new ADR file)*

- Date: *YYYY-MM-DD*
- Deciders: *names or roles*
- Consulted: *optional*

## Context and problem statement

*Start with a scene of 2–5 sentences that shows the problem through real users
(Lena the lead, Omar the developer, Mia the tester, Raj the manager, Sara the admin).
Then state the problem in one or two sentences, ideally as a question.*

## Decision drivers

*Bullet list. Link each driver to a requirement id where possible
(for example NFR-SEC-03, NFR-PERF-01, C-02).*

- *Driver 1*
- *Driver 2*

## Considered options

1. *Option A*
2. *Option B*
3. *Option C*

## Decision outcome

Chosen option: **"Option A"**, because *one or two sentences that tie the choice to the drivers*.

### Consequences

- Good: *what gets easier or safer.*
- Bad: *what gets harder, what it costs, what we give up.*
- Neutral: *what changes but is neither good nor bad.*

## Confirmation

*How we check, again and again, that the code follows this decision.
Prefer automatic checks: a test suite, a Deptrac rule, a PHPStan rule, a CI job,
a database permission. Name the check so a reader can find it.*

## Pros and cons of the options

### Option A

- Good, because *…*
- Bad, because *…*

### Option B

- Good, because *…*
- Bad, because *…*

### Option C

- Good, because *…*
- Bad, because *…*

## More information

- Related: *ADR links, architecture docs, requirement ids.*
- Revisit when: *a measurable trigger, for example "p95 search time is above 300 ms for 7 days".*
