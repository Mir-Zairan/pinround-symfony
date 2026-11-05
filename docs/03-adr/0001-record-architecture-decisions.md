# ADR-0001 — Record architecture decisions as MADR files

We keep every important architecture decision as a short Markdown file in `docs/03-adr`.

## Status

Accepted · 2026-11-06 · Deciders: Zairan Mir (lead), Omar-role developer, Mia-role tester.

## Context and problem statement

Omar opens a new Claude Code session to build the screenshot upload. He sees that
uploads go straight to object storage and not through PHP. He wonders if that was a
mistake and starts to "fix" it. Nobody wrote down why it was done this way.

pinround is built by a team of 3 people plus many short Claude Code sessions. Each
session starts with no memory. How do we keep decisions, and the reasons for them,
easy to find and hard to undo by accident?

## Decision drivers

- Each Claude Code session must get the "why" quickly (C-team size, many short sessions).
- Decisions must be reviewable in pull requests, next to the code.
- Old decisions must stay readable after they change.
- Low effort: writing one must take less than an hour.

## Considered options

1. MADR 4.0 Markdown files in the repo (`docs/03-adr`).
2. A wiki page (Confluence or GitHub wiki).
3. No records; rely on code comments and commit messages.

## Decision outcome

Chosen option: **MADR 4.0 files in the repo**, because they live next to the code,
are reviewed in the same pull request, and Claude Code can read them with no extra access.

pinround adds two rules to MADR:

- The context starts with a short scene with real users, then the problem.
- Every ADR has a **Confirmation** section and numeric revisit triggers.

### Consequences

- Good: one place for decisions; the history is in git; reviews happen in pull requests.
- Good: CLAUDE.md and the phase files can link to exact decisions.
- Bad: someone must keep the index in [README.md](README.md) up to date.
- Neutral: wiki users must read Markdown on GitHub.

## Confirmation

- The pull request template asks: "Does this change an architecture decision? Link or add an ADR."
- A CI check (added in phase P00) fails if a file in `docs/03-adr` is not listed in the README table.
- A CI check fails if any Markdown file over 100 lines has no `## Index` near the top.

## Pros and cons of the options

### MADR files in the repo

- Good, because they are versioned and reviewed with the code.
- Good, because tools and Claude Code can read them offline.
- Bad, because non-developers find GitHub less friendly than a wiki.

### Wiki

- Good, because it is easy to edit for everyone.
- Bad, because it drifts from the code and has no review step.
- Bad, because Claude Code sessions may not have access.

### No records

- Good, because it costs nothing today.
- Bad, because reasons get lost and decisions get undone by accident.

## More information

- Template: [template.md](template.md). Index: [README.md](README.md).
- MADR: https://adr.github.io/madr/
- Revisit when: the ADR count passes 100, or more than 3 ADRs per month get superseded (a sign that we decide too early).
