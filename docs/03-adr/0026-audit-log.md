# ADR-0026: The audit log is append-only, partitioned by month, and kept for as long as the org decides

Important actions are recorded in a log that the app itself cannot change or delete, except through the retention job.

## Index

- [Status and people](#status-and-people): status, date, deciders.
- [Context and problem statement](#context-and-problem-statement): the scene: "who changed this?".
- [Decision drivers](#decision-drivers): trust, compliance, cost.
- [Considered options](#considered-options): app table with normal rights, append-only table, external log service.
- [Decision outcome](#decision-outcome): what is logged, table design, grants, retention, export.
  - [Consequences](#consequences): good, bad and neutral effects.
- [Confirmation](#confirmation): tests and checks.
- [Pros and cons of the options](#pros-and-cons-of-the-options): details per option.
- [Clarifications (2026-11-06)](#clarifications-2026-11-06): partition grants, export links.
- [More information](#more-information): links and when to revisit.

## Status and people

- Status: Accepted
- Date: 2026-11-06
- Deciders: Zairan Mir (lead)

## Context and problem statement

On Monday, Sara the admin sees that Raj suddenly has the Admin role, AI help is set to send full ticket text to the cloud, and someone exported all data on Saturday. She needs to know who did it, when, and from where. If the log can be edited by the same application code that a bug or an attacker uses, the log proves nothing.

## Decision drivers

- Every security-relevant and admin action is recorded with actor, time and target.
- The application cannot update or delete entries (only insert and read). Deletion happens only by the retention policy.
- Orgs choose how long to keep data (1 year, 2 years, always).
- The log contains no ticket content and no secrets.
- Cheap to purge old data, and fast to show recent data.

## Considered options

1. **Normal table with full rights.**
2. **Append-only table with INSERT/SELECT grants, monthly partitions and a separate purge role (chosen).**
3. **External log service** (SIEM) only.

## Decision outcome

Chosen option: **2**, with an optional export stream to a SIEM later.

- **What is logged:**
  - Logins, including failed ones, rate-limited and as an aggregate.
  - MFA changes, password resets.
  - Personal access token created, revoked or used (first use per day).
  - Role and membership changes, invitations.
  - Team, step, policy and rule changes.
  - AI policy changes.
  - Ping and new-ticket settings.
  - Connection changes (Jira, SSO).
  - Visibility changes on tickets, closes as duplicate.
  - Exports, retention changes, admin transfer of drafts (ADR-0017).
  - Every MCP tool call (ADR-0021).
  - Rejected local-AI results (ADR-0019).
- Normal ticket work (moves, comments) lives in `tkt_ticket_event` (the "What happened" list). That is product history, not this audit log.
- **Table:** `aud_event(id uuid v7, organization_id, actor_user_id, actor_token_id null, action text, target_type text, target_id uuid null, data jsonb, ip_hash bytea, user_agent_hash bytea, at timestamptz)`, partitioned by month on `at`.
  - `data` holds ids and before/after values of settings only. No ticket text.
  - The IP is stored as an HMAC with a rotating secret, so incidents can be matched without storing raw IPs.
- **Grants:** the app DB role has `INSERT, SELECT` on the **parent table only**; every right on the child partitions is revoked, so nobody can query a partition directly and skip the parent's RLS policy. No `UPDATE`, `DELETE` or `TRUNCATE`. Migrations create partitions with `pinround_migrator`. RLS limits reads to the org (ADR-0005).
- **Retention:** the scheduled job runs as the separate `pinround_retention` role. It drops whole monthly partitions when every org's retention allows it. For orgs with shorter retention inside a still-kept partition, it deletes their rows (the only DELETE path). The job writes its own audit event.
- **Export:** an admin requests an export (JSON Lines or CSV). A worker builds it and stores it in private object storage for 24 hours. The email contains a link to an **app page**, not to the file: the page checks again that the person is an admin with a fresh MFA check, then issues a signed download URL valid 5 minutes. Exports never contain unshared draft rounds or private AI explanations. Each download is audited.
- **UI:** Admin → Activity log, newest first, with filters (actor, action, date).

### Consequences

- Good: the log is trustworthy against app bugs and a compromised app session.
- Good: cheap retention (dropping a partition) and fast recent reads.
- Bad: two DB roles to manage in deployment.
- Bad: a DB superuser can still change data. That is outside the threat model for the app. Backups and a SIEM export can cover it later.
- Neutral: retention also applies to `tkt_ticket_event` and notification partitions through the same job.

## Confirmation

- Integration test: running UPDATE or DELETE on `aud_event` as the app role fails with a permission error.
- Test per audited action: performing it writes exactly one event with the expected `action` and no forbidden keys in `data` (a schema allow-list per action).
- Test: the retention job drops or deletes only rows past each org's retention, and writes its own event.
- Monthly partition creation check in CI and at boot (health check fails if next month's partition is missing on the 25th).

## Pros and cons of the options

### 1. Normal table

- Good, because it is simplest.
- Bad, because any bug or attacker with app access can rewrite history.

### 2. Append-only with grants

- Good, because it is enforced by the database, and cheap to purge.
- Bad, because there are extra roles and partition upkeep.

### 3. External log service only

- Good, because it is independent.
- Bad, because it is not available to self-hosted small orgs, and admins need an in-app view anyway.

## Clarifications (2026-11-06)

- **Partition grants.** Grants on the parent only; child partitions have no grants for `pinround_app` or `pinround_readonly`. The schema test walks `pg_inherits` and fails on any direct grant on a partition.
- **Export links.** The emailed link opens an app page (admin + MFA re-checked) that issues a 5-minute signed URL; the file itself is deleted after 24 hours. Unshared drafts and private explanations are never exported.
- **Retention.** Open tickets are never deleted; events of tickets are purged only together with their closed ticket ([data-model.md › Partitions and retention](../02-architecture/data-model.md#partitions-and-retention)).

## More information

- Related: [ADR-0005](0005-multi-tenancy.md), [ADR-0021](0021-mcp-server.md), [ADR-0027](0027-secrets-and-encryption.md), [ADR-0030](0030-deployment-model.md).
- Architecture: [security.md](../02-architecture/security.md), [operations.md](../02-architecture/operations.md).
- Revisit when a customer needs tamper-evidence beyond grants (hash chaining or write-once storage), or when audit volume exceeds 10M rows per month.
