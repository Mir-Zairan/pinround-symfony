# ADR-0017: Draft rounds are private to their owner until shared

The assignee can check their own work in a private round. Nobody else sees it until they share it.

## Index

- [Status and people](#status-and-people): status, date, deciders.
- [Context and problem statement](#context-and-problem-statement): the scene and the problem.
- [Decision drivers](#decision-drivers): what the decision must achieve.
- [Considered options](#considered-options): three ways to handle self-check work.
- [Decision outcome](#decision-outcome): the rules: owner-only, hand-off dialog, MCP access.
  - [Consequences](#consequences): good, bad and neutral effects.
- [Confirmation](#confirmation): tests that prove the privacy rule.
- [Pros and cons of the options](#pros-and-cons-of-the-options): details per option.
- [Clarifications (2026-11-06)](#clarifications-2026-11-06) — same decision, details made explicit.
- [More information](#more-information): links and when to revisit.

## Status and people

- Status: Accepted. Superseded in part by [ADR-0044](0044-whose-turn-it-is-may-keep-a-draft-round.md): who may start a draft round (the assignee, or the person whose turn it is). Superseded in part by [ADR-0048](0048-tries-in-a-draft-and-one-open-round-per-ticket.md): a share may be refused and then stays unnumbered, it copies the latest try of each screenshot, and the hand-off dialog drops "Share and move" when sharing is refused. Everything else here still stands.
- Date: 2026-11-06
- Deciders: Zairan (lead), Toyib (developer), Anka (tester)

## Context and problem statement

Omar fixes the doubled order total with Claude Code. Before he sends the ticket to code review, he wants to see the result himself. He takes three screenshots, puts a pin on a column that is now left-aligned, writes "Not right yet", and asks Claude Code to "read my pinround notes and fix what is marked". He does not want Lena and Mia to get notifications for half-done work, and he does not want his rough notes to show up as an official round.

If every round is visible to the team, people stop writing honest notes to themselves. If self-check notes live outside pinround (a text file, a chat), Claude Code and the tester can never see them later.

Where should self-check work live, and who may see it?

## Decision drivers

- The assignee can make rough notes without an audience.
- Claude Code can read those notes and add screenshots and verdicts. It acts as the owner, with the owner's token.
- No notification and no "Needs you" item is created by a draft.
- When the ticket leaves the owner, the owner decides on purpose whether the team sees the draft.
- There is no way to leak a draft through lists, search, real-time pushes or exports.

## Considered options

1. **Private draft round (chosen).** A round of kind `draft` with an owner. Only the owner sees it. Sharing turns it into a normal numbered round.
2. **Normal rounds, always visible.** Self-checks are just rounds.
3. **Local notes outside pinround.** The developer keeps notes in files that Claude Code reads.

## Decision outcome

Chosen option: **1, private draft rounds**.

Rules:

- A draft round is an `rvw_round` row with `kind = draft`, `owner_id` and `shared_at = null`. It holds screenshots, pins with notes, and a verdict per screenshot ("Looks right" / "Not right yet").
- Only the current assignee can start a draft round on a ticket (voter `ROUND_DRAFT_CREATE`). One open draft per owner per ticket.
- Read and write access: owner only (voters `ROUND_VIEW` and `ROUND_EDIT`). Org admins do **not** get read access to unshared drafts through the UI. Data exports follow the same rule (see ADR-0026). Database operators can still read rows, as with all data.
- Drafts are excluded from search, list counts, real-time topics other than the owner's private topic (ADR-0023), notifications and Insight read models.
- **Hand-off:** when the owner moves the ticket to another step and an unshared, undecided draft with at least one screenshot exists, the UI asks first: "Share and move", "Keep private and move", or Cancel. The server records the choice (`decided_at`). If the client skips the dialog, for example an API call, the default is **keep private**.
- **Share:** sets `shared_at`, gives the round its number (next round number on the ticket), and writes a ticket event. From then on it follows normal round visibility (the ticket's visibility).
- **Delete:** the owner can delete an unshared draft. Media objects are scheduled for deletion.
- **MCP:** `get_round_notes`, `add_screenshot` and `set_verdict` (ADR-0021) can work on a draft only with a personal access token of the draft owner that has the right scope. Another user's token gets "not found" (not "forbidden"), so the draft's existence is not revealed.

### Consequences

- Good: honest self-check notes, and a clean loop with Claude Code.
- Good: the team sees a round only when it is meant for them.
- Bad: if the owner leaves the company, unshared drafts are lost to the team. An admin can transfer drafts only as part of user offboarding, which writes an audit event.
- Neutral: round numbers are given at share time, so numbers have no gaps.

## Confirmation

- Voter tests: another team member, the lead, a manager and an org admin all get "not found" for an unshared draft through UI routes, the API and MCP.
- Query tests: drafts never appear in ticket list counts, search results, Home items or Mercure payloads for other users.
- Functional test: the move action with an undecided draft returns the dialog. With `decision=keep` it moves and keeps `shared_at` null.
- Playwright journey: start draft → add screenshot → pin → verdict → send to review → share → the tester sees "Round N · self-check by Omar".

## Pros and cons of the options

### 1. Private draft round

- Good, because it uses the same data model as rounds (screenshots, pins, verdicts).
- Good, because Claude Code can use it through MCP.
- Bad, because it adds a privacy rule that every query must respect. This is mitigated by one repository method and voter tests.

### 2. Normal rounds, always visible

- Good, because it is simplest.
- Bad, because people avoid writing rough notes, or the team gets noise and notifications.

### 3. Local notes outside pinround

- Good, because there are no new privacy rules.
- Bad, because the notes are not linked to screenshots and pins, and the tester can never see them later.
- Bad, because it gives Claude Code a second, unstructured source.

## Clarifications (2026-11-06)

- "Owner only" includes **admins and team leads**: neither can open, list, count or export another person's unshared draft in the UI, API, MCP or exports. The only exception is the audited offboarding transfer described above.

## More information

- Related: [ADR-0013](0013-authorization.md) (voters), [ADR-0021](0021-mcp-server.md) (MCP tools), [ADR-0023](0023-realtime-mercure.md) (private topics), [ADR-0011](0011-screenshot-storage-and-processing.md) (media).
- Architecture: [media-pipeline.md](../02-architecture/media-pipeline.md), [security.md](../02-architecture/security.md).
- Revisit if more than 30% of drafts are never shared and the team asks to see them, or if admins need read access to drafts for compliance reasons.
