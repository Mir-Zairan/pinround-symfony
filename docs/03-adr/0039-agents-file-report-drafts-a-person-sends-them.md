# ADR-0039 — Agents file report drafts; a person sends them

A person's Claude Code may prepare a report, but only a person turns it into a live ticket.

## Index

- [Status](#status) — accepted, date, deciders.
- [Context and problem statement](#context-and-problem-statement) — a reporter's agent reproduces an issue.
- [Decision drivers](#decision-drivers) — privacy, human control, one upload path.
- [Considered options](#considered-options) — live ticket, report draft, no tool.
- [Decision outcome](#decision-outcome) — report draft, owner-only, scope `reports:write`.
- [Confirmation](#confirmation) — the contract and visibility tests.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — the trade-offs.
- [More information](#more-information) — related ADRs and requirements.

## Status

Accepted · 2026-11-14 · Deciders: product owner.

It adds to [ADR-0017](0017-private-draft-rounds.md) and [ADR-0020](0020-ai-safety-and-prompt-injection.md); their
decisions stand. It supersedes only the scope list and tool list of [ADR-0021](0021-mcp-server.md): the scope
`reports:write` and the tool `create_report` are added, and the rule below also covers the REST API. The rest of
ADR-0021 stands.

Superseded in part by [ADR-0040](0040-report-drafts-live-in-review.md): where the report drafts UI lives and how Send
runs, the `ReportDrafts` signatures, and how a draft stores its screenshots (the "Owning module", Api and Send bullets
below). Everything else here still stands.

Superseded in part by [ADR-0047](0047-a-sent-report-draft-is-a-report-and-its-pictures-upload-by-project.md): the
upload line ("Screenshots use the same upload path: `create_upload` …"). A report's pictures are begun with the tool
`create_report_upload` (scope `reports:write`) on the upload target kind `new_report`, keyed by the project.

## Context and problem statement

Mia, a tester at Acme Foods, gets a customer complaint about project SHOP. She asks her own Claude Code to reproduce
it. Claude Code opens the app, takes three screenshots, and could write the report for her: a title, a short text,
and for each picture a title and a summary ("Case B: a new delivery date leaves the invoice total alone"). Mia wants to look
at it before the team sees it. Raj, a manager, does not want agents to fill the team's board with tickets nobody
checked.

Can an agent file a report for a person, and if so, who decides that it becomes a ticket?

## Decision drivers

- AI never writes on its own; a person clicks ([ADR-0020](0020-ai-safety-and-prompt-injection.md)).
- A private draft is visible to its owner only ([ADR-0017](0017-private-draft-rounds.md), [FR-RND-10](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds)).
- A token never has more rights than its user, and a new write needs its own scope ([ADR-0021](0021-mcp-server.md)).
- One upload path for web, extension and MCP ([ADR-0011](0011-screenshot-storage-and-processing.md)).

## Considered options

1. A `create_report` tool that creates a live ticket with Round 0.
2. A `create_report` tool that creates a report draft; the owner edits it and clicks Send in the app.
3. No tool: the reporter copies the agent's text and pictures into the form by hand.

## Decision outcome

Chosen option: **2, report draft**, because it keeps the person in charge of what becomes a ticket while saving the
typing.

- New MCP tool `create_report` {project, title, detail, screenshots[{upload_id, title?, summary?, pins?[]}]}
  ([FR-MCP-12](../01-requirements/functional-requirements.md#mcp--mcp-server-and-agent-api)).
- It creates a **report draft** that only its owner can see; the ADR-0017 privacy rules apply (not admins, not the lead,
  not the team). Other users get "not found".
- A token can never create a live ticket, through MCP or through the REST API `/api/v1`. No token endpoint creates a
  ticket or a round on a ticket from a report; the REST endpoint `POST /api/v1/reports` is the same report draft as
  `create_report` (same scope, same caps, same privacy). The owner opens the draft in "Report drafts", can edit it, and clicks Send;
  Send runs the same path as the Report a problem form and calls `RoundCommands::createReport` (Round 0).
- **Owning module: Review (`rvw_`).** The draft holds screenshots with title, summary and pins, which Review owns, and
  Send hands the same content to `RoundCommands::createReport`. It is stored in the new table `rvw_report_draft`
  ([data-model.md](../02-architecture/data-model.md#review-and-media)): tenant table, `organization_id` first in the
  primary key and in every index, row-level security on. Api: `Review\Api\ReportDrafts::create(OrganizationId, UserId $owner, ProjectId, ReportDraftContent): ReportDraftId`
  (called by `create_report` and `POST /api/v1/reports`), `ReportDrafts::forOwner(UserId): list<ReportDraftView>` and
  `ReportDrafts::send(ReportDraftId, UserId): TicketKey` (only the UI calls `send`; the project is checked through the
  Ticketing Api).
- New token scope `reports:write`. It is not part of Claude Code's default scopes (`read` + `rounds:write`); the user
  ticks it when creating the token. A call without it gets an MCP error and nothing is created.
- Screenshots use the same upload path: `create_upload`, presigned PUT, then the tool takes the `upload_id`.
- Same rate limits and audit as every tool: token id, tool, target id, result, no content.
- Title and summary are untrusted user text: wrapped in delimiters when sent to AI or returned by MCP. Pinround AI may
  suggest a summary ("Use this" / "Dismiss") but never saves it alone.

### Consequences

- Good: the agent does the typing; a person decides what becomes a ticket.
- Good: no new storage path; reuses the draft privacy and the upload rules.
- Bad: one more list for the reporter ("Report drafts") and one more state to clean up (old report drafts need a
  retention rule, set in P06).
- Neutral: one more scope on the token page.

## Confirmation

- MCP contract tests for `create_report`: other organization's project → not found, missing `reports:write` → error,
  invalid input → error, no ticket row exists after the call. The same for `POST /api/v1/reports`.
- Visibility tests: the report draft is invisible to the lead, an admin and another user (HTML, queries, direct URL).
- A functional test that Send, as a person in the UI, creates the ticket with Round 0, and that neither the tool nor
  a REST call can do it.
- A test that `rvw_report_draft` has RLS on (`CatalogTest`) and that `ReportDrafts::send` refuses a non-owner.

## Pros and cons of the options

### Option 1, live ticket

- Good, because it is one step.
- Bad, because an agent could file unchecked tickets and a stolen token could spam the board.

### Option 2, report draft

- Good, because a person always checks and sends.
- Bad, because it adds a list and a retention rule.

### Option 3, no tool

- Good, because nothing new to build.
- Bad, because the reporter retypes what the agent already has.

## More information

- Related: [ADR-0017](0017-private-draft-rounds.md), [ADR-0020](0020-ai-safety-and-prompt-injection.md),
  [ADR-0021](0021-mcp-server.md), [FR-RND-16](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds),
  [FR-MCP-12](../01-requirements/functional-requirements.md#mcp--mcp-server-and-agent-api).
- Revisit when: reporters ask to let a trusted agent send without a click (then a new ADR must weigh ADR-0020).
