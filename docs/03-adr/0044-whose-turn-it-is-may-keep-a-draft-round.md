# ADR-0044 — The person whose turn it is may keep a draft round, and a tester's findings become the next round

A tester or reviewer can gather new findings on a ticket in a private draft round. When they hand the ticket back, the draft becomes the next round. By default it does not become a new ticket.

## Index

- [Status](#status) — Accepted, date, deciders, what it supersedes.
- [Context and problem statement](#context-and-problem-statement) — Mia finds a second problem while testing.
- [Decision drivers](#decision-drivers) — one ticket per problem thread, privacy, no new hand-off rule.
- [Considered options](#considered-options) — tester's draft, comments, always a new ticket.
- [Decision outcome](#decision-outcome) — who may start a draft, the label, the checklist, "Make it a new ticket".
- [Confirmation](#confirmation) — voter, isolation and journey tests.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — the trade-offs.
- [More information](#more-information) — related ADRs, requirements and revisit triggers.

## Status

Accepted

- Date: 2026-11-19
- Deciders: Zairan Mir (lead, product owner)

It supersedes the "who may start" rule of [ADR-0017](0017-private-draft-rounds.md) and of
[FR-RND-09](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds) ("the assignee of an open
ticket"). The rest of ADR-0017 stands: owner-only privacy, the hand-off dialog and MCP access with the owner's token.

## Context and problem statement

Omar fixed the doubled order total on SHOP-42 and sent it to test. Mia tests it on her laptop. The total is right
now, but the discount line is cut off on a small screen, and a warning shows twice. Today Mia can mark the old pins
Fixed or Still wrong in the review round. She cannot add new screenshots with new pins, because only the assignee may
start a draft round. She ends up writing a comment, or a new ticket that loses the link to SHOP-42.

Mia wants to collect her new findings while she tests. The team should see them only when she hands the ticket back
to Omar.

Who may keep a private draft round on a ticket, and where do a tester's new findings go?

## Decision drivers

- Findings about the same change stay on the same ticket, so its rounds tell the whole story
  ([FR-RND-14](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds)).
- Drafts stay private until shared ([ADR-0017](0017-private-draft-rounds.md), FR-RND-10).
- No new hand-off mechanism: reuse the "Share your draft round?" dialog (FR-RND-11).
- The developer must be able to tick off each new problem, as with Round 0 pins (FR-RND-04).
- The reporter decides when a finding is really a separate problem.

## Considered options

1. **The person whose turn it is may keep a draft round.** Shared at hand-off, it becomes the next round.
2. **Comments with attachments.** The tester writes findings as comments.
3. **Always a new ticket.** Every new finding becomes its own report.

## Decision outcome

Chosen option: **1, the person whose turn it is may keep a draft round**, because it reuses draft rounds and the
hand-off dialog, and it keeps the findings on the ticket they belong to.

- **Who may start one.** On an open ticket, a person may start a draft round when they are the assignee, or when the
  turn read model ([ADR-0015](0015-turn-read-model.md)) says it is their turn (for example the tester in a test step,
  or the reviewer in code review). `DraftRoundCreationVoter` checks this. Everyone else is denied.
- **One per person per ticket.** The partial unique key `rvw_round_ticket_id_owner_id_open_draft_key` already allows
  one open draft per person per ticket. The schema does not change.
- **A draft outlives the turn.** If the turn passes on, the owner keeps the draft. They may still edit, share or
  delete it. They may not start a new one until it is their turn again.
- **Hand-off.** When the owner moves the ticket and their draft has screenshots, the FR-RND-11 dialog asks once:
  "Share and move to <step>", "Keep private and move", or Cancel. Shared, the draft becomes the next round number.
  The dialog follows the owner, not the verb: it also runs on "Needs changes" (tester) and "Ask for changes"
  (reviewer), so the [transition catalogue](../02-architecture/workflow-engine.md#transition-catalogue) gets the
  "draft-round dialog" side effect on those rows.
- **Label.** The assignee's shared draft keeps "Round N · self-check by Omar". Anyone else's shared draft reads
  "Round N · findings by Mia". This is a product decision: the prototype has no such round.
- **Checklist.** Each pin in a shared findings round becomes a checklist item in state `open` (one `rvw_check_item`
  per pin, as FR-RND-04 does for Round 0). The assignee marks it fixed and the tester checks it, under the same voter
  rows. Pins in a self-check round do not become checklist items.
- **"Make it a new ticket".** The draft has a button that moves its screenshots, pins, titles and summaries into a new
  report draft owned by the same person ([ADR-0040](0040-report-drafts-live-in-review.md)). The report draft's
  detail starts with "Found while testing SHOP-42". The round draft is closed. Nothing becomes a ticket until the
  person clicks Send. The 10-screenshot cap of a report draft applies, and a bigger draft is refused with a plain
  message.
- **MCP.** `add_screenshot`, `set_verdict` and `get_round_notes` follow the voter, so a tester's Claude Code can fill
  the tester's draft with the tester's token. No new tool and no new scope.

### Consequences

- Good: a ticket keeps one story, from report to self-check to findings to fix.
- Good: the tester works privately, and the team hears about it once, at hand-off.
- Bad: the voter rule now reads the turn, so the voter matrix grows by one row per role and step.
- Bad: one more round label to keep in the copy catalogue.
- Neutral: the database does not change. Only the rule, the label and the checklist creation change.

## Confirmation

- Voter tests: Mia may start a draft in a test step on a ticket she can see. She may not start one when it is not her
  turn. Lena may start one in code review. Sara (admin, not on the team) is denied.
- Isolation and visibility tests: Omar, Lena and Sara cannot see Mia's unshared draft (HTML, queries, direct URLs).
- Functional test: Mia picks "Share and move" on "Needs changes". The round reads "Round N · findings by Mia", and each of its
  pins is an `open` checklist item.
- Functional test: "Make it a new ticket" creates a report draft with the same content and closes the round draft.
  No ticket exists until Send. Over 10 screenshots, it is refused and nothing changes.
- MCP contract test: Mia's token adds a screenshot to her own draft. Another person's draft is "not found".

## Pros and cons of the options

### Option 1, a draft for the person whose turn it is

- Good, because it reuses draft rounds, the hand-off dialog and the checklist.
- Good, because the findings stay with the change that caused them.
- Bad, because the "who may start" rule becomes more complex.

### Option 2, comments with attachments

- Good, because nothing new is needed for rounds.
- Bad, because comments have no pins, no verdicts and no checklist, and they are public at once.

### Option 3, always a new ticket

- Good, because it is simple.
- Bad, because a regression gets split from the change that caused it, and the board fills with tickets that
  belong together.

## More information

- Related: [ADR-0015](0015-turn-read-model.md), [ADR-0017](0017-private-draft-rounds.md),
  [ADR-0040](0040-report-drafts-live-in-review.md), [ADR-0045](0045-evidence-is-captured-where-the-person-works.md).
- Requirements: FR-RND-04, FR-RND-09, FR-RND-10, FR-RND-11, FR-RND-17.
- Plan: [phase 13](../05-plan/phase-13-capture-explore-and-scenarios.md), P13.T1 and P13.T2.
- Revisit when: more than 20% of findings rounds are turned into new tickets within a week. That would mean testers
  find unrelated problems more often than regressions.
