# ADR-0047 — A sent report draft is a report, and its pictures upload by project

Send makes the ticket through `TicketCommands::report`, and an agent begins a report draft's pictures with `create_report_upload` on the upload target kind `new_report`.

## Index

- [Status](#status) — accepted, date, deciders, the ADR-0039 and ADR-0040 parts it supersedes.
- [Context and problem statement](#context-and-problem-statement) — a sent draft skipped the checks, and its pictures had no target.
- [Decision drivers](#decision-drivers) — one intake path, one scope per tool, no ticket before Send.
- [Considered options](#considered-options) — the command Send calls, and how a new report's pictures begin.
- [Decision outcome](#decision-outcome) — `report` for Send; `create_report_upload` and `new_report` for pictures.
- [Confirmation](#confirmation) — the tests that pin both decisions.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — the trade-offs.
- [More information](#more-information) — related ADRs, docs and revisit triggers.

## Status

Accepted · 2026-11-19 · Deciders: product owner.

Supersedes in part:

- [ADR-0040](0040-report-drafts-live-in-review.md): the **Send** bullet's command. Send calls
  `Ticketing\Api\TicketCommands::report`, not `TicketCommands::create`. The rest of that bullet stands: the project key
  becomes a project through `ProjectQueries::projectByKey`, Round 0 comes from `RoundCommands::createReport`, the draft
  is deleted in the same transaction, and an unknown key refuses Send and keeps the draft.
- [ADR-0039](0039-agents-file-report-drafts-a-person-sends-them.md): the line "Screenshots use the same upload path:
  `create_upload`, presigned PUT, then the tool takes the `upload_id`". The presigned PUT and the processing worker stay;
  the tool that begins the upload and its target change.
- [ADR-0021](0021-mcp-server.md): its tool list gains `create_report_upload` (scope `reports:write`).

Everything else in those ADRs stands: owner-only drafts, the scope `reports:write`, the rule that no token makes a
ticket, and Review owning the drafts UI.

## Context and problem statement

Mia's Claude Code files a report draft for project SHOP: "The login form accepts a wrong password after a reset". Mia
clicks Send. Through `TicketCommands::create` the ticket lands in the team's board like a team ticket: no check reasons,
visible to the whole organization, and no checker is named, though the same words typed into Report a problem would
have made it team-only with the check reason "it sounds risky" for Lena, the team lead, to check. So an agent's report skipped the intake checks
of [ADR-0016](0016-direct-ticket-creation-with-check-flags.md).

Before that, Mia's Claude Code had to upload three pictures for the draft. `create_upload` begins an upload for a
ticket's draft round and needs `rounds:write`; a new report has no ticket yet, Mia's token may have only
`reports:write`, and a tool has one scope.

Which command should Send call, and how does an agent begin a picture for a report that has no ticket yet?

## Decision drivers

- A report is checked the same way wherever it comes from ([ADR-0016](0016-direct-ticket-creation-with-check-flags.md),
  FR-INT-01 to FR-INT-09).
- Anyone in the organization may report a problem on a project a team owns ([ADR-0038](0038-create-ticket-team-form-needs-team-role.md)).
- A tool has exactly one scope, and a token never gets more than its scopes ([ADR-0021](0021-mcp-server.md)).
- No ticket exists until a person clicks Send ([ADR-0039](0039-agents-file-report-drafts-a-person-sends-them.md)).
- Image bytes never pass through PHP; one presigned upload path and worker ([ADR-0011](0011-screenshot-storage-and-processing.md)).

## Considered options

For Send:

1. Keep `TicketCommands::create`, as ADR-0040 wrote.
2. Call `TicketCommands::report`, the command behind Report a problem.

For a new report's pictures:

3. Reuse `create_upload` and let it accept `reports:write` as well as `rounds:write`.
4. A new tool `create_report_upload {project, mime, bytes, sha256}` with scope `reports:write`, on a new upload target
   kind `new_report` whose id is the project key.
5. Make the draft first, then upload with the draft id as target.

## Decision outcome

Chosen options: **2** and **4**, because a sent draft then goes through the same intake checks as any report, and each
tool keeps one scope while the picture's target exists before any draft or ticket does.

- **Send calls `TicketCommands::report`.** Review's `ReportDrafts::send` passes a `ProblemReport` with the project and
  its owning team, the draft's title and detail, the default type and priority, no assignee (so the ticket starts in
  Backlog) and the pin notes, which the word rules read too. Ticketing works out the check reasons (`CheckReasons::for`),
  the team-only rule for a security word, and the checker, exactly as for Report a problem. Duplicate keys are not passed from Send
  yet.
- **`create_report_upload`** (MCP, scope `reports:write`, input `{project, mime, bytes, sha256}`) begins a presigned
  upload on the Media upload target kind **`new_report`** (`Review\Api\ReportDrafts::NEW_REPORT_UPLOAD_TARGET_KIND`),
  whose target id is the project key. Review's `NewReportMediaTarget` allows it for the request's own actor on any
  project of the organization that a team owns, and refuses another person, another organization, an unknown key and a
  project no team owns. The voter is `MediaPermission::UPLOAD`; the audit target is the project's id.
- **REST:** `POST /api/v1/uploads` with `project` runs `create_report_upload`; with `ticket` it runs `create_upload`;
  both or neither is 422.
- `create_report` then takes the `upload_id` of uploads begun for that project's `new_report`; one begun for another
  target or by another person is not found and stays unused.

### Consequences

- Good: an agent's report and a typed report get the same check reasons, visibility and checker.
- Good: a token with only `reports:write` can file a whole report with pictures; `create_upload` keeps one scope.
- Bad: one more tool and one more upload target kind to keep in step with Media's authorizer tag.
- Bad: an upload for `new_report` may be begun and never used by a draft; it waits for Media's retention.
- Neutral: Send gives the default type and priority and no assignee; the owner cannot pick them on the draft page yet.

## Confirmation

- `ReportDraftControllerTest::aSentDraftGetsItsCheckReasonsAndTeamOnlyVisibilityFromItsWords` and
  `sendMakesTheTicketWithRoundZeroAndTheDraftIsGone`.
- `CreateReportTest`: `aReportUploadIsBegunForTheProjectsNewReportAndAuditedByTheProjectsId`,
  `aTokenWithoutReportsWriteIsRefusedByBothToolsAndNothingIsMade`,
  `anUnknownKeyAProjectNoTeamOwnsAndAnotherOrganizationsProjectAreNotFound`,
  `anUploadBegunForAnotherTargetOrByAnotherPersonIsNotFoundAndLeftUnused`.
- `NewReportMediaTargetTest` (who may upload for a project) and `MediaTargetAuthorizersWiringTest` (`new_report` is a
  wired target kind).
- `RestApiTest::anUploadNamesExactlyOneTargetAndAProjectBeginsAReportUpload`.

## Pros and cons of the options

### Option 1, `TicketCommands::create`

- Good, because it is what ADR-0040 wrote and needs no change.
- Bad, because a sent draft skips the check reasons, the team-only rule and the checker.

### Option 2, `TicketCommands::report`

- Good, because one intake path decides how every report is checked.
- Bad, because Send must pick the project's team and default type and priority itself.

### Option 3, `create_upload` with two scopes

- Good, because no new tool.
- Bad, because a tool would have two scopes, and its target is a ticket that a new report does not have.

### Option 4, `create_report_upload` on `new_report`

- Good, because each tool keeps one scope and the target exists before the draft.
- Bad, because it is one more tool and one more target kind.

### Option 5, draft first, then upload to the draft

- Good, because the target is the draft itself.
- Bad, because `create_report` would take two calls and a draft could be seen half-filled.

## More information

- Related: [ADR-0016](0016-direct-ticket-creation-with-check-flags.md), [ADR-0021](0021-mcp-server.md),
  [ADR-0038](0038-create-ticket-team-form-needs-team-role.md), [ADR-0039](0039-agents-file-report-drafts-a-person-sends-them.md),
  [ADR-0040](0040-report-drafts-live-in-review.md), [src/AgentApi/README.md](../../src/AgentApi/README.md),
  [phase-06 hand-off notes](../05-plan/phase-06-intake-and-checks.md), [phase-09 hand-off notes](../05-plan/phase-09-mcp-and-claude-code.md).
- Revisit when: Report a problem and Send need different checks, or a tool needs more than one scope.
