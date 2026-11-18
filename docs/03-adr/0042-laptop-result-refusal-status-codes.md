# ADR-0042 — Laptop result refusals: 404 for an unknown package, 410 for a used or expired one

The laptop result route answers each refusal with its own status and records every refusal in the audit log; supersedes in part ADR-0019's 409 sentence.

## Index

- [Status](#status) — Accepted, with the date.
- [Context and problem statement](#context-and-problem-statement) — what ADR-0019 said and why it clashes.
- [Decision drivers](#decision-drivers) — no leaks, a clear answer for the browser, the repo's status rules.
- [Considered options](#considered-options) — one 409 for all, a status per refusal.
- [Decision outcome](#decision-outcome) — the status of each refusal and its consequences.
- [Confirmation](#confirmation) — the tests that pin it.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — the trade-offs.
- [More information](#more-information) — related ADRs and revisit triggers.

## Status

Accepted · 2026-11-18 · Deciders: product owner.

It supersedes in part the "Server trust rules" of [ADR-0019](0019-browser-local-ai.md): only the sentence "A wrong or used nonce returns 409 and writes an audit event". The rest of ADR-0019 (the flow, the browser rules, the other trust rules) stands.

## Context and problem statement

Omar asks for a laptop summary of SHOP-42, and his browser posts the answer twice, because he clicked twice. Mia copies Omar's package id into her own request. Lena posts a result an hour after she asked. ADR-0019 answers all three with 409. Phase 08 says a reused or expired package answers 410, and the repo answers an unknown or malformed id with 404.

Which status does each refusal of a laptop result get, so the browser can tell the person what to do and nobody learns that another person's package exists?

## Decision drivers

- Another person's or another organization's package must read exactly like one that does not exist (ADR-0005, ADR-0013).
- The browser must tell "ask again" apart from "this text is not accepted" and "you may not use laptop AI".
- The repo's rule: an unknown or malformed id answers 404, an expected domain refusal a 4xx, never a 500.
- Every refused result is evidence of a mistake or an attempt, so it is audited (ADR-0026).

## Considered options

1. One 409 for every wrong or used package (ADR-0019 as written).
2. A status per refusal: 404, 410, 422 and 403.

## Decision outcome

Chosen option: **2**, because it keeps the no-leak rule and gives the browser one clear next step per answer.

| Refusal (`LocalResultRefused` code) | Status |
|---|---|
| Unknown package, another person's or another organization's, or the poster may no longer see the ticket (`package_not_found`) | 404 |
| Package used or expired (`package_gone`) | 410 |
| Model name or text fails the check (`result_rejected`) | 422 |
| The AI policy no longer gives the poster laptop AI (`ai_off`, `leads_only`, `cloud_only`) | 403 |

Every refusal writes the audit entry `local_ai.result_refused` with data `{reason, model}`, never the text, and the package stays as it was.

### Consequences

- Good: a person cannot probe for other people's packages; the 404 is the same whether the id is unknown or someone else's.
- Good: the browser shows "ask again" on 410 and the reason on 422 without guessing.
- Bad: the audit entry is committed before the refusal is thrown, so the result route runs without the request transaction (`_request_transaction: false`).
- Neutral: 409 is not used by the laptop routes.

## Confirmation

- `tests/Integration/Assist/LocalAi/LocalAiServiceTest.php`: each refusal code, the package unchanged and one `local_ai.result_refused` entry per refusal.
- The functional tests of `POST /ai/laptop/result` (task 12b): each code answers the status in the table.

## Pros and cons of the options

### One 409 for all

- Good, because it is one rule.
- Bad, because the browser cannot tell "ask again" from "this text is refused", and it breaks the repo's 404 rule for unknown ids.

### A status per refusal

- Good, because each answer has one next step and the no-leak rule holds.
- Bad, because the route maps four codes instead of one.

## More information

- Related: [ADR-0019](0019-browser-local-ai.md), [ADR-0013](0013-authorization.md), [ADR-0026](0026-audit-log.md), [phase 08 P08.T9](../05-plan/phase-08-ai-assist.md#p08t9--laptop-ai-prompt-package-and-result-intake--stretch).
- Revisit when: a laptop client other than the browser posts results and needs to retry on a status.
