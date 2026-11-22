# ADR-0048 — Tries in a draft, and one open shared round per ticket

A retake is the next try of the same screenshot inside a private draft. A ticket has at most one open shared round, and
it closes when the person whose turn it is moves the ticket on.

## Index

- [Status](#status) — accepted, amended before merge, deciders, what it supersedes in ADR-0017 and ADR-0044.
- [Context and problem statement](#context-and-problem-statement) — Omar shares three empty rounds while Lena still checks one.
- [Decision drivers](#decision-drivers) — one story per ticket, honest round numbers, no race, no data change.
- [Considered options](#considered-options) — what closes a round, and what happens to old open rounds.
- [Decision outcome](#decision-outcome) — tries, when a round closes and which verbs move on, the share rules, old rounds.
  - [Consequences](#consequences) — the race guard, how Review hears of a move, what we give up.
- [Confirmation](#confirmation) — the tests of P14.T5 and P14.T6.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — what each option loses.
- [More information](#more-information) — related ADRs, requirements, revisit triggers.
- [Note of 2026-11-22](#note-of-2026-11-22-the-checklist-has-no-round-number) — the checklist has no round number; the first shared round is Round 1.
- [Note of 2026-11-22, general notes](#note-of-2026-11-22-general-notes-on-a-round) — one "General notes on this round" per shared round, in place of a verdict per screenshot; it closes and moves nothing.

## Status

Accepted 2026-11-20; amended 2026-11-21 before merge; dated notes 2026-11-22 on round numbers and on general notes · Deciders: Zairan Mir (lead, product owner).

The product owner accepted these decisions in a session on 2026-11-20, after testing pinround by hand
([phase 14](../05-plan/phase-14-tester-feedback.md#decisions-already-made)). An ADR is accepted when it is merged
([README](README.md#status-lifecycle)), and this one was not merged yet, so it was amended in place on 2026-11-21 with
four points the product owner decided that day: a findings round closes when the assignee moves the ticket on; only a
move-on verb closes a round, and the lead-only verbs "Put on hold", "Move to another step…" and "Go on" never do, whoever uses
them, also when the lead is the person the round is ready for; after a lead-only move, whoever's turn it is now closes
the round when they move the ticket on; and the confirmation test for a lead who is also the reviewer. The product
owner confirmed two more points on 2026-11-21, also before merge: "Input given · go on" and "Move on to <next core
step>" used by the person whose turn it is close an open round; and any move-on hand-off that closes the open round may
share the mover's draft in the same move, close first, then number. Amended 2026-11-21 before merge: with no earlier
hand-off round, Round 0's screenshots are the Befores (product owner, to match the phase 14 outcome that a first shared
draft's round page shows Before and After).

It supersedes in part:

- [ADR-0017](0017-private-draft-rounds.md), **Share**: a share no longer always gives the draft the next round
  number. It is refused while another shared round is open or when the draft has no screenshots, and the refused draft
  stays unshared and unnumbered. A share copies only the latest try of each screenshot.
- [ADR-0017](0017-private-draft-rounds.md) and [ADR-0044](0044-whose-turn-it-is-may-keep-a-draft-round.md),
  **hand-off choices** ("Share and move", "Keep private and move", Cancel): when sharing is refused, the dialog drops
  "Share and move" and shows the reason.

ADR-0044 set no rule for when a findings round closes, so the closing rules below add to it and supersede nothing.
The rest of both stands: drafts stay private, the hand-off dialog stays, and the person whose turn it is may still
keep a draft.

## Context and problem statement

Omar fixes the doubled order total on SHOP-42 with Claude Code. Each time Claude Code takes a new screenshot of the
cart, it lands in Omar's draft as one more screenshot, so the draft fills with old and new pictures of the same screen.
Lena reviews Round 1. Before she is done, Omar shares Rounds 2, 3 and 4 back to back, all of them empty. Lena no longer
knows which round to check, and the round numbers count clicks, not hand-offs.

How does a draft keep the back-and-forth of one screen, and when may someone share the next round on a ticket?

## Decision drivers

- One ticket tells one story: a round number counts hand-offs, not tries
  ([FR-RND-14](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds)).
- The person a round is ready for knows which round to check, and nobody shares over their head.
- No extra click to close a round: people already move the ticket when they are done.
- Two shares at the same moment cannot both pass the check (phase 14 design notes, "Races").
- Caps on anything sized from outside: tries per screenshot get a cap, as screenshots per round already have.
- No data migration of rounds people already shared.

## Considered options

What closes a shared round:

1. **Moving the ticket on.** The person whose turn it is moves the ticket; the verb is the verdict.
2. **Every picture marked.** The round closes when every screenshot has a verdict.
3. **A Close round button.** The person it is ready for closes it by hand.

What happens to tickets that already have more than one open shared round:

4. **Close all but the newest.** A migration closes the older ones.
5. **Leave them open.** No migration.
6. **Close them all.** A migration closes every open shared round.

## Decision outcome

Chosen options: **1, moving the ticket on**, and **5, leave old open rounds as they are**. Moving on is what the
reviewer and the tester already do when they are done, so the round closes with no new click. Leaving old rounds open
changes no data that people already saw.

**Tries.**

- A retake is a try of a screenshot in the same draft. The new screenshot row points at the one it retakes
  (`rvw_screenshot.retake_of`, same round, same organization).
- A screenshot keeps at most **5 tries**. A sixth is refused with a plain message, and nothing is stored.
- Pins stay on the try they were put on.
- **Share copies the latest try** of each screenshot into the shared round. Earlier tries stay with the owner and are
  deleted with the draft. The team sees one screenshot per chain, and its "before" is the earlier round's "after"; with
  no earlier shared draft or review round, the report's (Round 0's) same-numbered screenshot (amended 2026-11-21).
- The existing caps stay: 50 screenshots per round, 10 per report draft, title 120, summary 600, note 1,000.

**Which rounds count.** A *shared round* here is a round shared from a draft, or a review round: a hand-off with a
number from 1. Round 0 (the report) and the checklist round are not hand-offs. They never block a share, and this ADR
does not close them. Round 0 still gives the Befores of the first shared draft (amended 2026-11-21); the checklist round
never does.

**When a shared round closes.**

- A round shared at a hand-off is ready for the person whose turn it is next (the reviewer after "Send to code
  review", the tester after "Approve code"). It stays open until **that person moves the ticket on** (after a
  lead-only move, whoever's turn it is now; see below) with a move-on verb. The verb is the round's verdict. The round
  becomes `RoundState::Closed` in the move's transaction.
- **A move-on verb is any verb of the
  [transition catalogue](../02-architecture/workflow-engine.md#transition-catalogue) that is not lead-only, used by
  whoever's turn it is now**: "Send to code review", "Send to testing", "Approve code", "Ask for changes", "Pass it",
  "Needs changes", "Input given · go on", "Move on to <next core step>" and the others that are not lead-only (product
  owner, confirmed 2026-11-21). For example, Round 2 is ready
  for Mia in the team's Checking step; her "Move on to Testing" closes it. The same verb used by a lead whose turn it
  is not leaves the round open.
- **Only the person whose turn it is closes it, and only with a move-on verb** (product owner, 2026-11-21). A move by
  anyone else leaves an open round open. The lead-only verbs "Put on hold", "Move to another step…" and "Go on" never
  close a round, whoever uses them, also when the lead is the person the round is ready for. For example, Round 2 is ready for
  Lena at code review; Lena puts the ticket on hold; Round 2 stays open; later, back in Code review, her "Approve
  code" closes it.
- **After a lead-only move, whoever's turn it is now closes it** (product owner, 2026-11-21). When a lead moves the
  ticket past the turn of the person a round is ready for, the round stays open, and the person whose turn it is in
  the new step closes it when they move the ticket on. For example, Round 2 is ready for Lena at code review; Lena, as
  lead, moves the ticket to Testing with "Move to another step…"; Round 2 stays open; Mia's "Pass it" closes it, and
  Omar can then share Round 3.
- A **findings round** ([ADR-0044](0044-whose-turn-it-is-may-keep-a-draft-round.md)) is ready for the assignee. It
  closes when **the assignee moves the ticket on**, with a move-on verb, like any other round (product owner,
  2026-11-21); after a lead-only move, when whoever's turn it is now moves it on. That holds also when the assignee
  chooses "Keep private and move" and shares no new screenshots. It also closes when **the assignee shares the next
  round**: that share closes it first, then numbers the new round, in one transaction.
- A tester's "Needs changes" with a draft does both in one move: it closes the open round with her verdict, then
  shares her draft as "Round N · findings by Mia".
- **Any move-on hand-off that closes the open round may share the mover's draft in the same move**, the same way:
  close first, then number, in one transaction (product owner, confirmed 2026-11-21). For example, Round 2 is ready for
  Lena at code review; Lena, as lead, moves the ticket back to In progress with "Move to another step…"; Round 2 stays
  open; Omar's "Send to code review" with a draft closes Round 2 and shares Omar's draft as Round 3.

**Share rules.** On every path (the hand-off dialog, "Share now", MCP and REST):

- **One open shared round per ticket.** Sharing while another shared round on the ticket is open is refused with the
  reason, for example "Round 2 is still open. Lena gives a verdict first." The exceptions are a share that closes the
  open round first, as above: the assignee's share that closes an open findings round, and a move-on hand-off that
  closes the open round and shares the mover's draft in the same move. The refused draft stays unshared and
  unnumbered.
- **No empty share.** Sharing a draft with no screenshots is refused: "Add a screenshot before you share."
- The hand-off dialog offers only "Keep private and move" when sharing is refused, and shows the reason. MCP and REST
  answer the refusal as a 4xx with the reason.
- Anyone may keep a private draft meanwhile; only sharing waits.

**Old open rounds.** Tickets that already have more than one open shared round keep them. There is no data migration.
The demo seed has two open shared rounds on one ticket, and stays that way. On such a ticket every share is refused
until the rounds close, and the next move-on verb by the person whose turn it is closes every open shared round of the
ticket that is not a findings round. An open findings round closes on the assignee's move, as above.

### Consequences

- Good: a round number means a hand-off again, and the reviewer or tester always knows which round to check.
- Good: no new button; the move people already make closes the round.
- Good: Claude Code can retake a screen as often as it needs (up to 5 tries) without new rounds or clutter.
- Bad: **a race guard is needed.** Two shares at the same moment must not both pass the "no open round" check. Share
  either locks the ticket's round rows (`SELECT … FOR UPDATE`) or writes conditionally and checks the row count. A
  partial unique index on open shared rounds per ticket, like `rvw_round_ticket_id_owner_id_open_draft_key` in
  `migrations/Version20261115180000.php`, would also do it, but it cannot be built while old tickets hold two open
  rounds (option 5). So the lock or the conditional write is the guard for now.
- Bad: **Review must hear about the move.** Ticketing's `MoveTicket` runs Workflow's verbs; rounds belong to Review.
  Under [ADR-0002](0002-modular-monolith.md) and [ADR-0032](0032-module-call-edges-follow-modules-catalogue.md) that
  goes through an event (`Ticketing\Api\Event\TicketMoved`) or an Api port. The close and the findings share must be
  in one transaction with the move, and `TicketMoved` reaches listeners only after commit through the outbox
  ([ADR-0009](0009-async-messaging-and-outbox.md)). The port Review already implements for the move
  (`Ticketing\Api\ShareDraftQuestion`, called inside `MoveTicket`) shows the same-transaction way. P14.T5 picks the
  path and updates the "Depends on" lines in [modules.md](../02-architecture/modules.md) if it adds a call.
- Bad: because the lead-only verbs "Put on hold", "Move to another step…" and "Go on" do not close the round, even
  when the round is ready for that lead, the round can stay open while the ticket sits in another step, and no new round can be shared
  until whoever's turn it is now moves the ticket on. That person closes a round that was ready for someone else, with
  their own verb as its verdict.
- Bad: the verb closes the round even when the person never opened the pictures.
- Bad: old tickets with two open rounds look odd until someone moves them on.
- Neutral: the database gains `rvw_screenshot.retake_of` (index led by `organization_id`, RLS on). The round table
  does not change; `state` already holds `closed`.

## Confirmation

- P14.T5: domain unit tests for the share refusals and the close; a repository integration test with two connections
  where exactly one of two shares wins; functional and MCP tests where a refused share leaves the draft unshared and
  unnumbered (state, not only status); a test where Mia's "Needs changes" closes Round 2 and shares Round 3 · findings
  by Mia in one move, and Omar's next share closes Round 3; a test where Omar moves the ticket on with "Keep private
  and move" and no new screenshots, and Round 3 · findings by Mia closes with nothing new shared; a test where Round 2
  is ready for Lena, who is both lead and reviewer, her "Put on hold" leaves Round 2 open and a share is still refused,
  and later, back in Code review, her "Approve code" closes Round 2; a test where Round 2 is ready for Lena at code
  review, Lena as lead moves the ticket to Testing, Round 2 stays open and a share is still refused, then Mia's "Pass
  it" closes Round 2 and Omar's next share numbers Round 3; a test where Round 2 is ready for Lena at code review, Lena
  as lead moves the ticket back to In progress, Round 2 stays open, then Omar's "Send to code review" with a draft
  closes Round 2 and shares his draft as Round 3 in one move.
- P14.T6: domain, migration, repository and isolation tests for `retake_of`; a sixth try is refused and nothing is
  stored; `retake_of` pointing at another round, another person's draft or another organization answers 404; after
  share the round holds one screenshot per chain, the latest try.

## Pros and cons of the options

### Option 1, moving the ticket on (chosen)

- Good, because it needs no new click and matches how people already finish a check.
- Good, because the round's verdict and the ticket's step cannot disagree.
- Bad, because it loses a verdict that is separate from the move: the round closes even if the pictures were never
  looked at, and a move by anyone else, or a lead-only verb even from the person it is ready for, leaves it open.

### Option 2, every picture marked

- Good, because the round closes only when every picture was looked at.
- Bad, because it loses the quick path: a reviewer who approves without marking each picture leaves the round open
  and blocks the next share. The round and the ticket can disagree (the ticket in Testing, the round still open).

### Option 3, a Close round button

- Good, because closing is explicit.
- Bad, because it loses simplicity: one more click that people forget, and a forgotten round blocks every share on the
  ticket.

### Option 4, close all but the newest

- Good, because every ticket follows the new rule at once.
- Bad, because it loses the people's say: a migration changes rounds nobody decided on, and "newest" may not be the
  one the reviewer is checking.

### Option 5, leave them open (chosen)

- Good, because no data changes and nothing people saw disappears.
- Bad, because it loses a clean start: those tickets refuse every share until someone moves them on, and the partial
  unique index cannot be built yet.

### Option 6, close them all

- Good, because the partial unique index could be built at once.
- Bad, because it loses open work: the round a reviewer is checking right now closes under her, with no verdict.

## More information

- Related: [ADR-0002](0002-modular-monolith.md), [ADR-0009](0009-async-messaging-and-outbox.md),
  [ADR-0015](0015-turn-read-model.md), [ADR-0017](0017-private-draft-rounds.md),
  [ADR-0044](0044-whose-turn-it-is-may-keep-a-draft-round.md).
- Requirements: FR-RND-11, FR-RND-12, FR-RND-14; FR-RND-19 (tries) and FR-RND-20 (one open round), which P14.T6 and
  P14.T5 write.
- Plan: [phase 14](../05-plan/phase-14-tester-feedback.md), P14.T5 and P14.T6.
- Revisit when: more than 10% of shares in 30 days are refused because a round is open, or a shared round stays open
  more than 7 days after the ticket left the step it was shared into. Either means a lead's move should close it, or a
  Close round button is needed after all.

## Note of 2026-11-22: the checklist has no round number

The product owner decided on 2026-11-22 that the checklist made from the report's pins is not a numbered round. Round 0
is the report; the checklist follows it as "Checklist from the report", with no number; the first round shared after
the report is Round 1, then 2, 3 and so on. The decisions above stand as written: their example numbers ("Round 2 is
ready…") counted the checklist as Round 1, so read them one lower. The checklist was never a hand-off round, so no rule
on closing, sharing or Befores changes. In the schema the checklist row keeps `kind = 'checklist'` and `shared_at`, has
`number` NULL (`rvw_round_shared_check`, Version20261122060000), and its one slot per ticket stays
`rvw_round_ticket_id_checklist_key`; it has no round page of its own, as `/t/{key}/rounds/{number}` needs a number, and
its items are checked on the ticket page and on Round 0's page. Data was dummy, so no rounds were renumbered.

## Note of 2026-11-22: general notes on a round

The same day, the product owner first asked for a verdict with a note on each screenshot of a shared draft
(Version20261122120000, `rvw_screenshot_verdict`), then replaced it: what a reviewer cannot pin on a screenshot (a change
for the whole round, something the screenshots do not show) has no place on one screenshot. A shared round now has one
section, "General notes on this round": a list of notes, newest first, each with its author, an optional Pass, Needs fix
or Question, the note and its time, and a form to add one, on the page of a round without screen areas (a shared draft,
the report). Everyone who may see the round reads them; whoever may write
in the ticket's thread adds one (`RoundPermission::ADD_GENERAL_NOTE`, the same people as `ConversationPermission::COMMENT`)
while the round is open; a closed round keeps its notes read-only. Claude Code reads them as `general_notes` of
`get_round_notes`' shared round, as untrusted text (ADR-0020). It extends this ADR without changing it: option 1 stands,
so a note or its verdict closes no round and moves no ticket. The notes are stored in `rvw_round_note`
(Version20261122130000, which drops `rvw_screenshot_verdict`; its rows were demo data only). What happened does not list
general notes yet.
