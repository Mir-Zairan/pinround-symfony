# ADR-0010 — Valkey for cache, sessions, locks, rate limits and job queues

We use Valkey 8 (an open-source, Redis-compatible store) for all fast shared state, split by eviction policy.

## Index

- [Status](#status) — accepted, date, deciders.
- [Context and problem statement](#context-and-problem-statement) — what needs fast shared state.
- [Decision drivers](#decision-drivers) — speed, safety, licence.
- [Considered options](#considered-options) — database only, Memcached, Redis, Valkey.
- [Decision outcome](#decision-outcome) — two instances, uses, key rules.
- [Confirmation](#confirmation) — config checks and tests.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — trade-offs.
- [More information](#more-information) — links and revisit triggers.

## Status

Accepted · 2026-11-06 · Deciders: Zairan Mir (lead).

## Context and problem statement

Lena pings Omar about SHOP-42. One minute later she pings again. The second ping must be
blocked by the rate limit, on any app server. Mia logs in on two app servers behind a load
balancer; her session must work on both. Two Jira sync workers must not import the same
project at the same time. And Home counts should come from a cache, not be recomputed on every load.

Where do we keep sessions, locks, rate-limit counters, caches and job queues?

## Decision drivers

- Shared across all app and worker processes (NFR-SCAL: several app servers).
- Low latency (sub-millisecond) for session and rate-limit reads (NFR-PERF).
- Sessions and locks must never be evicted under memory pressure (NFR-REL, NFR-SEC).
- Open-source licence that self-hosters can use freely.

## Considered options

1. PostgreSQL only (sessions table, advisory locks, cache table).
2. Memcached for cache + PostgreSQL for the rest.
3. Redis.
4. Valkey 8 (BSD-licensed fork of Redis, same protocol).

## Decision outcome

Chosen option: **Valkey 8**, run as **two separate instances** with different eviction rules:

| Instance | `maxmemory-policy` | Persistence | Used for |
|---|---|---|---|
| `valkey-state` | `noeviction` | AOF on (everysec) | Sessions, Lock component, RateLimiter, Messenger queues |
| `valkey-cache` | `allkeys-lru` | off | Symfony cache pools (tag-aware), HTTP fragment cache |

Why two: if one instance used LRU eviction, a full cache could evict sessions, locks or
queued jobs. With `noeviction`, a full cache would make writes fail. Separate instances avoid both.

Key rules:

- Every key starts with the org id where the data is tenant data: `org:{uuid}:…`.
- Cache values never hold data a user may not see unless the key includes the access scope
  (for example a per-user Home fragment key `org:{o}:user:{u}:home:v{n}`).
- Cache invalidation by tags (`ticket:{id}`, `team:{id}`) from event handlers.
- Default TTLs: cache 10 minutes max; sessions 8 hours idle, 30 days absolute ([ADR-0012](0012-authentication.md)); locks have TTLs.
- TLS and a password (ACL user per app role) in SaaS; not reachable from the internet.

Symfony uses this through its standard Redis adapters (cache, session handler, Lock store,
RateLimiter storage, Messenger Redis transport). Valkey speaks the same protocol; verify
adapter compatibility at implementation.

### Consequences

- Good: fast shared state for all processes; standard Symfony integrations.
- Good: a BSD licence, so it is fine for self-hosted installs.
- Bad: two more containers to run (small).
- Bad: the cache can serve slightly stale data; invalidation by tags must be correct.
- Neutral: a managed Redis-compatible service can replace it in SaaS.

## Confirmation

- A startup check (health endpoint, P00) asserts `maxmemory-policy` is `noeviction` on `valkey-state`.
- Integration tests for the rate limiter, lock and session handler run against Valkey in CI.
- Tenant-isolation tests include a cache test: user of org B never gets a cached fragment of org A.

## Pros and cons of the options

### PostgreSQL only

- Good, because it is one less system.
- Bad, because sessions and rate-limit writes on every request add DB load; queues on the DB compete with business writes.

### Memcached + PostgreSQL

- Good, because it is a simple, fast cache.
- Bad, because it has no persistence, no streams, and weak locks; queues and sessions still need another store.

### Redis

- Good, because it is the standard and has the same features.
- Bad, because of the licence change in 2024 (verify current terms); self-hosters may object.

### Valkey 8

- Good, because it is Redis-compatible with an open BSD licence, and has all the features we need.
- Bad, because it is a younger project; some managed clouds name it differently.

## More information

- Revisit when: `valkey-state` memory passes 70% of its limit, or sessions/rate-limit reads p95 exceed 5 ms.
