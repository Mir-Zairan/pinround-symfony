# ADR-0034 — Keep only allow-listed ids in log records

Every log record keeps only the context and extra keys on one allow-list, and the org id is logged as `organization_id`.

## Index

- [Status](#status) — accepted, supersedes part of ADR-0027 and ADR-0029.
- [Context and problem statement](#context-and-problem-statement) — a new key name that no block list knows.
- [Decision drivers](#decision-drivers) — what the redaction must guarantee.
- [Considered options](#considered-options) — block list, allow-list, no context at all.
- [Decision outcome](#decision-outcome) — the rule, the exception messages, the field name, consequences.
- [Confirmation](#confirmation) — the tests that check it.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — trade-off analysis.
- [More information](#more-information) — links and revisit triggers.

## Status

Accepted · Supersedes in part [ADR-0027](0027-secrets-and-encryption.md): only the first "Logs and traces" bullet (a processor that redacts known keys and strings matching token patterns) and the Confirmation line that expects `[redacted]` in a log capture. Supersedes in part [ADR-0029](0029-observability.md): only the log field name `org_id`, now `organization_id`. The rest of both ADRs stays as decided.

- Date: 2026-11-07
- Deciders: Zairan Mir (lead)

## Context and problem statement

Omar adds a warning to the Ticketing module of Acme Foods and passes the ticket's text under the key `summary`. ADR-0027's processor redacts `description`, `body`, `note` and a few more, but not `summary`, so the text of a security ticket reaches the log tool. A week later Mia finds that DBAL's debug logger writes every bound SQL value under `params`, another name nobody listed.

A list of bad key names is always one name short. How does the redaction stay safe when someone logs a key nobody thought of?

## Decision drivers

- No ticket text, prompt, AI answer or token in any log, whatever key or message it comes in (ADR-0027, NFR-SEC-09).
- Logs still carry what an operator needs to follow a request or a message: ids, route, status, timing, error class.
- The rule must hold for vendor code (Symfony, Doctrine, Messenger) we cannot change.

## Considered options

1. Block list: drop known sensitive keys and strings that look like tokens (ADR-0027 as written).
2. Allow-list: keep only listed id and shape keys, drop every other key.
3. No context at all: drop all context and extra, keep the message.

## Decision outcome

Chosen option: **2, the allow-list**, because a key nobody listed is dropped instead of written, so a new key name can never leak.

- `RedactionProcessor` runs on every channel, after every other logger processor. It keeps only the keys listed in [security.md](../02-architecture/security.md#logging-without-leaking) whose value is a scalar or null, caps strings, and reduces `exception` to class, code, file and line. A dropped key is absent from the record; there is no `[redacted]` marker.
- Symfony quotes exception messages in record messages it builds itself. The processor replaces each quoted copy of the message of every throwable in the context with `[exception message withheld]`, and each unquoted copy of one with 16 characters or more. A shorter one found outside quotes as a whole word withholds the whole record message, because replacing it everywhere would also break unrelated words such as class names.
- No token-pattern scan: unlisted values are dropped, so a token can only reach a log through a message built from content, which the coding rule forbids (log a constant message with placeholders).
- The org id is logged as `organization_id`, the column name of ADR-0005, not `org_id`.

### Consequences

- Good: an unknown key, a bound SQL value and an exception message never reach a log.
- Bad: a key that would help debugging is dropped until someone adds it to the list, with a review of what its values can hold.
- Neutral: dev logs lose the SQL text of DBAL's debug logger; the profiler still shows queries in dev.

## Confirmation

`tests/Unit/SharedKernel/Infrastructure/Logging/RedactionProcessorTest.php` pins the exact allow-list and the exception-message rules. `tests/Functional/SharedKernel/LogLeakMarkerTest.php` sends a unique marker under an unlisted key, under `description`, as a bound SQL value and in an exception message, and fails if the marker is in the written log; it also fails if `RedactionProcessor` is not the last processor of every channel.

## Pros and cons of the options

### Block list

- Good, because every key that is not known to be bad stays, so logs are rich.
- Bad, because it fails open: each new key name leaks until someone lists it.

### Allow-list

- Good, because it fails closed: a new key is dropped.
- Bad, because adding a useful key needs a change and a review.

### No context at all

- Good, because it is the simplest and safest.
- Bad, because logs lose the ids that tie them to a request, a message or an organization.

## More information

- Related: [ADR-0005](0005-multi-tenancy.md), [ADR-0027](0027-secrets-and-encryption.md), [ADR-0029](0029-observability.md), [security.md](../02-architecture/security.md#logging-without-leaking).
- Revisit when: the allow-list passes 30 keys, or an incident review finds content in a log that the marker test did not cover.
