# Operations

How pinround is run: environments, deployment, backups, monitoring and the jobs that keep it healthy.

## Index

- [Why operations are part of the design](#why-operations-are-part-of-the-design) — the scene.
- [Environments](#environments) — local, CI, staging, production.
- [Services](#services) — the Docker Compose stack and what each part does.
- [Configuration](#configuration) — env vars, secrets, feature flags.
- [Deploy pipeline](#deploy-pipeline) — from merge to production.
- [Zero-downtime migrations](#zero-downtime-migrations) — expand and contract.
- [Backups](#backups) — PostgreSQL PITR, bucket versioning, Valkey.
- [Restore drill](#restore-drill) — proving backups work.
- [Observability](#observability) — traces, logs, metrics, health endpoints, SLOs, alerts.
- [Scheduled jobs](#scheduled-jobs) — retention, digests, partitions, rebuilds.
- [Capacity alarms](#capacity-alarms) — when to grow.
- [Runbooks](#runbooks) — the list of written procedures.

## Why operations are part of the design

Sara is the admin at a 40-person company. She installs pinround on one server with Docker Compose on a Friday. On Monday the team uses it. Six months later a disk fails. Sara restores last night's backup plus the write-ahead log and loses at most 15 minutes of work. Small companies cannot afford a platform team, so pinround must be easy to run, easy to back up, and loud when something is wrong. See [ADR-0030](../03-adr/0030-deployment-model.md).

## Environments

| Environment | Purpose | Data |
|---|---|---|
| Local | Development with Docker Compose | Neutral fixtures (Acme Foods) |
| CI | Tests and gates on every push | Fresh database per run |
| Staging | Release candidate, load tests, restore drills | Generated large dataset, never real customer data |
| Production (SaaS) | Customers | Real data |
| Self-hosted | A company runs its own copy | Their data, their operator |

All use the same container image. Only configuration differs.

## Services

Docker Compose for local and small self-hosted installs:

| Service | Image / tech | Role |
|---|---|---|
| `app` | FrankenPHP + pinround | Web, API, MCP endpoint, built-in Mercure hub ([ADR-0008](../03-adr/0008-frankenphp-runtime.md)) |
| `worker-outbox` | same image, `messenger:consume outbox --sleep=0.5` | Outbox relay only: moves domain events to their Valkey transport ([ADR-0033](../03-adr/0033-valkey-transports-workers-and-outbox-relay.md)) |
| `worker` | same image, `messenger:consume async_notify async_integration` | Short async jobs: notifications, Mercure, email, Jira sync ([ADR-0033](../03-adr/0033-valkey-transports-workers-and-outbox-relay.md)) |
| `worker-heavy` | same image, `messenger:consume async_media async_ai` | Slow async jobs: screenshot processing, AI ([ADR-0033](../03-adr/0033-valkey-transports-workers-and-outbox-relay.md)) |
| `scheduler` | same image, Symfony Scheduler | Scheduled jobs (see below) |
| `postgres` | PostgreSQL 18 with `pg_trgm`, `pgvector` (optional) | Primary data ([ADR-0004](../03-adr/0004-postgresql.md)) |
| `valkey` | Valkey 8 | Cache, sessions, locks, rate limits, job queues ([ADR-0010](../03-adr/0010-cache-sessions-locks.md)) |
| `minio` | MinIO (self-host) | Object storage for screenshots ([ADR-0011](../03-adr/0011-screenshot-storage-and-processing.md)); built from pinned source in `docker/minio` ([ADR-0031](../03-adr/0031-minio-built-from-pinned-source.md)) |
| `mailpit` | Mailpit (local only) | Catch outgoing email |
| `ollama` | optional | Server-side AI for orgs that want one shared box |

SaaS swaps `postgres`, `valkey` and `minio` for managed services (managed PostgreSQL with PITR, managed Valkey/Redis, S3 or R2) and runs `app`, `worker-outbox`, `worker`, `worker-heavy` and `scheduler` on a container platform (Kubernetes optional).

Containers run as a non-root user, with a read-only root file system where possible, and health checks.

## Configuration

- Twelve-factor style: everything through environment variables, documented in `.env` (defaults, no secrets) and [`docs/operations/config.md`](../operations/config.md).
- Secrets: Symfony secrets vault per environment, or the platform's secret store injected as env vars. Never in the repo ([ADR-0027](../03-adr/0027-secrets-and-encryption.md)). The variables the prod entrypoint guards (`APP_SECRET`, the Mercure keys, `FIELD_ENCRYPTION_KEYS`, `FIELD_ENCRYPTION_CURRENT_KEY_ID`, `OBJECT_STORAGE_SECRET_ACCESS_KEY`) must be plain env vars, because the guard reads only the environment ([config.md › Rules for prod](../operations/config.md#rules-for-prod)).
- Database roles: `app`, `worker-outbox`, `worker`, `worker-heavy` and `scheduler` get a `DATABASE_URL` for `pinround_app`. The `migrate` container gets its own `DATABASE_URL` for `pinround_migrator`; prod has no second connection. Dev and test add `MIGRATOR_DATABASE_URL` (in `.env.dev` and `.env.test`), which the `migrator` Doctrine connection uses for migrations. Test alone adds `READONLY_DATABASE_URL` (in `.env.test` and on the CI test steps) for a `readonly` connection that tests use to prove what `pinround_readonly` is refused. The `pinround_retention` credentials go only to the retention job and the `pinround_readonly` ones only to support tooling and that test connection, never into the web or worker env. The admin account runs `docker/postgres/roles.sql` at install, before the first migration, and again after an upgrade that changes it. The script grants no table rights. Each table's migration grants `pinround_app` and `pinround_readonly` their rights explicitly, and child partitions are never granted ([data-model.md › Row-level security](data-model.md#row-level-security)).
- Database connection: every Doctrine connection turns server-side prepares off (`Pdo\Pgsql::ATTR_DISABLE_PREPARES`), so PgBouncer transaction pooling is safe and a bound query is one round trip. Every main web request runs in one transaction (except on a route whose `_request_transaction` default is `false`, today only the health routes) and the tenant setting lasts only for it, so streamed response bodies, which run after the commit, see no tenant rows. Never add an ORM result cache for tenant data without a tenant-aware key ([data-model.md › Row-level security](data-model.md#row-level-security)).
- Feature flags: a small org-level flag table for risky features (laptop AI, vision pins, Jira comment copy), so we can turn them off per org without a deploy.

## Deploy pipeline

1. Pull request: all gates green ([testing-strategy.md](testing-strategy.md)).
2. Merge to `main`: build image, scan it, sign it, attach SBOM, push to registry.
3. Deploy to staging automatically; run smoke e2e tests.
4. Promote to production by hand (button or tag). Rolling update: new containers start, pass readiness checks, old ones drain.
5. Migrations run as a separate job **before** the new code starts, and must be backward-compatible with the running version (see below).
6. Workers restart after the web containers; messages in flight are retried.

Rollback: redeploy the previous image. Because migrations are expand-only during a release, the old code still works.

## Zero-downtime migrations

Expand and contract, always in two releases:

1. **Expand:** add new columns/tables (nullable or with defaults), new indexes `CONCURRENTLY`, write to both old and new.
2. **Migrate data** in a background job in batches.
3. **Switch reads** to the new shape.
4. **Contract** in a later release: drop old columns.

Rules: no long table locks; `lock_timeout` set in migrations; new tenant tables must have RLS policies in the same migration (a test checks this).

## Backups

| What | How | Kept |
|---|---|---|
| PostgreSQL | Continuous WAL archiving + daily base backup (managed PITR in SaaS; pgBackRest or WAL-G for self-host, choose in phase 12) | 30 days of PITR; monthly snapshot 12 months |
| Object storage | Bucket versioning + replication to a second region/bucket | Deleted versions 30 days |
| Valkey | Not backed up as a source of truth. Sessions and cache can be lost; queues are rebuilt from the outbox | — |
| Config and secrets | Platform secret store with its own backup | — |

Targets: RPO ≤ 15 minutes, RTO ≤ 4 hours (see [non-functional-requirements.md](../01-requirements/non-functional-requirements.md)).

## Restore drill

Every quarter on staging:

1. Restore PostgreSQL to a point in time.
2. Point a copy of the app at the restored DB and bucket versions.
3. Run the smoke tests and check a sample of screenshots opens.
4. Write down the time it took. If it is over the RTO, fix the process.

## Observability

See [ADR-0029](../03-adr/0029-observability.md).

- **Traces:** hand-written OpenTelemetry spans in `src/SharedKernel/Infrastructure/Tracing/`, exported over OTLP/HTTP. A root span covers each main web request. A DBAL middleware adds one span per statement, named by query kind only (`SELECT`, `INSERT`, `BEGIN`, `COMMIT` and so on), never with SQL text or values. Messenger adds a process span per handled message and a send span per sent one. AI and storage call spans are not built yet.
  - **No global context:** the current span lives in our own `CurrentSpan` holder, which resets after each request and message. The OpenTelemetry global context storage is not used, because worker mode allows no static state ([ADR-0008](../03-adr/0008-frankenphp-runtime.md)).
  - **Export:** spans leave after the response is sent, or after each message in a worker, in batches of up to 512, each HTTP call capped at 1 second; spans past 2048 per request or message are dropped. A failed export is logged (class only) and its spans are dropped; the request is never held up.
  - **Sampling:** head sampling by `OTEL_TRACES_SAMPLER_ARG` (0 to 1; use `0.1` in prod). An unsampled request starts no database or send spans.
  - **Off by default:** tracing is off while `OTEL_EXPORTER_OTLP_ENDPOINT` is empty.
  - **Jaeger locally:** the `observability` profile runs only Jaeger for now, as a dev-only viewer; the self-host Collector and Grafana parts are still to come ([ADR-0029](../03-adr/0029-observability.md)). Run `docker compose --profile observability up`, set `OTEL_EXPORTER_OTLP_ENDPOINT=http://jaeger:4318` in `.env.local`, and open http://localhost:16686. Both ports bind to 127.0.0.1 only, and traces are kept in memory.
- **Logs:** JSON lines to stdout with `request_id`, `trace_id`, `organization_id`, `user_id`, route, timing. `RedactionProcessor` keeps only the keys on its allow-list and drops every other one ([security.md](security.md#logging-without-leaking), [ADR-0034](../03-adr/0034-allow-list-log-redaction.md)). Prod writes `info` and above as they happen, for web requests, commands and workers alike, through one stdout handler with no buffer: a warning, such as a failed readiness check, reaches the log even when no error follows it. The `request` channel is the exception: it has its own stdout handler from `warning` up, because its `Matched route` info line would otherwise add a log line for every request, including every healthcheck and load-balancer probe of `/health/*`; a 5xx error on it is still written. A missing route (404) or a wrong method (405), including an outside caller of `/health/*`, is logged at `debug` (`framework.exceptions`), so it stays out of the prod log instead of showing up as an error (`MonologConfigTest` pins both).
- **Metrics:** request rate, error rate, latency per route; queue depth and oldest message age per transport; worker memory; DB connections; cache hit rate; AI calls and cost per org; storage per org.
- **Health** (`HealthController`, [ADR-0029](../03-adr/0029-observability.md)): two `GET` routes that answer `{"status":"ok"}` (200) or `{"status":"fail"}` (503), never which check failed, a version or an error text.
  - `/health/live`: the PHP process answers. It touches no database or service. The image `HEALTHCHECK` probes it through Caddy over HTTPS from loopback.
  - `/health/ready`: for the load balancer. In order, stopping at the first failure: PostgreSQL answers `SELECT 1` as the default connection's role (its own connection, 1 s connect timeout); then, in any order, every migration is executed, `aud_event` has next month's partition (`aud_event_YYYYMM`, UTC), so do `tkt_ticket_event`, `ntf_notification` and `ast_usage`, both Valkeys answer (`REDIS_CACHE_DSN`, and the host of `MESSENGER_TRANSPORT_DSN`; 1 s timeouts) and `OBJECT_STORAGE_ENDPOINT` (the storage's internal address, empty in `.env`, so readiness fails until it is set) accepts a TCP connection within 1 s. A Valkey DSN's own `lazy`, `timeout`, `read_timeout`, `retry_interval` and `persistent` options are ignored by the probe, and a DSN of several hosts must answer on each. A comma in a Valkey password must be written URL-encoded as `%2C` (a `?` may stay as it is). Messenger's Redis transport splits its DSN at every comma, so the probe fails on a raw one in `MESSENGER_TRANSPORT_DSN`; it fails on one in `REDIS_CACHE_DSN` too, although the cache client would read it, only so both DSNs follow one rule. The `dsn_without_stream` env processor also refuses one in `MESSENGER_TRANSPORT_DSN` when a transport is built, so `worker` and `worker-heavy` fail at start ([ADR-0033](../03-adr/0033-valkey-transports-workers-and-outbox-relay.md)). The timeouts bound the connect and the replies, not the lookup of a host name: DNS resolution is a blocking system call no PHP timeout covers, so a slow resolver can hold the route longer (the platform's probe timeout still cuts it off). Each check is a `ReadinessCheck` in the module that owns what it checks: the platform ones in SharedKernel, `NextAuditPartitionExists` in Audit, `NextTicketEventPartitionExists` in Ticketing, `NextNotificationPartitionExists` in Notification, `NextUsagePartitionExists` in Assist, `ObjectStorageIsReachable` in Media. A failed check logs one warning with its name (`check`), the exception class, SQLSTATE and code only. No check reads a tenant table.
  - The migrations check compares the versions the code ships with one plain `SELECT` of `doctrine_migration_versions` (not Doctrine's status check, which reads the table's structure from the catalog on every call). It reads through the migrations configuration's connection: the migrator in dev and test, the app's own in prod, which has no migrator connection. So `pinround_app` has `SELECT` on that table and nothing else (`Version20261107180000`; `roles.sql` revokes every other right on re-run, and everything from `pinround_readonly`). The user chose this read-only grant: the table holds only version names and run times, no tenant data, and a read-only grant is the smallest change that lets prod's readiness tell a half-migrated database from a current one.
  - Who may call them: only a client whose IP is loopback or private (`IpUtils::PRIVATE_RANGES`); anyone else gets the 404 of a missing route. Behind a reverse proxy the client IP is the proxy's until trusted proxies are configured (P12).
  - Neither route opens the request transaction (route default `_request_transaction: false`, read by `RequestTransaction`) or a session, so both answer while PostgreSQL or Valkey is down.
  - The Caddy admin API is off in the prod image (`CADDY_ADMIN=off`); dev keeps it on `localhost:2019`, the only place Caddy's `/metrics` is served.

SLOs:

| SLO | Target |
|---|---|
| Availability (SaaS) | 99.9% per month |
| Home p95 | ≤ 250 ms |
| Ticket page p95 | ≤ 250 ms |
| Notification delivered (in-app) | 95% within 5 s |
| Screenshot ready after upload | 95% within 10 s |

Alerts (page someone): error rate > 2% for 5 minutes; `notify` queue oldest message > 30 s; `media` > 2 min; DB connections > 85%; disk > 80%; any RLS violation error; backup job failed.

## Scheduled jobs

Symfony Scheduler, run by the `scheduler` service. Each job is idempotent and uses a Lock so only one instance runs.

| Job | When | What |
|---|---|---|
| Retention purge | daily 02:00 | As `pinround_retention` (the only `BYPASSRLS` role): delete closed tickets past the org's retention together with their events, messages, rounds and media references (open tickets never); purge audit events past retention (security events kept ≥ 1 year); delete media; write audit counts ([data-model.md › Partitions and retention](data-model.md#partitions-and-retention)) |
| Partition maintenance | daily | As `pinround_migrator`: create next months' partitions for `tkt_ticket_event`, `aud_event`, `ntf_notification`, `ast_usage`, with no direct grants on the children; drop expired `ntf_notification`/`ast_usage` partitions; drop a `tkt_ticket_event`/`aud_event` partition only when no org with longer retention has rows in it |
| Ping digests | every 15 min check, at most every 3 h per user / daily | Email digests per user preference |
| Quiet hours release | every 5 min | Deliver pings held during quiet hours when the recipient's quiet time ends |
| Turn read model check | nightly | Compare `ins_ticket_turn` with ticket state; rebuild mismatches |
| Signed export cleanup | hourly | Remove export files older than 24 h (also a bucket lifecycle rule) |
| AI budget reset | monthly | Reset per-org monthly AI budgets; send usage summary to admins |
| Jira reconcile | nightly | Re-fetch tickets updated in Jira since the last webhook to catch missed events |
| Token expiry notice | daily | Warn users 7 days before a personal access token expires |

Built so far, all daily on the `default` schedule and outside prod only (they need the migrator connection): `AddTicketEventPartitions`, `AddNotificationPartitions` (the current UTC month and three ahead), `DropExpiredNotificationPartitions` (drops each `ntf_notification_YYYYMM` whose whole UTC month ended at least 90 days ago; the current and later months never), `AddUsagePartitions` (the current UTC month and three ahead of `ast_usage`; readiness check `next_usage_partition`) and `DropExpiredUsagePartitions` (drops each `ast_usage_YYYYMM` whose whole UTC month ended at least 13 months ago; the current and later months never).

## Capacity alarms

Warn (ticket for the team, not a page) when:

- DB CPU > 60% at peak for 7 days.
- DB size grows > 20% in a month.
- Storage per org passes 80% of quota.
- p95 of any hot path above budget for 3 days.
- Queue age trends up over a week.

Then follow the scaling steps in [performance-and-scale.md](performance-and-scale.md#scaling-steps).

## Runbooks

Written in `docs/operations/runbooks/` during phase 12. Each has: symptoms, checks, fix steps, how to confirm.

- Restore PostgreSQL to a point in time.
- Restore deleted screenshots from bucket versions.
- Rotate the field-encryption key.
- Revoke all sessions and tokens for a user or an org.
- Jira sync paused (token expired, rate limited).
- Queue backlog (find the slow handler, scale workers).
- AI provider down (switch provider or turn AI off for the org).
- Disk full on self-host.
- Security incident first hour ([security.md](security.md#incident-response-basics)).
