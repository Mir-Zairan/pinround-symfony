# ADR-0040 — Report drafts live in Review: its UI lists, edits and sends them

Review owns the report drafts list, the edit page and Send; Send creates the ticket through Ticketing's Api, so Ticketing never calls Review.

## Index

- [Status](#status) — accepted, date, deciders, the ADR-0039 parts it supersedes.
- [Context and problem statement](#context-and-problem-statement) — Send needs both Ticketing and Review.
- [Decision drivers](#decision-drivers) — module edges, small rows, plain contracts.
- [Considered options](#considered-options) — Review UI with jsonb, Review UI with a table, Ticketing's `ReportForm`.
- [Decision outcome](#decision-outcome) — Review's UI, string signatures, one jsonb array of at most 10.
- [Confirmation](#confirmation) — Deptrac, the cap and the Send tests.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — the trade-offs.
- [More information](#more-information) — related ADRs and docs.

## Status

Accepted · 2026-11-15 · Deciders: product owner.

Supersedes in part [ADR-0039](0039-agents-file-report-drafts-a-person-sends-them.md): where the UI for report drafts
lives and how Send runs (it ran "the same path as the Report a problem form"), the `ReportDrafts` signatures, and how a
draft stores its screenshots. Everything else in ADR-0039 stands: the `create_report` tool, the scope `reports:write`,
owner-only privacy and the rule that no token creates a ticket.

## Context and problem statement

Mia's Claude Code has filed a report draft for project SHOP with three screenshots. Mia opens it, fixes a title and
clicks Send. Send must create a ticket (Ticketing) and Round 0 with the screenshots and pins (Review). ADR-0039 left
open which module's UI shows the list and runs Send. If Ticketing's `ReportForm` ran Send, Ticketing's UI and
Application would call Review's Api. Review already depends on Ticketing's Api ([modules.md](../02-architecture/modules.md#review-rvw_)),
so that edge would make a Ticketing-Review cycle, and Deptrac forbids it.

Which module owns the drafts UI and Send, what do the `ReportDrafts` signatures carry, and how does a draft store its screenshots?

## Decision drivers

- Ticketing must not call Review's Api; Review may call Ticketing's Api ([ADR-0032](0032-module-call-edges-follow-modules-catalogue.md)).
- Api signatures carry plain values and SharedKernel ids, never another module's types.
- A draft row stays small and is read whole, owner only, by the "Report drafts" list.

## Considered options

1. Review's UI owns the list, the edit page and Send; the draft keeps its screenshots as one jsonb array, at most 10.
2. Review's UI as in option 1, but screenshots in their own table `rvw_report_draft_screenshot`.
3. Keep the list, edit and Send in Ticketing's `ReportForm`.

## Decision outcome

Chosen option: **1, Review's UI with one jsonb array**, because it needs no forbidden edge and the least code.

- **The UI is Review's.** The "Report drafts" list, the page to open and edit a draft, and Send are in `src/Review/UI`,
  not in Ticketing's `ReportForm`. The Report a problem page only links to the list.
- **Signatures use strings and SharedKernel ids.** `Review\Api\ReportDrafts::create(OrganizationId, UserId $owner, string $projectKey, ReportDraftContent): ReportDraftId`,
  `ReportDrafts::forOwner(UserId): list<ReportDraftView>` and `ReportDrafts::send(ReportDraftId, UserId): string`
  (owner only; returns the new ticket key, for example `SHOP-12`; only the UI calls `send`).
- **Send** turns the draft's project key into an id with `Ticketing\Api\ProjectQueries::projectByKey`, then calls
  `Ticketing\Api\TicketCommands::create` with that project id and the draft's title, detail, type, priority, assignee
  and reported-at time. Ticketing checks the project and the assignee. Send then creates Round 0 through Review's own
  `RoundCommands::createReport` and deletes the draft, all in one transaction. A key that matches no project refuses Send
  with a plain message and keeps the draft.
- **Screenshots are one jsonb array** in `rvw_report_draft` (each item: media id, title, summary, pins), at most 10 per
  draft. More is refused with a plain message and nothing is saved. A round keeps its limit of 50.

### Consequences

- Good: Ticketing never calls Review's Api; no cycle.
- Good: one table, one read for the list and the edit page.
- Bad: Review's UI creates a ticket, so it depends on Ticketing's Api; Deptrac guards the edge.
- Neutral: a draft holds at most 10 screenshots, fewer than a round's 50, to keep the jsonb row small.

## Confirmation

- Deptrac: `Ticketing_UI` and `Ticketing_Application` may not see `Review_Api`; `Review_UI` may see `Ticketing_Api`.
- A test that the 11th screenshot is refused with the plain message and the stored draft is unchanged; the
  `CHECK (jsonb_array_length(screenshots) <= 10)` holds on `rvw_report_draft`.
- A functional test that Send as the owner creates the ticket and Round 0 and deletes the draft; for another user
  the draft is "not found" and nothing is created; for an unknown project key Send is refused and the draft stays.

## Pros and cons of the options

### Option 1, Review's UI and one jsonb array

- Good, because the module edges stay legal and the code is small.
- Bad, because the screenshots cannot be joined or indexed one by one (not needed: the draft is read whole).

### Option 2, Review's UI and a table `rvw_report_draft_screenshot`

- Good, because each screenshot is a row.
- Bad, because it is about 150 more lines (entity, repository, migration, tests) and a join on every read.

### Option 3, Ticketing's `ReportForm`

- Good, because the Report a problem form and the draft edit would share one component.
- Bad, because Send would call Review's Api, a forbidden edge and a Ticketing-Review cycle.

## More information

- Related: [ADR-0039](0039-agents-file-report-drafts-a-person-sends-them.md), [ADR-0002](0002-modular-monolith.md),
  [ADR-0032](0032-module-call-edges-follow-modules-catalogue.md), [data-model.md](../02-architecture/data-model.md#review-and-media),
  [modules.md](../02-architecture/modules.md#review-rvw_).
- Revisit when: drafts need more than 10 screenshots or per-screenshot queries (then option 2).
