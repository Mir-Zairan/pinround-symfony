# ADR-0035 — Web owns its UI preferences (web_ prefix)

The Web module keeps per-user, per-organization UI preferences in its own `web_` tables, with row-level security like any tenant table.

## Index

- [Status](#status) — accepted, supersedes part of ADR-0002.
- [Context and problem statement](#context-and-problem-statement) — a Home chip that must be remembered.
- [Decision drivers](#decision-drivers) — what the answer must allow.
- [Considered options](#considered-options) — a column on the user, the session, a Web table.
- [Decision outcome](#decision-outcome) — the prefix, the first table, consequences.
- [Confirmation](#confirmation) — the tests that hold it.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — trade-off analysis.
- [More information](#more-information) — links and revisit triggers.

## Status

Accepted · Supersedes in part [ADR-0002](0002-modular-monolith.md): only the Web row of its module table (Prefix "—", Owns "layout, navigation, Home composition, Twig components"). Web now has the prefix `web_` and owns its UI preference tables. The rest of ADR-0002 stays as decided.

- Date: 2026-11-08
- Deciders: Zairan Mir (lead)

## Context and problem statement

Lena is Admin and lead in Acme Foods. On Home she picks the "Admin" focus chip, logs out, and opens pinround the next morning on her laptop: Home should open on "Admin" again (P01.T5, "the chosen chip is remembered across sessions"). If she is also in another organization where she is no admin, that organization keeps its own choice.

The chips are Web's (FR-HOME-08), but ADR-0002 gives Web no prefix and no tables, and Web may not call `Identity_Api` (modules.md, Depends on). Where is the choice stored?

## Decision drivers

- The choice survives logout and follows the user to every device.
- One choice per organization: the chips a user gets depend on their roles there (All teams for a Manager, Admin for an Admin).
- Web may use only the Api of Insight, Organization, Notification and Ticketing ([ADR-0032](0032-module-call-edges-follow-modules-catalogue.md)).
- Tenant data stays under row-level security ([ADR-0005](0005-multi-tenancy.md)).

## Considered options

1. A column on `idn_user`, written through a new Identity Api.
2. The session.
3. A Web table per user and organization, `web_home_focus`.

## Decision outcome

Chosen option: **a Web table**, because it keeps one choice per organization, survives logout, and needs no new edge from Web to Identity. The user chose it over a column on the user.

- Web gets the table prefix `web_`. It is used only for UI preferences of a user in an organization: no business rules, nothing other modules read.
- Every `web_` table is a tenant table: `organization_id` first in every index and `TenantTable::enableRls`, as ADR-0005 requires.
- The first table is `web_home_focus` (`Version20261108160000`; see [data-model.md](../02-architecture/data-model.md#web)), written by `POST /home/focus` through `DbalHomeFocusPreferences`.

### Consequences

- Good: the choice works on every device and per organization, with no new module edge.
- Good: the row-level security rules and their tests cover it like any tenant table.
- Bad: Web now has a schema of its own and a migration to keep, against ADR-0002's "Web holds no business rules, it only composes".
- Neutral: a membership removed leaves its `web_home_focus` row behind; it is harmless (the chip falls back to All) and the retention work (P11) may purge it.

## Confirmation

- `CatalogTest` fails on any tenant table without the `TenantTable::enableRls` policy.
- `WebTablesIsolationTest` lists every `web_` table and proves another organization's rows are invisible and cannot be written.
- `DatabaseRolesTest::theHomeFocusTableGrantsTheAppNoDeleteAndIsKeyedByTheOrganization` pins the grants and the only index.
- `composer deptrac` keeps Web from calling `Identity_Api`.

## Pros and cons of the options

### A column on idn_user

- Good, because it needs no new table.
- Bad, because one value cannot differ per organization, and an Admin chip saved in one organization means nothing in another.
- Bad, because Web would need a new Identity Api edge.

### The session

- Good, because it needs no schema at all.
- Bad, because it is lost at logout and does not reach the user's other devices.

### A Web table

- Good, because it is per user and per organization and lasts.
- Bad, because Web gets tables, which ADR-0002 did not plan.

## More information

- Related: [ADR-0002](0002-modular-monolith.md), [ADR-0005](0005-multi-tenancy.md), [ADR-0032](0032-module-call-edges-follow-modules-catalogue.md), [modules.md](../02-architecture/modules.md#web-ui-shell), FR-HOME-08.
- Revisit when: Web holds more than 3 tables, or another module needs to read a `web_` table.
