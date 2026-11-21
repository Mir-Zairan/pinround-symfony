# ADR-0030: One container image for SaaS and self-hosting; zero-downtime migrations; point-in-time backups with restore drills

The same image runs in a small company's Docker Compose and in our hosted setup. Upgrades do not stop the app, and backups are proven by restoring them.

## Index

- [Status and people](#status-and-people): status, date, deciders.
- [Context and problem statement](#context-and-problem-statement): the scene: two very different customers.
- [Decision drivers](#decision-drivers): one build, safe upgrades, real backups.
- [Considered options](#considered-options): SaaS only, self-host only, one image for both.
- [Decision outcome](#decision-outcome): images, Compose stack, SaaS stack, migrations, backups, upgrades.
  - [Consequences](#consequences): good, bad and neutral effects.
- [Confirmation](#confirmation): checks and drills.
- [Pros and cons of the options](#pros-and-cons-of-the-options): details per option.
- [More information](#more-information): links and when to revisit.

## Status and people

- Status: Accepted
- Date: 2026-11-06
- Deciders: Zairan Mir (lead)

## Context and problem statement

Acme Foods (40 people) wants pinround on one server in their office, because the data must stay inside. Another company wants a hosted service and no servers. A release on a Thursday adds a column to tickets. If the migration locks the table for two minutes, nobody can work. If the nightly backup was never tested, one bad disk could lose a month of screenshots and rounds.

## Decision drivers

- One build artifact. No "self-host edition" drift.
- Small orgs run it with one command and a short guide.
- Deploys with no downtime and no long table locks.
- RPO ≤ 15 min and RTO ≤ 4 h (non-functional requirements), proven by drills.

## Considered options

1. **SaaS only.**
2. **Self-host only.**
3. **One image for both, with Compose for self-host and managed services for SaaS (chosen).**

## Decision outcome

Chosen option: **3**.

- **Images:** one multi-stage Dockerfile, based on the official FrankenPHP image (ADR-0008). Images are built in CI, tagged with the git SHA and semantic version, scanned for vulnerabilities (Trivy or Grype), and signed (cosign, check at implementation time). Roles from the same image: `app` (HTTP + Mercure), `worker` (Messenger consumers per transport), `scheduler` (Symfony Scheduler), `migrate` (one-off).
- **Self-host stack** (`compose.prod.yaml`): app, worker, scheduler, postgres:18 (with pg_trgm, unaccent, optional pgvector), valkey:8, minio, plus optional profiles `observability` and `clamav`. TLS through Caddy (inside FrankenPHP) with automatic certificates, or behind the org's proxy. One `.env` file and the secrets.
- **SaaS stack:** managed PostgreSQL 18 with PITR, managed Redis-compatible store, S3-compatible storage with versioning, and a container platform (Kubernetes optional). The same environment variables.
- **Migrations** (Doctrine Migrations, expand/contract):
  1. Expand: add nullable columns or new tables. Create indexes `CONCURRENTLY` (in their own migration, without a transaction). New constraints `NOT VALID`, then `VALIDATE`.
  2. Deploy code that writes both and reads new.
  3. Backfill in batches with a Messenger job.
  4. Contract: drop old columns in a later release.
  - Never rename a column in one step. A CI check flags `ALTER TABLE ... RENAME` and non-concurrent `CREATE INDEX` on large tables.
  - The `migrate` container runs before new app pods take traffic.
- **Backups:**
  - Postgres: continuous WAL archiving + nightly base backup (pgBackRest or the platform's PITR), so RPO ≤ 15 min.
  - Object storage: bucket versioning + lifecycle, and for self-host a nightly `mc mirror` to a second location.
  - Encrypted (ADR-0027), with 35 days kept by default.
  - Self-host guide includes a one-command backup script.
- **Restore drills:** quarterly. Restore to a fresh environment, run the smoke tests, and record the time (target ≤ 4 h). Phase 12 runs the first drill.
- **Upgrades:**
  - Rolling deploy with readiness checks (ADR-0029).
  - FrankenPHP worker restart on deploy.
  - Messenger workers stop gracefully (`--time-limit`, and they handle SIGTERM).
  - Release notes say if a release has a contract step.

### Consequences

- Good: one product, both markets, and confidence in data safety.
- Good: no maintenance windows for normal releases.
- Bad: expand/contract makes some changes take two releases.
- Bad: self-host support (old versions in the wild). Mitigated by a supported-versions policy (latest two minors).
- Neutral: Compose is enough up to roughly 300 users per org on one 8-core/32 GB host. To be confirmed by the phase 12 load test.

## Confirmation

- CI builds the image on every PR. A vulnerability scan with high or critical findings fails the release job.
- A CI migration check: run all migrations on an empty DB and on a seeded DB with 100k tickets, and flag lock-heavy statements.
- The restore-drill record lives in `docs/02-architecture/operations.md` (date, duration, result).
- A smoke test suite runs after each deploy (login, Home, ticket page, upload).

## Pros and cons of the options

### 1. SaaS only

- Good, because there is one environment to run.
- Bad, because we lose orgs that must keep data inside.

### 2. Self-host only

- Good, because data always stays with the customer.
- Bad, because there is no easy start for teams without servers, and we cannot see problems.

### 3. One image for both

- Good, because of the reach, and one build.
- Bad, because we must document and test two topologies.

## More information

- Related: [ADR-0004](0004-postgresql.md), [ADR-0008](0008-frankenphp-runtime.md), [ADR-0010](0010-cache-sessions-locks.md), [ADR-0011](0011-screenshot-storage-and-processing.md), [ADR-0027](0027-secrets-and-encryption.md), [ADR-0029](0029-observability.md).
- Architecture: [operations.md](../02-architecture/operations.md).
- Revisit when a single org exceeds what one Compose host can serve (load test), or when we need multi-region hosting.
