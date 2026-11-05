# ADR-0006 — UUIDv7 primary keys and human ticket keys

Every record gets a UUIDv7 id; tickets also get a short human key like `SHOP-123`.

## Status

Accepted · 2026-11-06 · Deciders: Zairan Mir (lead).

## Context and problem statement

Mia says "SHOP-123 is broken again" in a chat, and everyone knows which ticket she means.
But if the screenshot URL were `/media/4711`, anyone could try `/media/4712`.
And the ticket table grows by thousands of rows a day across all organizations.

What ids do we use inside the system, and what do people see?

## Decision drivers

- Ids must not be guessable or show how big a customer is (NFR-SEC).
- Inserts must stay fast at 2M tickets and 20M screenshots (NFR-PERF, NFR-SCAL).
- People need short, readable keys per project.
- Ids must be created in PHP before saving (events, outbox, presigned upload keys).

## Considered options

1. Auto-increment integers everywhere.
2. UUIDv4 primary keys.
3. UUIDv7 primary keys plus a per-project human number.

## Decision outcome

Chosen option: **UUIDv7 primary keys (Symfony Uid, `uuid` column type) plus a human key per project**.

- UUIDv7 is time-ordered, so new rows land at the end of the B-tree index. This keeps
  inserts fast and indexes compact (unlike random UUIDv4).
- Human key: `tkt_project.next_number` is increased with
  `UPDATE tkt_project SET next_number = next_number + 1 WHERE id = $1 RETURNING next_number - 1`
  in the same transaction as the ticket insert. The row lock makes numbers unique
  per project, and a rollback also rolls back the counter, so there are no gaps. A unique index on
  `(organization_id, project_id, number)` is the safety net.
- URLs use the human key inside the org (`/t/SHOP-123`), never a raw integer.
  Media and API URLs use UUIDs and still pass a voter check ([ADR-0013](0013-authorization.md)).
- Imported Jira keys are kept as `external_ref`, not reused as pinround keys
  ([ADR-0025](0025-jira-integration.md)).

### Consequences

- Good: ids are unguessable and can be created anywhere (PHP, workers, MCP).
- Good: insert performance close to integers.
- Bad: UUIDs take 16 bytes instead of 8; indexes are bigger.
- Bad: UUIDv7 shows the creation time to someone who sees the id. Acceptable: we never expose ids without an access check.
- Neutral: the project counter row is a small hot spot; fine at our write rates.

## Confirmation

- A PHPUnit architecture test fails if an entity id is not a `Uuid` (v7) type.
- A schema test fails if a primary key is an integer column.
- A concurrency test creates 100 tickets in parallel in one project and checks numbers 1–100 without duplicates.

## Pros and cons of the options

### Auto-increment integers

- Good, because they are small and fast.
- Bad, because they are guessable and show volume; ids come only from the database.

### UUIDv4

- Good, because they are unguessable.
- Bad, because random inserts fragment indexes and slow writes at large row counts.

### UUIDv7 + human key

- Good, because they are unguessable, index-friendly, and readable for people.
- Bad, because there are two kinds of identifier to explain.

## More information

- Revisit when: the per-project counter shows lock waits above 50 ms p95.
