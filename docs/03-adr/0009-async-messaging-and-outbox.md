# ADR-0009 — Async work with Symfony Messenger and a transactional outbox

Side effects run asynchronously through Symfony Messenger; domain events are saved in the same transaction as the change that caused them.

## Index

- [Status](#status) — accepted, date, deciders.
- [Context and problem statement](#context-and-problem-statement) — lost or duplicated side effects.
- [Decision drivers](#decision-drivers) — reliability, speed, simplicity.
- [Considered options](#considered-options) — sync only, dispatch after commit, outbox, external broker.
- [Decision outcome](#decision-outcome) — buses, transports, the outbox flow, handler rules.
- [Confirmation](#confirmation) — tests and monitoring.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — trade-offs.
- [More information](#more-information) — links and revisit triggers.

## Status

Accepted · 2026-11-06 · Deciders: Zairan Mir (lead).

## Context and problem statement

Mia presses "Pass it". The ticket must move to Done, the turn read model must update,
Lena's pings on that ticket must clear, an audit line must be written, the Jira status
must sync, and Omar's Home must refresh live. If the Jira call is slow, Mia should not wait.
If the server crashes after the commit but before the Jira call, the sync must still happen.
If it runs twice, nothing may break.

How do we run side effects fast, never lose them, and never let them corrupt data?

## Decision drivers

- Action latency ≤ 150 ms p95 plus async side effects (NFR-PERF).
- No lost side effects after a committed change (NFR-REL).
- Read models (whose turn) must be consistent with the change for the acting user ([ADR-0015](0015-turn-read-model.md)).
- Few moving parts for a team of 3.

## Considered options

1. Everything synchronous in the request.
2. Dispatch messages to a broker right after commit.
3. Transactional outbox: write events into a table in the same transaction, relay them after.
4. A full external broker (RabbitMQ, Kafka) with outbox.

## Decision outcome

Chosen option: **Symfony Messenger with a transactional outbox in PostgreSQL**, plus a
Valkey transport for heavy jobs.

Buses and transports:

| Bus / transport | What goes on it | Where |
|---|---|---|
| `command.bus` (sync) | User actions (MoveTicket, AnswerQuestion). One handler, one transaction. | in-process |
| Same-transaction listeners | Updates that must be consistent at once: the turn read model, ticket_event log. | in-process, same transaction |
| `outbox` transport | Domain events (TicketMoved, QuestionAnswered, PinVerdictSet …). | Doctrine transport table in PostgreSQL, written in the same transaction |
| `async` transport | Jobs fed by the relay: clear pings, audit, notifications, Mercure publish, Jira sync. | Valkey (Redis transport) |
| `heavy` transport | Image processing, AI calls, Jira import, exports, email digests. | Valkey, separate consumers |
| `failed` transport | Messages that failed after retries. | PostgreSQL |

Flow:

```mermaid
sequenceDiagram
  participant U as Mia (browser)
  participant H as MoveTicket handler
  participant DB as PostgreSQL
  participant R as Outbox relay worker
  participant Q as Valkey queues
  participant W as Workers
  U->>H: POST "Pass it"
  H->>DB: BEGIN; update tkt_ticket; update ins_ticket_turn; insert tkt_ticket_event; insert outbox(TicketMoved); COMMIT
  H-->>U: 303 + Turbo Stream (fast)
  R->>DB: read outbox rows
  R->>Q: route to async / heavy
  W->>Q: consume (clear pings, audit, Jira sync, Mercure push)
```

Handler rules:

- Every async handler is **idempotent**. It uses the event id as a dedupe key
  (`processed_message` table or a natural unique constraint).
- Messages carry ids and small facts, not entities or ticket text.
- Every message has a `TenantStamp`; the worker sets the tenant before touching data ([ADR-0005](0005-multi-tenancy.md)).
- Retries: 3 attempts with exponential backoff (1 s, 10 s, 60 s) for `async`; AI and Jira use
  longer delays and respect provider rate limits.
- Order is guaranteed only per ticket where needed (the Jira sync handler checks the ticket version).

### Consequences

- Good: a committed change always leads to its side effects; a crash cannot lose them.
- Good: the user only waits for the database write.
- Bad: side effects are eventually consistent; for example Jira may lag a few seconds.
- Bad: we must run a relay worker and watch outbox age.
- Neutral: Valkey is already needed for cache and locks ([ADR-0010](0010-cache-sessions-locks.md)).

## Confirmation

- Integration test: a handler that throws after commit; the outbox row stays and is delivered later.
- Idempotency test per async handler: dispatch the same event twice, assert one effect.
- Deptrac/PHPStan rule: message classes in `*/Application/Message` hold only scalars, ids and enums.
- Metrics and alerts ([ADR-0029](0029-observability.md)): outbox oldest row age > 30 s,
  queue age > 60 s, and failed transport count > 0 all trigger alerts.

## Pros and cons of the options

### Everything synchronous

- Good, because it is simple.
- Bad, because slow Jira or AI calls block the user, and a failure rolls back the whole action.

### Dispatch after commit

- Good, because it is fast.
- Bad, because a crash between commit and dispatch loses the event.

### Transactional outbox

- Good, because no event is lost and there is no 2-phase commit.
- Bad, because there is an extra table and a relay process.

### External broker (RabbitMQ/Kafka)

- Good, because of high throughput, routing, and replay (Kafka).
- Bad, because it is one more system to run; it is not needed at 500 req/s.

## More information

- Revisit when: the outbox relay cannot keep oldest-row age under 30 s at peak,
  or event volume passes 1,000 per second. Then move `async` to RabbitMQ.
