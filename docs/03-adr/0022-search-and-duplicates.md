# ADR-0022: Search and duplicate detection use PostgreSQL full-text search and trigrams; embeddings are optional

We find tickets and likely duplicates inside PostgreSQL. There is no separate search engine to run.

## Index

- [Status and people](#status-and-people): status, date, deciders.
- [Context and problem statement](#context-and-problem-statement): the scene: duplicate reports.
- [Decision drivers](#decision-drivers): speed, tenant safety, ops cost.
- [Considered options](#considered-options): Postgres FTS + trigram, Meilisearch/Elasticsearch, embeddings only.
- [Decision outcome](#decision-outcome): columns, indexes, duplicate query, optional pgvector.
  - [Consequences](#consequences): good, bad and neutral effects.
- [Confirmation](#confirmation): tests and budgets.
- [Pros and cons of the options](#pros-and-cons-of-the-options): details per option.
- [Clarifications (2026-11-06)](#clarifications-2026-11-06): indexes under RLS, candidate count, visibility.
- [Clarifications (2026-11-12)](#clarifications-2026-11-12): index shape, the search function in use.
- [Clarifications (2026-11-19)](#clarifications-2026-11-19): duplicates use the search function too.
- [More information](#more-information): links and when to revisit.

## Status and people

- Status: Accepted
- Date: 2026-11-06
- Deciders: Zairan (lead), Toyib (developer), Anka (tester)

## Context and problem statement

Mia types "Order total doubled on checkout" in "Report a problem". SHOP-17 "Checkout: order total counted twice for split deliveries" already exists. pinround should show SHOP-17 under the title field before Mia sends, so she can "Add my report there". Lena also searches the Tickets list by key or words. One org may have 200,000 tickets. Search must never return another org's tickets, or tickets the user may not see.

## Decision drivers

- p95 search at most 300 ms, and duplicate suggestions while typing at most 150 ms server time.
- Tenant isolation and visibility rules apply to search exactly like lists (ADR-0005, ADR-0013).
- No extra infrastructure for small self-hosted orgs.
- Works without AI. AI improves it when the org allows.

## Considered options

1. **PostgreSQL FTS + pg_trgm, optional pgvector embeddings (chosen).**
2. **Separate search engine** (Meilisearch or Elasticsearch/OpenSearch) fed by events.
3. **Embeddings only** (semantic search for everything).

## Decision outcome

Chosen option: **1**.

- **Columns:** `tkt_ticket.search` is a generated `tsvector` column built from title (weight A), key (A) and description (B), using the `simple` configuration plus `unaccent`. A language-specific configuration can be set per org later. Index: GIN on `(search)`. Every query also filters `organization_id`, and RLS applies.
- **Titles:** GIN index with `gin_trgm_ops` on `title` for fuzzy matching (typos, word order).
- **Search query:** `websearch_to_tsquery`, ranked with `ts_rank_cd`. An exact key match (`SHOP-17`) is returned first. Keyset pagination is used.
- **Duplicate candidates** while typing:
  - Debounced at 300 ms in the browser.
  - Open tickets only, in the same org, visible to the user.
  - Filtered by `search @@ query OR similarity(title, :title) > 0.3`.
  - Ordered by a combined score (FTS rank × 0.6 + trigram similarity × 0.4).
  - Top 3, title at least 8 characters.
  - The exact thresholds are tuned with a test set, and the values live in config.
- **Semantic duplicates (optional):** when the AI policy allows it and the org has the `pgvector` extension, the Assist module stores an embedding per ticket (title + short summary) through the Symfony AI Store (check the Postgres store bridge at implementation time). Candidates from vectors are merged with the FTS ones. Embedding jobs run async (ADR-0009).
- **Restricted tickets:** visibility filters are applied in the same SQL. They are never post-filtered in PHP after a `LIMIT`, because that would leak counts and break paging.

### Consequences

- Good: one database, transactional freshness (a new ticket is searchable at once), and simple backups.
- Good: the same RLS protects search.
- Bad: FTS ranking is weaker than a dedicated engine for long texts and many languages.
- Bad: trigram indexes grow with titles. This is fine at 2M tickets.
- Neutral: embeddings add storage (for example 384 floats × 2M ≈ 3 GB) only for orgs that turn them on.

## Confirmation

- Tests: search as a user outside the team never returns `visibility = team` tickets, and never returns tickets from another org (tenant suite).
- Duplicate test set: 50 synthetic report/ticket pairs. Precision of top 3 at least 0.7 for FTS + trigram. Measured in CI as a report, not a hard gate at first.
- k6 load test (phase 12): p95 search at most 300 ms with 200k tickets in one org.
- EXPLAIN checks in integration tests: both queries use the GIN indexes (no seq scan on `tkt_ticket` with 50k seeded rows).

## Pros and cons of the options

### 1. Postgres FTS + trigram

- Good, because there are no new services and it is always consistent.
- Bad, because ranking is weaker than a dedicated engine.

### 2. Separate search engine

- Good, because ranking, typo tolerance and facets are better.
- Bad, because it is another service to secure, plus sync lag and a second place where tenant filters can fail.

### 3. Embeddings only

- Good, because it finds meaning, not just words.
- Bad, because it needs AI, which some orgs turn off. Exact keys and words match badly, and it costs money and time.

## Clarifications (2026-11-06)

- **Indexes under RLS.** `@@`, `%`, `similarity()` and pgvector distance operators are not `LEAKPROOF`. Under row-level security the planner may then skip the GIN/HNSW index and scan the whole org. A CI `EXPLAIN` test, run as `pinround_app` with the tenant set, fails if the index is not used. Fallback: a `SECURITY DEFINER` search function (owner without login, fixed `search_path`) that takes the org and visibility inputs as explicit arguments, filters on them first, and returns ticket ids only ([data-model.md › Query patterns](../02-architecture/data-model.md#query-patterns)).
- **Candidates before the rule.** Duplicate suggestions take the top 20 candidates by rank, apply the FR-INT-15 word rule, then keep the top 3. Taking 3 first could return none after the rule.
- **Visibility.** Search and duplicates use the canonical [ticket visibility predicate](../02-architecture/data-model.md#ticket-visibility-predicate) inside the SQL. Embeddings are never made for team-only tickets unless the admin allows cloud AI for them.

## Clarifications (2026-11-12)

- **Index shape.** The GIN indexes lead with `organization_id`, as every index does, through the `btree_gin` extension: `(organization_id, search)` and `(organization_id, title gin_trgm_ops)` (see data-model.md's [index catalogue](../02-architecture/data-model.md#index-catalogue)).
- **The fallback is in use for search.** `TicketSearchQueryPlanTest` showed the planner skips both GIN indexes under RLS and uses them without it. Search therefore runs in `search_ticket_ids`, the `SECURITY DEFINER` function the 2026-11-06 clarification describes ([data-model.md › Query patterns](../02-architecture/data-model.md#query-patterns)). It returns at most 1,000 ticket ids, best first, after the visibility filter. Duplicate candidates still run as `pinround_app` and still skip the indexes; P06 decides their function.

## Clarifications (2026-11-19)

- **The fallback is in use for duplicates too.** The duplicate candidates of Report a problem come from `search_ticket_ids` with the open steps only and no project, joined back under RLS and the visibility predicate (`IntakeCandidateQueries::similarOpenTickets`). No query reads the GIN indexes as `pinround_app` any more, so `TicketSearchQueryPlanTest` explains the function only.

## More information

- Related: [ADR-0004](0004-postgresql.md), [ADR-0005](0005-multi-tenancy.md), [ADR-0016](0016-direct-ticket-creation-with-check-flags.md), [ADR-0018](0018-ai-provider-abstraction.md).
- Architecture: [data-model.md](../02-architecture/data-model.md), [performance-and-scale.md](../02-architecture/performance-and-scale.md).
- Revisit when search p95 exceeds 300 ms for 2 weeks after index tuning, or when users need search across comments and screenshots' OCR text.
