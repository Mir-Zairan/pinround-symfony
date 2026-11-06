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
- [Observability](#observability) — traces, logs, metrics, SLOs, alerts.
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
| `worker` | same image, `messenger:consume` | Async jobs: media, ai, notify, integration, outbox relay ([ADR-0009](../03-adr/0009-async-messaging-and-outbox.md)) |
| `scheduler` | same image, Symfony Scheduler | Scheduled jobs (see below) |
| `postgres` | PostgreSQL 18 with `pg_trgm`, `pgvector` (optional) | Primary data ([ADR-0004](../03-adr/0004-postgresql.md)) |
| `valkey` | Valkey 8 | Cache, sessions, locks, rate limits, job queues ([ADR-0010](../03-adr/0010-cache-sessions-locks.md)) |
| `minio` | MinIO (self-host) | Object storage for screenshots ([ADR-0011](../03-adr/0011-screenshot-storage-and-processing.md)); built from pinned source in `docker/minio` ([ADR-0031](../03-adr/0031-minio-built-from-pinned-source.md)) |
| `mailpit` | Mailpit (local only) | Catch outgoing email |
| `ollama` | optional | Server-side AI for orgs that want one shared box |

SaaS swaps `postgres`, `valkey` and `minio` for managed services (managed PostgreSQL with PITR, managed Valkey/Redis, S3 or R2) and runs `app`, `worker` and `scheduler` on a container platform (Kubernetes optional).

Containers run as a non-root user, with a read-only root file system where possible, and health checks.

## Configuration

- Twelve-factor style: everything through environment variables, documented in `.env` (defaults, no secrets) and `docs/operations/config.md` (created in phase 0).
- Secrets: Symfony secrets vault per environment, or the platform's secret store injected as env vars. Never in the repo ([ADR-0027](../03-adr/0027-secrets-and-encryption.md)).
- Database roles: `app`, `worker` and `scheduler` get a `DATABASE_URL` for `pinround_app`. The `migrate` container gets its own `DATABASE_URL` for `pinround_migrator`; prod has no second connection. Dev and test add `MIGRATOR_DATABASE_URL` (in `.env.dev` and `.env.test`), which the `migrator` Doctrine connection uses for migrations. The `pinround_retention` credentials go only to the retention job and the `pinround_readonly` ones only to support tooling, never into the web or worker env. The admin account runs `docker/postgres/roles.sql` at install, again after the first migration (it takes `doctrine_migration_versions` away from `pinround_app`), and after an upgrade that changes it ([data-model.md › Row-level security](data-model.md#row-level-security)).
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

- **Traces:** OpenTelemetry for HTTP requests, Doctrine queries (no parameter values), Messenger handlers, AI calls (provider, model, tokens, time; no content), storage calls.
- **Logs:** JSON lines to stdout with `request_id`, `trace_id`, `org_id`, `user_id`, route, timing. A redaction processor removes content ([security.md](security.md#logging-without-leaking)).
- **Metrics:** request rate, error rate, latency per route; queue depth and oldest message age per transport; worker memory; DB connections; cache hit rate; AI calls and cost per org; storage per org.
- **Health:** `/healthz` (process alive) and `/readyz` (DB, Valkey, storage reachable). Readiness never leaks details publicly.

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
