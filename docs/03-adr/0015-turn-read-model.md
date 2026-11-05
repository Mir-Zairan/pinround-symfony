# ADR-0015 — Store "whose turn" as a read model updated on every change

For each open ticket we store whose turn it is, what kind of task it is, and since when it waits, so hot pages read one indexed table.

## Index

- [Status](#status) — accepted, date, deciders.
- [Context and problem statement](#context-and-problem-statement) — why computing turns per request is too slow.
- [Decision drivers](#decision-drivers) — speed, correctness, simplicity.
- [Considered options](#considered-options) — compute per request, cache, async read model, same-transaction read model.
- [Decision outcome](#decision-outcome) — the table, the rules, the score, the update path.
- [Confirmation](#confirmation) — tests and checks.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — trade-offs.
- [Clarifications (2026-11-06)](#clarifications-2026-11-06) — same decision, details made explicit.
- [More information](#more-information) — links and revisit triggers.

## Status

Accepted · 2026-11-06 · Deciders: Zairan Mir (lead).

## Context and problem statement

Lena opens Home: "30 things wait on you". To show that, pinround must know, for every open
ticket in her org, whose turn it is: an open question to her, a review where she is the reviewer,
a new ticket she must check, a ticket on hold that only she can restart. The "Whose turn?" view
groups all tickets by person. The workload bars count turns per person. In a big org with
200k tickets, computing all this from tickets, threads and rules on every page load is too slow.

How do we answer "whose turn is it?" fast for Home, lists and workload, and always correctly?

## Decision drivers

- Home ≤ 250 ms p95 and list ≤ 200 ms p95 at 200k tickets per org (NFR-PERF).
- The acting user must see the new turn at once after an action (no stale Home).
- Turn rules depend on several modules (Workflow, Conversation, Ticketing check flags).
- Simple to rebuild if a rule changes.

## Considered options

1. Compute turns per request from tickets, threads and rules.
2. Compute per request and cache the result per user.
3. Read model updated asynchronously from events.
4. Read model updated in the same transaction as the change, with an async rebuild path.

## Decision outcome

Chosen option: **a read model in the Insight module, updated in the same transaction (option 4)**.

Table `ins_ticket_turn` (one row per open ticket):

| Column | Meaning |
|---|---|
| `organization_id`, `ticket_id` (pk) | Tenant and ticket. |
| `turn_user_id` (nullable) | The person who acts next; null for "Customer", "Product owner" or done. |
| `turn_label` | Text for non-users ("Customer", "Product owner"). |
| `kind` | `answer`, `check`, `review`, `test`, `assign`, `input`, `decide`, `build`. |
| `base_score` | Kind weight + priority weight + release weight (no time part). |
| `waiting_since` | When the current turn started. |
| `group` | One of the five groups, for counts. |
| `check_user_id` (nullable) | The checker when the ticket has open check reasons. |
| `team_id`, `visibility` | For fast, permission-aware filters. |

Indexes: `(organization_id, turn_user_id, kind)`, `(organization_id, check_user_id) WHERE check_user_id IS NOT NULL`,
`(organization_id, team_id, group)`.

Turn rules (the `TurnResolver`, pure PHP, one place):

1. An open question → the asked person (kind `answer`).
2. A new ticket with open check reasons and no assignee → the checker (`check`).
3. Backlog or On hold → the team lead (`decide` for hold).
4. Code review → the reviewer stored on the ticket as `reviewer_id` (`review`; see FR-WF-14).
5. Testing, or a company/team step in the Checking group → the tester stored as `tester_id` (`test`).
6. Needs input → `waiting_on_user_id`, or the label in `waiting_on_label` with `turn_user_id` null (`input`; see FR-WF-15).
7. Done → nobody (row deleted).
8. Otherwise → the assignee (`build`), or the lead when nobody is assigned (`assign`).

The check task is stored separately (`check_user_id`), so an assigned person can build while the lead checks.

Score: `base_score` = kind (answer 100, check 70 (+40 if risky), review 30, test 30, assign 25, input 20, decide 15, build 10)
\+ priority (Blocker 50, Critical 40, Major 20, Normal 8, Minor 3) + release (current 30, any 10).
The time part `LEAST(days_waiting, 60) * 0.5` is computed in SQL when reading, so rows do not need nightly updates.

Update path:

- Every command that can change a turn (move, assign, ask/answer a question, check, report, team change, rule change)
  calls `Insight\Api\TurnProjector::refresh(ticketId)` inside the same transaction. This is an allowed
  synchronous Api call ([ADR-0002](0002-modular-monolith.md)).
- Changes that touch many tickets (a rule change, a team lead change) enqueue a rebuild job per team.
- `bin/console insight:turns:rebuild --org=…` rebuilds from scratch and is used after rule changes in a release.

### Consequences

- Good: Home, Whose turn and workload are single indexed queries.
- Good: the acting user sees the new turn immediately.
- Good: turn logic lives in one resolver, shared by Home, lists, MCP and notifications.
- Bad: every turn-changing command must call the projector; missing one gives stale turns.
- Bad: the extra write costs a little on each action.
- Neutral: Insight depends on other modules' Api for the inputs to the resolver.

## Confirmation

- **Consistency test**: for random fixtures and random actions, a full rebuild must produce exactly the stored rows.
- A nightly job (P12) compares a sample of rows with a fresh resolve and reports drift as a metric; alert if drift > 0.
- An architecture test lists all command handlers marked `#[ChangesTurn]` and asserts each calls the projector (test via a spy).
- Query plans for Home and Whose turn are checked in a test to use the indexes (EXPLAIN in CI on seeded data).

## Pros and cons of the options

### Compute per request

- Good, because it is always correct and has no extra table.
- Bad, because it is too slow at 200k tickets; complex SQL across modules.

### Compute and cache

- Good, because reads are fast.
- Bad, because invalidation is hard (one move affects several users); stale Home after an action.

### Async read model

- Good, because writes are fast.
- Bad, because the acting user may see an old Home for a few seconds.

### Same-transaction read model

- Good, because it is fast and immediately correct.
- Bad, because every turn-changing command must call it.

## Clarifications (2026-11-06)

- **Reading across modules.** Home, Whose turn and workload need ticket fields (key, title, priority) next to turn rows. Insight's query service (`Insight\Infrastructure\Query\TurnQueries`) is the **only** place allowed to join Ticketing data with `ins_ticket_turn`, and only through the read-only SQL view `tkt_ticket_list_v` owned by Ticketing. This is one named, allowed Deptrac edge; no other Insight code reads `tkt_` tables. If Ticketing changes a column, it keeps the view stable or versions it.
- **Reviewer and tester** are chosen by Ticketing when the ticket enters Code review / Testing (FR-WF-14) and stored on the ticket; the resolver only reads them.

## More information

- Workflow rules: [ADR-0014](0014-status-model-five-groups.md). Performance: [performance-and-scale.md](../02-architecture/performance-and-scale.md).
- Revisit when: action latency p95 rises above 150 ms because of projector work, or drift is found in production.
