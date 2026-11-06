# Performance and scale

How pinround stays fast with thousands of tickets and screenshots per company, and how it grows when that is not enough.

## Index

- [Why speed matters here](#why-speed-matters-here) — the scene.
- [Capacity model](#capacity-model) — the design point and the math behind it.
- [Latency budgets](#latency-budgets) — server and browser targets.
- [Principles](#principles) — the rules every feature follows.
- [Hot paths](#hot-paths) — Home, Tickets list, ticket page, Whose turn, search, screenshot view: queries, indexes, caching.
- [Caching](#caching) — what is cached, keys, TTLs, invalidation.
- [Avoiding N+1 queries](#avoiding-n1-queries) — DTO queries, no lazy loading in views.
- [Pagination](#pagination) — keyset, not offset.
- [Read models](#read-models) — why "whose turn" is stored.
- [Async work](#async-work) — what leaves the request.
- [App server tuning](#app-server-tuning) — FrankenPHP worker mode.
- [Connection pooling](#connection-pooling) — PgBouncer and the transaction-local tenant setting.
- [Images, CDN and HTTP caching](#images-cdn-and-http-caching) — variants and headers.
- [Frontend budgets](#frontend-budgets) — LCP, INP, JS size.
- [Scaling steps](#scaling-steps) — what we do next, and when.
- [Load test plan](#load-test-plan) — k6 scenarios and pass criteria.

## Why speed matters here

Omar opens pinround in the morning. Home shows "12 things wait on you". He clicks the first one, answers a question, presses "Next ticket", approves a code review, and moves on. If each step takes two seconds, he gives up on the queue and goes back to chat messages. pinround only works if moving through work feels instant. So every main screen has a time budget, and every feature is designed against it.

## Capacity model

This is the design point for headroom, not the day-one load (see [non-functional-requirements.md](../01-requirements/non-functional-requirements.md)).

| Item | Design point | Notes |
|---|---|---|
| Organizations | 500 | |
| Users | 50,000 | ~100 per org on average |
| Largest org | 2,000 people, 200,000 tickets | drives index and query design |
| Tickets | 2,000,000 | ~4,000 per org on average |
| Screenshots | 20,000,000 | ~10 per ticket on average |
| Requests | 50/s average, 500/s peak | |

Storage math:

- Screenshots: 20 M × ~300 KB (WebP after re-encode) ≈ **6 TB** in object storage. Thumbnails add ~20 M × 25 KB ≈ 0.5 TB.
- Ticket rows: 2 M × ~2 KB (text plus tsvector) ≈ 4 GB plus indexes ≈ 10 GB.
- Ticket events (history): ~30 events per ticket ⇒ 60 M rows × ~300 B ≈ 18 GB per lifetime, partitioned by month.
- Comments: ~8 per ticket ⇒ 16 M rows ≈ 8 GB.
- Audit events: ~50 per user per month ⇒ 2.5 M per month, partitioned by month, purged by retention.

Conclusion: the relational data fits comfortably on one well-sized PostgreSQL server (tens of GB). The images are the big part, and they live in object storage, not in the database.

Request math: 500 req/s peak with ~20 ms of PHP time each needs ~10 busy PHP workers. With FrankenPHP worker mode and some margin, 2–3 app containers with 16 workers each cover the peak.

## Latency budgets

Server time, p95, warm cache, measured at the app (see [ADR-0029](../03-adr/0029-observability.md) for how):

| Screen or action | Budget | Max SQL queries |
|---|---|---|
| Home | ≤ 250 ms | 8 |
| Tickets list (50 rows) | ≤ 200 ms | 5 |
| Ticket page | ≤ 250 ms | 10 |
| Search | ≤ 300 ms | 3 |
| Thumbnail first byte (object storage/CDN) | ≤ 150 ms | 0 |
| Action (move, answer, ping) | ≤ 150 ms | 6 writes, side effects async |

Browser: LCP ≤ 2.0 s on a mid laptop, INP ≤ 200 ms, JavaScript ≤ 120 KB gzip per page.

The query counts are enforced in tests (see [testing-strategy.md](testing-strategy.md)).

## Principles

1. **Read models for questions people ask all day.** "Whose turn is it?" and "what needs me?" are stored, not computed per request.
2. **One query per list.** Views get DTOs from explicit queries. No Doctrine lazy loading in templates.
3. **Every index starts with `organization_id`.** It matches RLS and keeps scans inside one company.
4. **Side effects leave the request.** Notifications, AI, image processing, Jira sync and emails run in workers.
5. **Measure before optimizing.** Budgets are checked in CI with a seeded dataset, and in production with traces.

## Hot paths

### Home

What it shows: "Needs you now" (top 5), "Also waiting on you" (grouped), pings, release bar, team stack, focus chips, admin card. See [prototype-guide.md](../04-prototype/prototype-guide.md).

Query plan:

1. Top 5: two index scans on `ins_ticket_turn` combined with `UNION ALL` (my turns; my checks), each ordered by `base_score`. Because the time part of the score is at most 30 points, only rows with `base_score ≥ (5th best base_score − 30)` can reach the top 5, so the query reads a few dozen rows, never all of a lead's turns. Exact SQL: [data-model.md › Query patterns](data-model.md#query-patterns). The visibility predicate is applied in the same query.
1b. "Also waiting on you" counts: one separate `GROUP BY kind` count query (no limit can cut the counts). Group items load when a group is opened.
2. Ticket title, key and priority come from a join to `tkt_ticket` by primary key. This join happens inside the Insight query service, which reads Ticketing's public view `tkt_ticket_list_v` (a read-only SQL view owned by Ticketing). That keeps module boundaries while staying at one round trip.
3. Pings: one grouped query on `ntf_ping` where `to_id = :me and cleared_at is null`, index `(organization_id, to_id, cleared_at)`.
4. Release bar and team stack: cached per team (see [Caching](#caching)).

Result: ≤ 8 queries (the Home budget), all index-only or index-range scans.

### Tickets list (grouped by step)

- Filter by project, person, search, "show done", "to check".
- Indexes: see [data-model.md › Index catalogue](data-model.md#index-catalogue) (one project, or "All projects" of a team).
- Default hides done steps (most rows), so the scan stays small.
- **One query for all steps:** a `LATERAL` subquery takes the first 51 rows of each step (50 shown + 1 to know there is more), so the page stays within its ≤ 5-query budget. "Show more" in one step uses keyset pagination for that step only.
- Project rail counts come from the read model `ins_project_counts`, not from `count(*)` on tickets.
- "Turn:" and "To check by" come from the joined `ins_ticket_turn` row (same query).

### Whose turn view

- `select turn_user_id, count(*), min(waiting_since) ... group by turn_user_id` on `ins_ticket_turn`, with the visibility predicate (so outsiders never count team-only tickets). Index: `(organization_id, team_id, turn_user_id, waiting_since)`, or `(organization_id, project_id, …)` when a project is picked — both in the [Index catalogue](data-model.md#index-catalogue).
- Then the top 5 tickets per person with a `LATERAL` join, which uses the same index.

### Ticket page

- One query for the ticket DTO (ticket + project + step + turn).
- One query for thread messages with replies (ordered by `created_at`, index `(organization_id, ticket_id, created_at)`).
- One query for rounds, screenshots and pins (a single join tree, ordered).
- One query for people (assignee, reporter, reviewer, tester) by ids.
- One query for the last 6 activity events (partitioned table, index `(organization_id, ticket_id, at desc)`).
- Summary: current summary row by `(ticket_id, is_current)`.

Target: ≤ 10 queries, none of them scans.

### Search

- `tkt_ticket.search` is a generated `tsvector` column (title weight A, description weight B) with a GIN index.
- Ticket keys (`SHOP-123`) are matched exactly first.
- `pg_trgm` GIN index on `title` for typo-tolerant matching and for duplicate suggestions while typing.
- Always filtered by `organization_id` and by the [ticket visibility predicate](data-model.md#ticket-visibility-predicate).
- **RLS caveat:** `@@`, `%`, `similarity()` and pgvector operators are not `LEAKPROOF`, so under row-level security the planner may refuse the GIN/HNSW index and scan the whole org. A CI test runs `EXPLAIN` as `pinround_app` with the tenant set and fails if the index is not used; the fallback is a `SECURITY DEFINER` search function with an explicit org filter ([data-model.md › Query patterns](data-model.md#query-patterns)).
- Duplicates: take 20 candidates by rank, apply the FR-INT-15 word rule, keep the top 3.
- See [ADR-0022](../03-adr/0022-search-and-duplicates.md).

Duplicate suggestions while typing are debounced (400 ms), need at least 8 characters, and return the top 3 (after the word rule on 20 candidates).

### Screenshot view

- The page HTML holds signed URLs for thumbnails (valid 5 minutes). Full images load on click or when the review canvas opens.
- Object storage (or a CDN in front of it) serves the bytes. The PHP app never streams image bytes.
- Pins are rendered from DB data as HTML elements over the image, so they cost no image processing.

## Caching

All cache keys start with the org id. Pools are tag-aware (Symfony Cache with Valkey; see [ADR-0010](../03-adr/0010-cache-sessions-locks.md)).

| What | Key | TTL | Invalidated by tag |
|---|---|---|---|
| Steps and rules for a team (workflow config) | `org:{o}:wf:team:{t}` | 1 day | `wf-team-{t}` on any step/rule/policy change |
| Release progress bar | `org:{o}:rel:{team}:{release}` | 5 min | `ticket-team-{t}` on move |
| Team stack and workload counts | `org:{o}:load:{team}` | 2 min | `turn-team-{t}` on turn change |
| Manager team overview | `org:{o}:mgr` | 5 min | `turn-org-{o}` |
| User permissions snapshot (roles, memberships) | `org:{o}:perm:{u}` | 10 min | `perm-user-{u}` on role change |
| Prompt package / summary by content hash | `org:{o}:ai:{hash}` | 30 days | none (content-addressed) |
| Signed URLs | not cached server-side | — | — |

Rules:

- Never cache a rendered page that contains restricted tickets for more than one user. Fragments are cached per user or not at all.
- Cache stampede protection: Symfony Cache's probabilistic early expiration (verify the option name at implementation time) or a Lock for heavy rebuilds.

## Avoiding N+1 queries

- Controllers and Live Components call **query services** that return read-only DTOs (built with DQL `NEW` or native SQL).
- Entities are used for writes only. Templates never receive entities.
- A test helper counts queries per request (Doctrine middleware). Each hot path has a test with a max query count. The build fails above the limit.
- Collections that a page needs are loaded with one `IN (...)` query, never in a loop.

## Pagination

- Keyset (seek) pagination everywhere a list can grow: `WHERE (updated_at, id) < (:lastUpdated, :lastId) ORDER BY updated_at DESC, id DESC LIMIT 50`.
- No `OFFSET` beyond the first page.
- UUIDv7 ids are time-ordered, so `id` works as a stable tie-breaker.

## Read models

`ins_ticket_turn` holds one row per open ticket: `turn_user_id`, `turn_label` (for non-users like "Customer"), `kind` (answer, check, review, test, assign, input, decide, build), `base_score`, `waiting_since`, `group`, `team_id`, `check_user_id`.

- Event handlers in the Insight module update it **in the same transaction** as the change (synchronous Messenger bus inside the unit of work), so Home is never out of date after your own action. See [ADR-0015](../03-adr/0015-turn-read-model.md).
- A nightly job rebuilds rows that look inconsistent (checksum on the ticket's event count) as a safety net.
- The time-based part of the score is computed in SQL at read time, so no job has to update scores every hour.

## Async work

Runs in Messenger workers (see [ADR-0009](../03-adr/0009-async-messaging-and-outbox.md)):

| Work | Transport | Typical time |
|---|---|---|
| Image verify, re-encode, thumbnails | `media` (Valkey) | 200–800 ms per image |
| AI summaries, suggestions, embeddings | `ai` (Valkey), low concurrency per org | 1–20 s |
| Notifications fan-out, Mercure publish, emails | `notify` (Valkey) | < 100 ms |
| Jira sync | `integration` (Valkey), rate-limited | varies |
| Domain events to other modules | outbox (PostgreSQL, same transaction) → relay | < 1 s |

Queues are separate, so a slow AI provider never delays notifications. Alerts fire when the oldest message in a queue is older than its limit (see [operations.md](operations.md)).

## App server tuning

FrankenPHP worker mode ([ADR-0008](../03-adr/0008-frankenphp-runtime.md)) boots the Symfony kernel once per worker and reuses it.

- Workers per container: start at 2 × CPU cores; tune with load tests.
- Restart a worker after N requests (for example 500) to contain memory leaks. Verify the FrankenPHP setting name at implementation time.
- Services must be stateless between requests: no request data in service properties. Use `ResetInterface` for services that hold state. A test runs two requests with different users through the same kernel and checks nothing leaks.
- OPcache with preloading on, JIT off by default (measure before turning on).
- Doctrine metadata and query caches in the system cache (APCu or PHP files), never rebuilt per request.

## Connection pooling

- PgBouncer in **transaction** pooling mode in front of PostgreSQL when we run more than ~100 app connections.
- Tenant context uses `SELECT set_config('app.org_id', $1, true)` inside each transaction (transaction-local like `SET LOCAL`, but with a bound parameter). It ends with the transaction, so a pooled connection never carries one org's context into another org's request. Plain `SET` and `set_config(…, false)` are forbidden (a static check looks for them). The RLS policy uses `NULLIF(current_setting('app.org_id', true), '')::uuid`, because a pooled connection reads an old transaction-local setting back as `''`.
- Every request that reads tenant data runs inside a transaction (a request-level read transaction for GET pages; Doctrine's `wrapInTransaction` for writes).
- Server-side prepared statements are off on every DBAL connection: `config/packages/doctrine.yaml` sets `Pdo\Pgsql::ATTR_DISABLE_PREPARES` to `true`. Bound values still travel separately from the SQL, but in one unnamed-statement round trip (libpq `PQexecParams`) instead of `PREPARE`, `EXECUTE` and `DEALLOCATE`, so `set_config` costs one round trip per transaction and no named statement outlives a transaction under PgBouncer transaction pooling. `DatabaseConnectionSetupTest` checks that a bound query leaves no row in `pg_prepared_statements`.

## Images, CDN and HTTP caching

- Variants: `thumb` (max 480 px wide, WebP q≈75), `view` (max 1920 px wide, WebP q≈85), `original` kept only if the org's policy needs it (default: keep `view` as the master after re-encode).
- Signed GET URLs valid 5 minutes. The browser caches the image bytes privately (`Cache-Control: private, max-age=300`).
- A CDN may sit in front of the bucket only if it supports signed URLs per request (verify the provider). Otherwise serve from the bucket directly.
- Static assets from AssetMapper have content hashes in file names: `Cache-Control: public, max-age=31536000, immutable`.
- HTML pages: `Cache-Control: private, no-store` for anything with tenant data.
- Brotli/gzip by Caddy (FrankenPHP).

## Frontend budgets

- Server-rendered HTML with Turbo, so navigation sends HTML fragments, not big JSON plus client rendering. See [frontend.md](frontend.md).
- JS: Turbo + Stimulus + Live Components + a few controllers (pin canvas, upload, laptop AI). Budget 120 KB gzip.
- Fonts: Atkinson Hyperlegible Next self-hosted, WOFF2, subset, `font-display: swap`, preload the two weights used most.
- Images: `width`/`height` attributes on every image to avoid layout shift; `loading="lazy"` below the fold.
- Live Components: debounce text inputs (300–400 ms); avoid re-rendering whole lists on every keystroke.

## Scaling steps

We grow in this order and only when a trigger fires:

| Step | Trigger | Action |
|---|---|---|
| 1. Vertical | DB CPU > 60% at peak for a week, or p95 over budget with good plans | Bigger PostgreSQL instance, more app containers |
| 2. Pooling | > 100 active DB connections | PgBouncer transaction mode |
| 3. Read replica | Read share > 80% and primary CPU still high | Route Home/list/search reads to a replica; your own writes still read from the primary for 2 s ("read your writes") |
| 4. Partitioning | `tkt_ticket_event`, `aud_event`, `ntf_notification` > 100 M rows | Already partitioned by month from day 1; add partition pruning checks and detach old partitions per retention |
| 5. Separate search | Search p95 > 300 ms on the largest org with good indexes | Add Meilisearch or OpenSearch fed by outbox events; Postgres FTS stays as fallback |
| 6. Split a module out | One module's load or deploy cadence clearly differs (likely AI or media workers) | Run its workers on separate nodes first; a separate service only if that is not enough |

## Load test plan

Tool: k6 (phase 12, with a smaller smoke run in CI from phase 3). Dataset: seeded with the "largest org" shape (2,000 users, 200,000 tickets, 2 M screenshots metadata, images stubbed).

| Scenario | Mix | Pass criteria |
|---|---|---|
| Morning rush | 60% Home, 25% ticket page, 10% list, 5% actions; ramp to 500 req/s | All p95 budgets met; error rate < 0.1% |
| Review session | Open ticket, load 10 thumbnails, set 10 verdicts | Verdict p95 ≤ 150 ms; thumbnail first byte ≤ 150 ms |
| Intake burst | 50 reports/min with duplicate suggestions while typing | Suggestion p95 ≤ 300 ms |
| Search heavy | 100 searches/s on the largest org | p95 ≤ 300 ms |
| AI backlog | 1,000 summary jobs queued | Notifications queue age stays < 5 s (queues isolated) |
| Soak | 2 h at 50 req/s | No memory growth in workers; no connection leaks |
