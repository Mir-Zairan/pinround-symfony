# ADR-0033 — Valkey transports, workers and the outbox relay

Four Valkey transports replace ADR-0009's `async` and `heavy`. Three workers consume them, a relay moves outbox events to them, and AI and Jira retry more slowly.

## Index

- [Status](#status) — accepted, supersedes part of ADR-0009.
- [Context and problem statement](#context-and-problem-statement) — one worker, two named transports, no numbers.
- [Decision drivers](#decision-drivers) — latency, isolation, small machines, PgBouncer, rate limits.
- [Considered options](#considered-options) — ADR-0009 as written, one worker, three workers, five workers.
- [Decision outcome](#decision-outcome) — transports, workers, relay, polling, retries, the DSN rule.
- [Confirmation](#confirmation) — the tests and the compose check.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — trade-offs.
- [More information](#more-information) — links and revisit triggers.

## Status

Accepted · Supersedes in part [ADR-0009](0009-async-messaging-and-outbox.md): only the `async` and `heavy` rows of its "Buses and transports" table, the relay's "route to async / heavy" step, and its retry line for AI and Jira. The rest of ADR-0009 stays as decided.

- Date: 2026-11-07
- Deciders: Zairan Mir (lead)

## Context and problem statement

Mia passes a ticket while Omar asks for AI summaries of a large round. The AI provider takes 20 s a call and starts to rate-limit. With one worker reading the outbox and every queue, Mia's `TicketMoved` event waits in the outbox behind Omar's AI jobs, and Lena's pings on that ticket do not clear.

ADR-0009 names two Valkey transports, `async` and `heavy`, and says AI and Jira retry "with longer delays" without numbers. The P00.T7 plan asks for four. How do we split transports and workers, relay outbox events, and retry, so a slow job never holds up an event or a notification?

## Decision drivers

- Domain events leave the outbox in under 1 s ([performance-and-scale.md › Async work](../02-architecture/performance-and-scale.md#async-work)).
- A slow AI provider never delays notifications (the "AI backlog" load test: notifications queue age stays under 5 s).
- C-10: one VM with 4 vCPU and 8 GB RAM.
- PgBouncer transaction pooling keeps no session state, so no LISTEN/NOTIFY ([operations.md › Configuration](../02-architecture/operations.md#configuration)).
- AI providers and Jira rate-limit us; Jira sync retries with backoff and jitter ([ADR-0025](0025-jira-integration.md)).

## Considered options

1. ADR-0009 as written: `async` and `heavy`, one worker.
2. Four transports, one worker for all of them and the outbox.
3. Four transports, three workers: outbox, short jobs, heavy jobs.
4. One worker per transport (five).

## Decision outcome

Chosen option: **3, four transports and three workers**, because it keeps the relay and notifications away from slow jobs with only two more containers on a small machine.

| Transport | Store | Consumed by | What goes on it | Retries (`config/packages/messenger.yaml`) |
|---|---|---|---|---|
| `outbox` | PostgreSQL table `messenger_outbox`, app connection | `worker-outbox` | Domain events, written in the change's transaction | 10, waiting 1 s doubling to 60 s (about 5 min) |
| `async_notify` | state Valkey, stream `async_notify` | `worker` | Notifications, Mercure, email | 3, waiting 1 s, 10 s, 60 s |
| `async_integration` | state Valkey, stream `async_integration` | `worker` | Jira sync | 5, waiting 30 s, 90 s, 270 s, 810 s, 900 s, jitter 0.1 |
| `async_media` | state Valkey, stream `async_media` | `worker-heavy` | Screenshot processing | 3, waiting 1 s, 10 s, 60 s |
| `async_ai` | state Valkey, stream `async_ai` | `worker-heavy` | AI summaries, suggestions, embeddings | 5, waiting 30 s, 90 s, 270 s, 810 s, 900 s, jitter 0.1 |
| `failed` | PostgreSQL table `messenger_failed` | nobody; an operator runs `messenger:failed:retry` | Messages whose retries ran out | none |

**Workers.** One image, the non-root `app` user, one shared health check, `--time-limit=3600`, never `--no-reset`:

- `worker-outbox`: `messenger:consume outbox --sleep=0.5 --memory-limit=128M`.
- `worker`: `messenger:consume async_notify async_integration --memory-limit=256M`. It reads `async_notify` first.
- `worker-heavy`: `messenger:consume async_media async_ai --memory-limit=256M`. It reads `async_media` first.

With the scheduler (128M) the consumers stop at 768 MB of PHP memory together, and Compose restarts each one.

**Relay.** `RelayOutboxMessageMiddleware` runs on both buses. For a message received from `outbox` it picks the target in the `outbox_relay.target_transports` parameter (the class, then its parent classes, then its interfaces), sends it there and returns without handling it. A missing route, a route back to `outbox` or to no transport fails at once into `failed`. Delivery is at least once: the worker deletes the outbox row only after the send returned, so a crash between the two sends the event again after the Doctrine transport's `redeliver_timeout` (3600 s). Async handlers stay idempotent (ADR-0009). A message retried from `failed` is relayed the same way.

**Polling.** `use_notify` is off on `outbox` and `failed`. The worker sleeps only after a poll finds nothing, so an event waits at most one sleep plus one poll and one Valkey send. Messenger's default sleep of 1 s gives a worst case just over 1 s, which misses the 1 s target. At 0.5 s the worst case is about 0.5 s plus a few milliseconds, which meets it. The cost is two indexed polls a second on a nearly empty table.

**Retries.** ADR-0009 gave AI and Jira no numbers, so they get 5 retries from 30 s, times 3, capped at 15 min: about 35 minutes before a message goes to `failed`, so a provider's rate-limit window ends first. The jitter (up to 10 %) spreads retries after a shared outage. A handler that reads a `Retry-After` header can throw `RecoverableMessageHandlingException` with that delay.

**The DSN rule.** All four Valkey transports read `%env(dsn_without_stream:MESSENGER_TRANSPORT_DSN)%` and name their stream in `options.stream`. The Redis transport lets a stream in the DSN path (`redis://host:6379/messages`) or a `stream` query parameter override that option, which would put all four queues on one stream. `MESSENGER_TRANSPORT_DSN` therefore names no stream: no path after the host, and no `stream` query parameter. `DsnWithoutStreamEnvVarProcessor` throws on such a DSN when a transport is built: `worker` and `worker-heavy` fail at start, and every relay send fails until the event reaches `failed`.

### Consequences

- Good: a slow AI or media job never delays the relay or a notification.
- Good: AI and Jira back off instead of using up their retries in a minute.
- Bad: a slow Jira call can still delay notifications on `worker` by up to its HTTP timeout.
- Bad: two more containers to run and watch.
- Neutral: a crash mid-relay delays that event by up to an hour (the redeliver timeout).

## Confirmation

- `tests/shell/compose-config-check.sh` (CI `build` job) fails unless `worker-outbox`, `worker` and `worker-heavy` together consume each key of `framework.messenger.transports` except `failed` exactly once, `worker-outbox` consumes only `outbox`, those three and `scheduler` depend on `postgres`, and the three workers depend on `valkey-state`.
- `tests/Unit/Architecture/RetryStrategyConfigTest` pins each transport's retry numbers and jitter above.
- `tests/Unit/Architecture/AsyncTransportStreamsTest` sends each transport's options and the `.env.dev` DSN through the Redis transport's parser and expects four distinct streams. A DSN with a stream path merges them, and the guard refuses it.
- `tests/Unit/SharedKernel/Infrastructure/Messenger/DsnWithoutStreamEnvVarProcessorTest` covers the DSN rule.
- `tests/Unit/SharedKernel/Infrastructure/Messenger/RelayOutboxMessageMiddlewareTest` and `tests/Integration/Messaging/OutboxRelayTest` cover the relay, including a retry from `failed`.

## Pros and cons of the options

### ADR-0009 as written

- Good, because it has the fewest names.
- Bad, because AI and media jobs share one queue, and one worker serves everything.

### Four transports, one worker

- Good, because it is one container.
- Bad, because a 20 s AI call blocks the relay and notifications.

### Four transports, three workers

- Good, because the relay and short jobs never wait behind slow ones.
- Bad, because Jira sync shares a worker with notifications.

### One worker per transport

- Good, because every queue is isolated.
- Bad, because five consumers is too much for C-10's small VM.

## More information

- Related: [ADR-0009](0009-async-messaging-and-outbox.md), [ADR-0010](0010-cache-sessions-locks.md), [ADR-0018](0018-ai-provider-abstraction.md), [ADR-0025](0025-jira-integration.md), [operations.md](../02-architecture/operations.md#services).
- Revisit when: the outbox oldest-row age passes 1 s at peak, the `async_notify` queue age passes 5 s because of Jira sync (give `async_integration` its own worker), or AI or Jira messages reach `failed` more than once a day.
