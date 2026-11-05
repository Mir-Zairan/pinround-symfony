# ADR-0004 — Use PostgreSQL 18 as the only primary database

All business data of pinround lives in one PostgreSQL 18 database.

## Index

- [Status](#status) — accepted, date, deciders.
- [Context and problem statement](#context-and-problem-statement) — what the data needs.
- [Decision drivers](#decision-drivers) — tenancy, search, scale, ops.
- [Considered options](#considered-options) — PostgreSQL, MySQL/MariaDB, PostgreSQL plus a search engine.
- [Decision outcome](#decision-outcome) — features we use and how.
- [Confirmation](#confirmation) — how we check it.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — trade-offs.
- [More information](#more-information) — links and revisit triggers.

## Status

Accepted · 2026-11-06 · Deciders: Zairan Mir (lead).

## Context and problem statement

Mia reports a problem: "Planned total is too high". While she types, pinround must show
similar open tickets within a blink. Raj, the manager, opens Home and sees all teams
by the five groups. Sara, the admin, must be sure that Acme Foods never sees another
company's tickets, even if a developer forgets a filter.

We need one database that does tenant isolation, full-text and fuzzy search,
flexible settings, large append-only logs, and optional vector search, run by a small team.

## Decision drivers

- Tenant isolation inside the database, not only in PHP (NFR-SEC, [ADR-0005](0005-multi-tenancy.md)).
- Search and duplicate detection within 300 ms p95 (NFR-PERF) without a second system on day 1.
- Large logs (ticket events, audit events) that we can drop by month.
- Optional semantic duplicates with embeddings ([ADR-0022](0022-search-and-duplicates.md)).
- Managed offers exist on every cloud, and it is easy to self-host (C-self-host).

## Considered options

1. PostgreSQL 18 for everything.
2. MySQL 8 / MariaDB.
3. PostgreSQL plus a separate search engine (Meilisearch, OpenSearch) from day 1.

## Decision outcome

Chosen option: **PostgreSQL 18 for everything**, because it is the only option
with row-level security, strong full-text search, trigram search, JSONB and pgvector
in one engine.

Features we use:

| Feature | Used for |
|---|---|
| Row-level security (RLS) | Second wall for tenant isolation ([ADR-0005](0005-multi-tenancy.md)). |
| `tsvector` generated column + GIN index | Ticket search ([ADR-0022](0022-search-and-duplicates.md)). |
| `pg_trgm` | Fuzzy title match for duplicates and people search. |
| `citext` | Case-insensitive unique emails. |
| JSONB | Org settings, event payloads, image variants. Never for fields we filter on often. |
| Declarative partitioning by month | `tkt_ticket_event`, `aud_event`, `ntf_notification`; old months detach and drop. |
| `pgvector` (optional) | Embeddings for semantic duplicates when the org allows AI. |
| `uuid` type | UUIDv7 keys ([ADR-0006](0006-identifiers.md)). |

Rules:

- Doctrine ORM for writes in the domain. Plain DBAL SQL for read models and reports.
- Migrations with Doctrine Migrations, written expand/contract style ([ADR-0030](0030-deployment-model.md)).
- Connection pooling with PgBouncer in transaction mode in SaaS. This works with
  a transaction-local tenant setting (`set_config(..., true)`, see ADR-0005) used for RLS. Do not use session-level `SET`.
- Every index on a tenant table starts with `organization_id`.

### Consequences

- Good: one system to run, back up and secure.
- Good: search and duplicates need no sync job on day 1.
- Bad: PostgreSQL full-text search has weaker ranking and typo handling than a search engine.
- Bad: we are tied to PostgreSQL features (RLS, tsvector); moving to another DB is hard.
- Neutral: pgvector must be installed where AI duplicates are enabled.

## Confirmation

- Docker Compose and CI use the `postgres:18` image with `pg_trgm`, `citext` and `vector` extensions.
- A migration test checks that every table with `organization_id` has RLS enabled and forced.
- A schema test checks that every index on a tenant table starts with `organization_id`.
- k6 load test in P12 checks search p95 ≤ 300 ms at the scale design point.

## Pros and cons of the options

### PostgreSQL 18

- Good, because of RLS, FTS, trigram, JSONB, partitions and pgvector in one engine.
- Good, because managed versions exist everywhere.
- Bad, because FTS ranking is basic.

### MySQL / MariaDB

- Good, because many hosts support it.
- Bad, because there is no row-level security; isolation would rely only on PHP.
- Bad, because full-text and fuzzy search are weaker; no pgvector equivalent in the same engine.

### PostgreSQL + search engine from day 1

- Good, because ranking, typo tolerance and facets are better.
- Bad, because there is one more system to run, secure and keep in sync. Tenant isolation must be enforced twice.
- Bad, because it is not needed at our first scale.

## More information

- Data model: [data-model.md](../02-architecture/data-model.md).
- Revisit when: search p95 > 300 ms for 7 days at real load, or the database exceeds 2 TB,
  or one tenant needs data residency in another region.
