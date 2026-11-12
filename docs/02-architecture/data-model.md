# Data model

The PostgreSQL tables, keys, indexes, partitions, row-level security, read models, and how big each table gets.

## Index

- [The story behind the shape](#the-story-behind-the-shape) — why the tables look the way they do.
- [General rules](#general-rules) — ids, tenant column, timestamps, naming, JSONB, enums.
- [Diagram 1 — people and organizations](#diagram-1--people-and-organizations) — Identity and Organization tables.
- [Diagram 2 — tickets and workflow](#diagram-2--tickets-and-workflow) — Ticketing, Workflow, Conversation.
- [Diagram 3 — rounds, screenshots and media](#diagram-3--rounds-screenshots-and-media) — Review and Media.
- [Diagram 4 — notifications, AI, integration, audit](#diagram-4--notifications-ai-integration-audit) — the supporting modules.
- [Table specifications](#table-specifications) — columns, constraints and indexes per table.
- [Read models](#read-models) — `ins_ticket_turn` and the other Insight tables.
- [Row-level security](#row-level-security) — the policy every tenant table gets (`TenantTable::enableRls`), how the tenant is set (`TenantContext`, `TenantMiddleware`, and for Messenger `TenantStamp`, `TenantStampMiddleware` and `RejectMessageForAnotherTenantMiddleware`), roles, and the resolver functions (owned by `pinround_resolver`, reading through `TenantTable::allowResolverRead`) that run before the tenant is known, what `CatalogTest` enforces, and what worker mode resets between requests and messages.
- [Ticket visibility predicate](#ticket-visibility-predicate) — the one canonical rule for who may see a restricted ticket.
- [Index catalogue](#index-catalogue) — the single list of indexes on hot tables.
- [Query patterns](#query-patterns) — the grouped list, Home, duplicates, ticket history, with SQL.
- [Partitions and retention](#partitions-and-retention) — monthly partitions and purge rules.
- [Migrations policy](#migrations-policy) — expand/contract, zero downtime.
- [Size estimates](#size-estimates) — rows and GB per table at the design point.

## The story behind the shape

Lena's Home must load in a quarter of a second, even when her company has 200,000 tickets. Mia's screenshots must never be readable by another company. Sara must be able to say "keep data for two years", and pinround must keep that promise.

These needs give the model its shape:

- every tenant row carries `organization_id`, and every index starts with it;
- "whose turn" is stored, not computed;
- screenshots are only references to objects in a bucket;
- history tables are partitioned by month, so old data can be dropped cheaply.

## General rules

- **Primary keys:** `uuid` with UUIDv7 values, generated in PHP (Symfony Uid) ([ADR-0006](../03-adr/0006-identifiers.md)). They are time-ordered, so B-tree inserts stay local, and nobody can guess the next id. Sequential integers are never exposed.
- **Tenant column:** `organization_id uuid NOT NULL` on every tenant table ([ADR-0005](../03-adr/0005-multi-tenancy.md)).
  - Every index and unique constraint starts with it. The exceptions are named in the table specifications below (`org_organization_slug_key`, `org_membership_user_id_idx`, `org_invitation_token_hash_key`), and `DatabaseRolesTest` fails on any other one on an `org_` table.
  - Foreign keys that point inside the tenant are composite where useful: `(organization_id, ticket_id)`. A child can then never point to another tenant's parent.
- **Global tables (no tenant):** `idn_user`, `idn_credential`, `idn_password_reset`, `idn_mfa_secret`, `idn_sso_identity`, `idn_login_attempt`. One person can belong to several organizations through `org_membership`. These tables are reached only through Identity, never joined from tenant modules.
- **Time:** `timestamptz` everywhere, stored in UTC. `created_at` and `updated_at` are set by the application `Clock`.
- **Optimistic locking:** `version int NOT NULL DEFAULT 1` on aggregates that people edit at the same time: tickets, rounds, settings.
- **Enums:** stored as `text` with a `CHECK` constraint, not as PostgreSQL enum types (adding a value then needs no migration lock).
- **JSONB:** only for data that is always read and written as a whole (settings, variants, suggestion results). Anything we filter on gets a real column.
- **Text search:** a generated `tsvector` column with a GIN index ([ADR-0022](../03-adr/0022-search-and-duplicates.md)).
- **Names:** snake_case. Tables are singular and carry the module prefix.

## Diagram 1 — people and organizations

The Identity and Organization tables. `idn_*` tables are global; `org_*` tables are per tenant.

```mermaid
erDiagram
  idn_user ||--o{ idn_credential : has
  idn_user ||--o{ idn_password_reset : requests
  idn_user ||--o| idn_mfa_secret : has
  idn_user ||--o{ idn_sso_identity : links
  idn_user ||--o{ idn_api_token : owns
  org_organization ||--o{ org_membership : has
  idn_user ||--o{ org_membership : joins
  org_organization ||--o{ org_team : has
  org_team ||--o{ org_team_member : has
  idn_user ||--o{ org_team_member : is
  org_organization ||--o{ org_invitation : sends
  org_organization ||--|| org_settings : has
  org_membership {
    uuid organization_id
    uuid user_id
    text_arr org_roles "admin, manager"
  }
  org_team_member {
    uuid organization_id
    uuid team_id
    uuid user_id
    text team_role "lead|member|viewer"
    text job "tester|developer|other"
  }
```

## Diagram 2 — tickets and workflow

Ticketing, Workflow and Conversation. Only the `Ticketing → Workflow` link (`step_id`) crosses a module. It is stored as an id; there is no Doctrine relation.

```mermaid
erDiagram
  org_team |o--o{ tkt_project : owns
  tkt_project ||--o{ tkt_ticket : contains
  wf_step ||--o{ tkt_ticket : "current step (id only)"
  tkt_ticket ||--o{ tkt_ticket_event : history
  tkt_ticket ||--o{ tkt_duplicate_link : "maybe same as"
  tkt_ticket ||--o{ tkt_summary : summaries
  tkt_ticket ||--o{ cnv_message : thread
  cnv_message ||--o{ cnv_reply : replies
  org_team ||--o{ wf_step : "team steps"
  org_organization ||--o{ wf_step : "core + company steps"
  org_organization ||--o{ wf_rule_set : rules
  tkt_ticket {
    uuid id
    uuid organization_id
    text key "SHOP-412"
    uuid step_id
    uuid assignee_id
    uuid team_id
    text visibility "all|team"
    text_arr check_reasons
  }
```

## Diagram 3 — rounds, screenshots and media

Review and Media. Review stores only `media_id`. The bytes live in the bucket.

```mermaid
erDiagram
  tkt_ticket ||--o{ rvw_round : "rounds (id only)"
  rvw_round ||--o{ rvw_screenshot : has
  rvw_screenshot ||--o{ rvw_pin : has
  rvw_round ||--o{ rvw_check_item : "checklist (round 1)"
  rvw_pin ||--o| rvw_check_item : "made from"
  med_object ||--o{ rvw_screenshot : "image (id only)"
  med_upload_intent ||--o| med_object : becomes
  rvw_round {
    uuid id
    int number
    text kind "report|checklist|review|draft"
    uuid owner_id
    timestamptz shared_at
  }
  rvw_pin {
    numeric x_pct
    numeric y_pct
    text verdict
    text source "human|ai"
  }
```

## Diagram 4 — notifications, AI, integration, audit

The supporting modules. They point to tickets by id only.

```mermaid
erDiagram
  tkt_ticket ||--o{ ntf_ping : "pings (id only)"
  tkt_ticket ||--o{ ntf_notification : about
  tkt_ticket ||--|| ins_ticket_turn : "read model"
  tkt_ticket ||--o{ ast_explanation : explanations
  tkt_ticket ||--o| ast_embedding : vector
  tkt_ticket ||--o{ int_external_ref : "Jira id"
  int_jira_connection ||--o{ int_project_link : links
  int_project_link ||--o{ int_status_mapping : maps
  org_organization ||--o{ aud_event : log
```

## Table specifications

Only the important columns are listed. Every tenant table also has `organization_id uuid NOT NULL` and `created_at timestamptz NOT NULL`. Each index below already starts with `organization_id`.

### Identity (global)

| Table | Columns | Constraints / indexes |
|---|---|---|
| idn_user | id, email `citext`, name, status (`active`/`blocked`), locale, time_zone, created_at, session_stamp `text` (32 random hex digits; a new one ends every session of the user), theme `text` default `system` (`system`/`light`/`dark`) | `UNIQUE (email)` (`idn_user_email_key`, so any letter case counts); `CHECK` on status and on theme (`idn_user_theme_check`). Created by `Version20261107190000`, like the two below; session_stamp and theme added by `Version20261108100000` (each existing row got its own random stamp); `pinround_app` has `SELECT, INSERT, UPDATE`, `pinround_readonly` nothing (a global table has no tenant policy to limit it) |
| idn_credential | user_id PK, password_hash (argon2id), changed_at | FK user (`idn_credential_user_id_fkey`); `pinround_app` has `SELECT, INSERT, UPDATE` |
| idn_mfa_secret | user_id PK, secret_enc `bytea` (the TOTP seed through `FieldEncryption`: key id, nonce, secretbox; never the seed), confirmed_at (null until the user typed a code of the seed: two-step login is off until then), recovery_code_hashes `text[]` (Argon2id hashes of the 10 recovery codes; empty until confirmed), last_used_step `bigint` (the TOTP time step of the last code taken, so it never counts twice; set by the confirmation), created_at (when the current seed was made) | FK user (`idn_mfa_secret_user_id_fkey`); one row per user, mapped by the entity `TwoStepLogin`. A new start before the confirmation replaces the seed; changes lock the user's `idn_user` row (`FOR NO KEY UPDATE`) first. Turning it off deletes the row, so a new setup starts from a new seed. Created by `Version20261108180000`; `pinround_app` has `SELECT, INSERT, UPDATE` and, since `Version20261108200000`, `DELETE` (turning it off), `pinround_readonly` nothing |
| idn_sso_identity | id, user_id, provider_config_id (the org's IdP config), issuer (`iss`), subject (`sub`), linked_at | `UNIQUE (provider_config_id, issuer, subject)`. Never matched by email; linking to an existing account needs the account password + MFA ([ADR-0012](../03-adr/0012-authentication.md)) |
| idn_sso_provider | id, organization_id, issuer, client_id, secret_enc, allowed_domains `text[]` (each proven by a DNS TXT record), verified_at | `UNIQUE (organization_id, issuer)`. Read before login through a resolver function (see [Row-level security](#row-level-security)) |
| idn_api_token | id, organization_id, user_id, name, token_hash (SHA-256 of a 32-byte random value plus a server pepper; tokens look like `pr_…`), prefix (8 characters, for display), scopes `text[]`, expires_at, last_used_at, revoked_at | `UNIQUE (token_hash)`; `(organization_id, user_id)` |
| idn_password_reset | id, user_id, token_hash `bytea` (SHA-256 of a 32-byte random token; the link carries the token base64url), created_at, expires_at (created_at + 1 hour), used_at | FK user (`idn_password_reset_user_id_fkey`), `(user_id)` (`idn_password_reset_user_id_idx`); `UNIQUE (token_hash)` (`idn_password_reset_token_hash_key`). Used once: one `UPDATE … WHERE used_at IS NULL AND expires_at > now RETURNING user_id`. Created by `Version20261108120000`; `pinround_app` has `SELECT, INSERT, UPDATE` (no DELETE: the retention job purges, P11), `pinround_readonly` nothing |
| idn_login_attempt | id, email_hash, ip_hash, success, at | `(email_hash, at)`; purged after 30 days; `pinround_app` has `SELECT, INSERT`. No entity maps it (hidden by `schema_filter`) |

### Organization

| Table | Columns | Constraints / indexes |
|---|---|---|
| org_organization | organization_id PK (the organization's own id, so its row has row-level security like any tenant row and is visible only while it is the tenant), name, slug (lowercase letters and digits joined by single hyphens, at most 63 characters), status (`active`), created_at | `UNIQUE (slug)` (`org_organization_slug_key`; an exception to the `organization_id`-first rule, as slugs are unique across organizations); `CHECK` on status. Created by `Version20261108140000`, like org_membership, org_team, org_team_member and org_settings below; all five are tenant tables with `TenantTable::enableRls`, no foreign key to `idn_user`, `pinround_app` has `SELECT, INSERT, UPDATE` (plus `DELETE` on org_membership and org_team_member only) and `pinround_readonly` has `SELECT` |
| org_membership | organization_id, user_id, org_roles `text[]` (`admin`, `manager`; Doctrine type `text_array`), joined_at (stands for created_at) | `PK (organization_id, user_id)`; `(user_id)` (`org_membership_user_id_idx`; an exception to the `organization_id`-first rule, for `resolve_user_orgs`, which runs before the tenant is known); `CHECK (org_roles <@ ARRAY['admin','manager'])`; `(organization_id, org_roles)` GIN only if needed. `resolver_read` with `SELECT (organization_id, user_id)` for `resolve_user_orgs`. Changing roles locks the org's admin rows first (`Memberships::lockAdminsOf`, `SELECT … FOR UPDATE`, which reloads loaded admin memberships), so the last admin cannot lose Admin |
| org_team | organization_id, id, name `citext`, slug (the team's part of its address: lowercase letters and digits joined by single hyphens, at most 63 characters; made from the name when the team is created — Latin letters without accents, other characters a hyphen, cut to 50, `team` when nothing is left — with `-2`, `-3`… when the organization has it already; never changed, also not on a rename; `TeamSlug`; teams older than the column got theirs from `Version20261110180000` by the same rule, in created_at order), lead_user_id, created_at | `PK (organization_id, id)` (the target of the member's composite foreign key); `UNIQUE (organization_id, name)` (`org_team_organization_id_name_key`; citext, so any letter case counts); `UNIQUE (organization_id, slug)` (`org_team_organization_id_slug_key`). A change to a team's roles first locks its row (`Teams::lockById`, `SELECT … FOR UPDATE`) |
| org_team_member | organization_id, team_id, user_id, team_role (`lead`/`member`/`viewer`), job (`tester`/`developer`/`other`), created_at (when the person joined the team) | `PK (organization_id, team_id, user_id)`; `(organization_id, user_id)`; FK `(organization_id, team_id)` → org_team `(organization_id, id)` (`org_team_member_team_fkey`; foreign key checks skip row-level security, so the organization is part of the key and a row can never name another organization's team) with its index `(organization_id, team_id)`, which DBAL wants though the key's prefix would serve; `CHECK` on team_role and job; `EXCLUDE USING btree (organization_id WITH =, team_id WITH =) WHERE (team_role = 'lead') DEFERRABLE INITIALLY DEFERRED` (`org_team_member_one_lead_excl`): at most one lead per team, checked at COMMIT so a lead change may demote and promote in either order. The `Team` aggregate keeps exactly one; Doctrine writes the two key columns through the team association (`TeamMemberKeyThroughTeam`) |
| org_invitation | organization_id, id, email `citext` (lowercase), org_roles `text[]` (`admin`, `manager`), team_id and team_role (`member`/`viewer`; both NULL or both set), token_hash `bytea` (SHA-256 of the link token; NULL until the email worker attaches it, replaced when a new link is sent), invited_by, created_at, expires_at (created_at + 72 h), accepted_at | `PK (organization_id, id)`; `UNIQUE (token_hash)` (`org_invitation_token_hash_key`; an exception to the `organization_id`-first rule, for `resolve_invitation`, which runs before the tenant is known); `UNIQUE (organization_id, email) WHERE accepted_at IS NULL` (`org_invitation_organization_id_email_key`; an expired invitation counts until a new invitation of that email removes it); FK `(organization_id, team_id)` → org_team `(organization_id, id)` (`org_invitation_team_fkey`, as for org_team_member) with its index `(organization_id, team_id)` (`org_invitation_organization_id_team_id_idx`); `team_id` is a plain field in the ORM, so `InvitationTeamKeyInSchema` adds the key and index to the ORM schema for the diff check; `CHECK` on email, org_roles, team_role and the team pair. Tenant table with `TenantTable::enableRls`; `pinround_app` has `SELECT, INSERT, UPDATE, DELETE`, `pinround_readonly` has `SELECT`; `resolver_read` with `SELECT (id, organization_id, token_hash, accepted_at, expires_at)` for `resolve_invitation`. Created by `Version20261109100000` |
| org_settings | organization_id PK, version `int NOT NULL DEFAULT 1` (Doctrine's optimistic lock), data `jsonb`, created_at | One row per org. Data keys and the defaults of a new row (Admin screen defaults): `ping_limit_hours` 4, `auto_clear` true, `quiet_hours` false, `lead_check` false, `triager_id` the first admin, `step_policy` `extend` (`locked`/`extend`/`team`), `ai` {on true, who `everyone` (or `leads`), send `title_and_text` (or `title`), sync true, where `cloud` (or `laptop`, `laptop_then_cloud`), model `qwen3:4b`}, `retention` `2y` (`1y`/`2y`/`all`) |

### Workflow

| Table | Columns | Constraints / indexes |
|---|---|---|
| wf_step | id, organization_id, team_id NULL, name, grp (`todo`/`doing`/`check`/`wait`/`done`), core_code NULL (`backlog`, `todo`, `progress`, `review`, `test`, `input`, `hold`, `done`), origin (`core`/`company`/`team`), position, archived_at NULL (when the step was removed); no created_at, like tkt_project | `PK (organization_id, id)`; `(organization_id, team_id, grp, position)` (`wf_step_organization_id_team_id_grp_position_idx`); `UNIQUE (organization_id, team_id, core_code) NULLS NOT DISTINCT WHERE core_code IS NOT NULL` (`wf_step_organization_id_team_id_core_code_key`; core steps have no team, so NULL team ids must count as equal); `CHECK` on grp (`wf_step_grp_check`), core_code (`wf_step_core_code_check`) and origin (`wf_step_origin_check`: a core step has a core code and no team, a company step neither, a team step a team and no core code). Created by `Version20261110120000`, like wf_rule_set; both are tenant tables with `TenantTable::enableRls`, no foreign key to org_team, `pinround_app` has `SELECT, INSERT, UPDATE` and `pinround_readonly` has `SELECT` |
| wf_rule_set | organization_id, team_id NULL (NULL is the organization's rule set; a team row only under policy `team`), tester_moves_done bool, no_self_review bool, review_before_test bool; no created_at | No primary key: a key cannot hold `coalesce(team_id)`, so `UNIQUE (organization_id, team_id) NULLS NOT DISTINCT` (`wf_rule_set_organization_id_team_id_key`) keeps one row per organization and team instead. No entity maps it (`DbalRuleSets` writes it with `INSERT … ON CONFLICT (organization_id, team_id) DO UPDATE`; `switchRule`, used by `SetRule`, updates only the switched rule's column, chosen from a fixed map of the three rule codes, so two people switching different rules at once keep both changes, and a new team row starts as a copy of the organization's rules), so the doctrine `schema_filter` hides it from `doctrine:migrations:diff` |

### Ticketing

| Table | Columns | Constraints / indexes |
|---|---|---|
| tkt_project | id, organization_id, team_id NULL (null while the project is unlinked; `Version20261111100000`), key (`SHOP`), name, next_number int (the number the next ticket gets; starts at 1, `CHECK (next_number >= 1)`) | `UNIQUE (organization_id, key)` |
| tkt_ticket | id, organization_id, project_id, number int, key text, title, description, type (`bug`/`story`/`task`), priority (`blocker`/`critical`/`major`/`normal`/`minor`), priority_rank smallint (0 = blocker … 4 = minor, set with priority), step_id, previous_step_id NULL, assignee_id NULL, pending_assignee_id NULL, reviewer_id NULL, tester_id NULL, waiting_on_user_id NULL, waiting_on_label NULL, reporter_id, team_id NULL, visibility (`all`/`team`), release NULL, parent_id NULL, components `text[]`, check_reasons `text[]`, checked_at, checked_by, closed_as_duplicate_of NULL, closed_at NULL (set when the step's group becomes `done`, cleared on reopen), version, created_at, updated_at, search `tsvector` GENERATED STORED (key and title weight A, description B; `simple` configuration over `public.tkt_unaccent()`, an IMMUTABLE wrapper of `unaccent` with a named dictionary and a fixed `search_path`, owned by `pinround_migrator`; `Version20261112100000`. No entity field: `TicketSearchInSchema` adds the column and its GIN indexes to the ORM schema for the diff) | `UNIQUE (organization_id, project_id, number)`; `UNIQUE (organization_id, key)`; indexes: see [Index catalogue](#index-catalogue). Created by `Version20261111120000`, with `tkt_summary`; a tenant table with `TenantTable::enableRls`; `(organization_id, project_id)` has a foreign key to `tkt_project` (`tkt_ticket_project_fkey`, `Version20261111140000`); `pinround_app` has `SELECT, INSERT, UPDATE` (no DELETE) and `pinround_readonly` has `SELECT` |
| tkt_ticket_event | id, organization_id, ticket_id, actor_id, type, from_step, to_step, payload `jsonb`, at | Partitioned by `RANGE (at)` monthly; `PRIMARY KEY (id, at)` (a partitioned table's key must contain the partition column); `(organization_id, ticket_id, at DESC)`. Created by `Version20261111140000`. Monthly partitions `tkt_ticket_event_YYYYMM` in UTC (2026-11 to 2027-12 from `Version20261111150000`, later ones from the partition job), no `DEFAULT` partition, so a row past the last month fails. `(organization_id, ticket_id)` has a foreign key to `tkt_ticket` with `ON DELETE CASCADE` (`tkt_ticket_event_ticket_fkey`). A tenant table with `TenantTable::enableRls`; grants on the parent only: `pinround_app` `SELECT, INSERT`, `pinround_readonly` `SELECT`. No entity maps it (hidden by `schema_filter`) |
| tkt_duplicate_link | organization_id, ticket_id, candidate_id, score real, source (`fts`/`ai`), decided (`same`/`different`/NULL) | `PK (organization_id, ticket_id, candidate_id)` |
| tkt_summary | id, organization_id, ticket_id, text, source (`sync`/`ai`/`explanation`/`human`), author_id NULL, model NULL, is_current bool, created_at | `(organization_id, ticket_id, created_at DESC)`; partial `UNIQUE (organization_id, ticket_id) WHERE is_current`. Created by `Version20261111120000`, with `tkt_ticket`; a tenant table with `TenantTable::enableRls`; `pinround_app` has `SELECT, INSERT, UPDATE` (no DELETE) and `pinround_readonly` has `SELECT`. No entity maps it (hidden by `schema_filter`) |

Ticket numbers come from `tkt_project.next_number`. `next_number` holds the number the next ticket gets and starts at 1. The app runs `UPDATE … SET next_number = next_number + 1 RETURNING next_number - 1`, which returns the number taken, inside the create transaction: a row lock, short, per project ([ADR-0006](../03-adr/0006-identifiers.md)).

### Conversation

| Table | Columns | Constraints / indexes |
|---|---|---|
| cnv_message | id, organization_id, ticket_id, author_id, kind (`comment`/`question`), to_user_id NULL, body, resolved_at NULL, resolved_by NULL, created_at | `(organization_id, ticket_id, created_at)`; partial `(organization_id, to_user_id) WHERE kind='question' AND resolved_at IS NULL` |
| cnv_reply | id, organization_id, message_id, author_id, body, is_answer bool, created_at | `(organization_id, message_id, created_at)` |

### Review and Media

| Table | Columns | Constraints / indexes |
|---|---|---|
| rvw_round | id, organization_id, ticket_id, number int NULL (set when shared), kind (`report`/`checklist`/`review`/`draft`), owner_id, shared_at NULL, decided_at NULL, state (`open`/`closed`), version | `(organization_id, ticket_id, number)`; partial `UNIQUE (organization_id, ticket_id, owner_id) WHERE kind='draft' AND shared_at IS NULL` (one open draft per person per ticket) |
| rvw_screenshot | id, organization_id, round_id, media_id, position, variant_label (`before`/`after`/NULL), verdict (`looks_right`/`not_right`/NULL), note | `(organization_id, round_id, position)` |
| rvw_area | id, organization_id, round_id, name, position, verdict (`pass`/`needs_fix`/`question`/NULL), note, decided_by NULL, decided_at NULL | `(organization_id, round_id, position)`. A screen area of a review round. Saving a verdict never moves the ticket; when every area has a verdict, the tester is offered "Pass it" or "Needs changes"; "Question" asks the lead. |
| rvw_pin | id, organization_id, screenshot_id, number, x_pct numeric(5,2), y_pct numeric(5,2), note, verdict (`fixed`/`still_wrong`/NULL), source (`human`/`ai`), ai_state (`suggested`/`kept`/`dismissed`/NULL), author_id | `(organization_id, screenshot_id, number)`; `CHECK (x_pct BETWEEN 0 AND 100 AND y_pct BETWEEN 0 AND 100)` |
| rvw_check_item | id, organization_id, round_id, pin_id, state (`open`/`fixed`/`ok`/`not_fixed`), changed_by, changed_at | `(organization_id, round_id)` |
| med_object | id, organization_id, storage_key, sha256, mime, bytes, width, height, status (`pending`/`ready`/`rejected`), reject_reason, variants `jsonb` ({thumb: key, full: key}), uploaded_by, created_at | `(organization_id, sha256)`; `(organization_id, status, created_at)` |
| med_upload_intent | id, organization_id, user_id, purpose, max_bytes, mime_allowed `text[]`, storage_key, expires_at, used_at | `(organization_id, expires_at)`; purge after 1 day |

### Notification, Assist, Integration, Audit, AgentApi

| Table | Columns | Constraints / indexes |
|---|---|---|
| ntf_ping | id, organization_id, ticket_id, from_id, to_id, body, created_at, deliver_after NULL (quiet hours), cleared_at NULL, cleared_reason (`done`/`acted`) | `(organization_id, to_id, cleared_at, created_at DESC)` |
| ntf_notification | id, organization_id, user_id, ticket_id NULL, kind, data `jsonb`, read_at, created_at | Partitioned monthly; `(organization_id, user_id, read_at, created_at DESC)`; kept 90 days |
| ntf_mute | organization_id, user_id, ticket_id | `PK (organization_id, user_id, ticket_id)` |
| ast_explanation | id, organization_id, ticket_id, user_id, prompt, text, model, shared_at NULL, created_at | `(organization_id, ticket_id, user_id)` |
| ast_suggestion | id, organization_id, user_id, purpose (`intake`/`pins`), result `jsonb`, model, created_at | Purge after 7 days |
| ast_embedding | organization_id, ticket_id PK part, model, embedding `vector(n)` | HNSW index per org-size bucket. Optional; verify the pgvector index choice at implementation |
| ast_usage | id, organization_id, user_id, purpose, provider, model, tokens_in, tokens_out, ms, at | Partitioned monthly; no content columns |
| ast_local_package | id, organization_id, user_id, ticket_id, purpose, expires_at (5 min, single use), used_at | — |
| int_jira_connection | id, organization_id, base_url, auth_type, secret_enc `bytea`, key_id, status | — |
| int_project_link | id, organization_id, connection_id, jira_key, project_id | `UNIQUE (organization_id, jira_key)` |
| int_status_mapping | organization_id, project_link_id, jira_status, step_id | `PK (organization_id, project_link_id, jira_status)` |
| int_external_ref | organization_id, entity, entity_id, system, external_id, external_version | `UNIQUE (organization_id, system, external_id)`; `(organization_id, entity, entity_id)` |
| aud_event | id `uuid` (UUIDv7; its time is `at`), organization_id, at `timestamptz`, actor_kind `text` (`user`/`token`/`system`), actor_user_id `uuid` NULL, actor_token_id `uuid` NULL, action `text` (a dotted code such as `role.changed`), target_type `text` (a code), target_id `uuid` NULL, data `jsonb` (an object of ids and old/new setting values, never ticket text), ip_hash `bytea` NULL, user_agent_hash `bytea` NULL (HMACs) | Created by migration `Version20261107170000`. Partitioned by `RANGE (at)` monthly in UTC (`aud_event_YYYYMM`, 2026-11 to 2027-12, no `DEFAULT` partition, so a row past the last month fails); `PRIMARY KEY (id, at)`; `(organization_id, at DESC)`; `(organization_id, target_type, target_id, at DESC)`. `CHECK`: a `user` row has the user id only, a `token` row the token and its user, a `system` row neither; `data` is a JSON object. RLS on the parent and every partition. Grants on the parent only: `pinround_app` `SELECT, INSERT`, `pinround_readonly` `SELECT`; every right on each partition revoked from `PUBLIC`, `pinround_app` and `pinround_readonly`. Written through `Audit\Api\AuditLog` (`DbalAuditLog`) in the caller's transaction ([ADR-0026](../03-adr/0026-audit-log.md)) |
| api_idempotency_key | organization_id, key, token_id, response_hash, created_at | `PK (organization_id, key)`; purge after 24 h |
| messenger_outbox | Symfony Messenger Doctrine transport table (queue `outbox`): id, body, headers, queue_name, created_at, available_at, delivered_at | Created by migration `Version20261107150000` in Messenger's own layout (`auto_setup` off); `pinround_app` has `SELECT`, `INSERT`, `UPDATE`, `DELETE`. Not a tenant table: no `organization_id`, no RLS; each message carries its organization in a `TenantStamp`. No order within a second: Messenger stores `available_at` to the second and sorts by it alone, and on a small table PostgreSQL scans the heap, where a new row can fill the slot of a relayed and vacuumed one, so a handler must not rely on the order of events |
| messenger_failed | The `failed` transport (queue `failed`), same layout and grants as `messenger_outbox` | Its own table, so the outbox age alert reads only messages still to relay |
| processed_message | message_id `uuid` (the `MessageIdStamp` id, kept through the outbox, the relay, retries and `failed`), handler `text` (the stable name an async handler passes to `OnceOnlyHandling::runOnce`), processed_at `timestamptz` default `now()` | `PRIMARY KEY (message_id, handler)`, index on `processed_at` for the purge. Created by migration `Version20261107160000`; `pinround_app` has `SELECT`, `INSERT` only, `pinround_readonly` nothing. `ProcessedMessageLedger` inserts the row with `ON CONFLICT DO NOTHING` in the transaction of the handler's effect and skips the effect when no row was inserted (ADR-0009). Not a tenant table: message ids are UUIDv7 unique across organizations and a row holds no tenant data. No purge job yet: a chunked retention job (P11) deletes old rows ([ADR-0033](../03-adr/0033-valkey-transports-workers-and-outbox-relay.md#idempotency-key)) |

### Web

| Table | Columns | Constraints / indexes |
|---|---|---|
| web_home_focus | organization_id, user_id, focus `text` (`all`/`my_work`/`my_team`/`all_teams`/`admin`, the Home focus chip of FR-HOME-08), updated_at | `PRIMARY KEY (organization_id, user_id)`, its only index; `CHECK` on focus (`web_home_focus_focus_check`). Created by `Version20261108160000`; a tenant table with `TenantTable::enableRls`, no foreign key to `idn_user`; `pinround_app` has `SELECT, INSERT, UPDATE` (no DELETE: a choice is replaced, never removed), `pinround_readonly` has `SELECT`. One row per user per organization, written by one upsert (`DbalHomeFocusPreferences`), so the choice follows the user to every device. No entity maps it (hidden by `schema_filter`). Web's `web_` prefix: [ADR-0035](../03-adr/0035-web-owns-its-ui-preferences.md) |

## Read models

Read models are owned by Insight and rebuilt from events if needed (`bin/console insight:rebuild --org=…`).

### ins_ticket_turn (the most important table for speed)

One row per **open** ticket. The row is deleted when the ticket is done. Created by `Version20261110160000`; a tenant table with `TenantTable::enableRls`, `pinround_app` has `SELECT, INSERT, UPDATE, DELETE`, `pinround_readonly` has `SELECT`. No entity maps it (hidden by `schema_filter`); `(organization_id, ticket_id)` has a foreign key to `tkt_ticket` with `ON DELETE CASCADE` (`ins_ticket_turn_ticket_fkey`, `Version20261111120000`).

| Column | Meaning |
|---|---|
| organization_id, ticket_id | The ticket; `PRIMARY KEY (organization_id, ticket_id)` |
| team_id, project_id | For filters |
| grp | The group of the current step: `todo`, `doing`, `check` or `wait`, never `done` (`ins_ticket_turn_grp_check`) |
| turn_user_id NULL | Whose turn it is. NULL means an outside party (customer, owner) holds it, named by `turn_label` |
| turn_label NULL | Display text when there is no user ("Customer", "Product owner"). Exactly one of `turn_user_id` and `turn_label` is set (`ins_ticket_turn_turn_check`); a ticket that is nobody's turn has no row |
| kind | `answer`, `check`, `review`, `test`, `assign`, `input`, `decide`, `build` |
| base_score int | Kind + priority + release points (see [workflow-engine.md](workflow-engine.md#needs-you-kinds-and-score)) |
| waiting_since timestamptz | When the turn last changed. Drives "waiting 12d" and the time part of the score |
| check_user_id NULL | Set when the ticket needs a check (the lead or the triager) |
| visibility, team_id, reporter_id, assignee_id, pending_assignee_id, reviewer_id, tester_id, waiting_on_user_id | Copied from the ticket, so Home, Whose turn and workload can apply the [ticket visibility predicate](#ticket-visibility-predicate) without a join |

Indexes: see [Index catalogue](#index-catalogue).

Updated **synchronously** in the same transaction as the change, by `TurnProjector::refresh(TicketId)` (`DbalTurnProjector`, one upsert or delete; `waiting_since` starts again only when the turn person or label changes) ([ADR-0015](../03-adr/0015-turn-read-model.md)). Every change of the turn or of a column the row copies calls it: `team_id`, `project_id`, `grp`, `visibility`, `reporter_id`, `assignee_id`, `pending_assignee_id`, `reviewer_id`, `tester_id`, `waiting_on_user_id`, `check_user_id`, and the priority, release and risk check behind `base_score`. A stale visibility column would show a restricted ticket on Home and Whose turn. This list is wider than ADR-0015's update path, which names only the commands that change a turn (see the P02.T6 hand-off note in [phase 2](../05-plan/phase-02-projects-and-workflow.md)).

Home and "Whose turn?" read this table joined with `tkt_ticket_list_v`, a read-only SQL view owned by Ticketing (key, title, priority, release, step). Only Insight's query service may use that join ([ADR-0015](../03-adr/0015-turn-read-model.md)).

### Other Insight tables

- `ins_team_counts (organization_id, team_id, grp, step_id, visibility, open_count)` — the manager's "Teams at a glance". Counted per visibility, so a viewer outside the team gets only the `all` rows: restricted tickets never show up as numbers to people who may not see them. Updated by events, and corrected nightly by a full recount.
- `ins_project_counts (organization_id, project_id, visibility, open_count, done_count)` — the counts on the project rail of the Tickets page. Same visibility rule.
- Whose turn and workload counts read `ins_ticket_turn`, which carries `visibility` and the people columns, so they apply the [ticket visibility predicate](#ticket-visibility-predicate) in the same query.
- `ins_release_progress (organization_id, team_id, release, grp, count)` — the release bar.
- My reports needs no table: it reads `tkt_ticket` by `(organization_id, reporter_id, created_at DESC)` joined to `ins_ticket_turn` (through the Ticketing view `tkt_ticket_list_v`). It is keyset-paginated: open groups first (up to 50 cards), Done loaded on demand, 50 per page.

## Row-level security

Every tenant table gets the same policy. The app's database role has no `BYPASSRLS`, and `FORCE ROW LEVEL SECURITY` makes the table owner obey the policy too. The migration that creates the table passes the statements of `App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable::enableRls('<table>')` to `addSql()` (it accepts only a plain lowercase name of up to 63 characters and quotes it). These are the statements; the table rights are separate grants in the same migration (see below).

```sql
ALTER TABLE tkt_ticket ENABLE ROW LEVEL SECURITY;
ALTER TABLE tkt_ticket FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON tkt_ticket
  USING      (organization_id = NULLIF(current_setting('app.org_id', true), '')::uuid)
  WITH CHECK (organization_id = NULLIF(current_setting('app.org_id', true), '')::uuid);
```

**How the tenant is set**

- `App\SharedKernel\Application\TenantContext` holds the current `OrganizationId` (a lowercase canonical UUIDv7) for the request or message. The DBAL driver middleware `App\SharedKernel\Infrastructure\Doctrine\TenantMiddleware`, registered on every connection, runs `SELECT set_config('app.org_id', ?, true)` with the id bound, right after each outermost `BEGIN`, when `TenantContext` holds an organization. The third argument `true` makes it transaction-local, like `SET LOCAL`, and unlike `SET LOCAL` it accepts a bound parameter (no string building, no SQL injection). The setting ends with the transaction, which is safe with PgBouncer in transaction mode and with FrankenPHP worker mode. Nothing a connection's middlewares depend on (`TenantContext` and its `DoctrineTenantSwitchGuard`, `TenantFilterMiddleware`, the tracer and loggers) may reference that connection or the entity manager, not even as a service closure: such a loop makes the dumped dev and prod containers create the connection before they set its middlewares. They take them from the `doctrine` registry instead; `DefaultConnectionMiddlewareTest` checks the driver chain in dev, prod (debug off, as it runs) and test.
- **Contract:** set the tenant before the transaction begins. On the default connection, a switch to another organization while a transaction is open is refused (`TenantSwitchRefused`). Only setting the first tenant while a transaction is open, or a change seen by another connection such as `migrator`, waits for the next transaction. Nested transactions (savepoints) never set it again. If `BEGIN` or `set_config` fails, every connection's DBAL class `App\SharedKernel\Infrastructure\Doctrine\CloseOnFailedBeginConnection` (doctrine.yaml `wrapper_class`) closes the connection and rethrows the failure, when the failing `BEGIN` is the outermost one. Closing ends the half-begun transaction on the server and resets DBAL's transaction count, so the next transaction starts on a fresh connection instead of sending `SAVEPOINT` outside a transaction. A failed nested savepoint is not covered: the connection and its outer transaction stay, but DBAL's nesting level stays one too high (DBAL's own behaviour, not fixed; a unit test pins it). A statement outside a transaction (autocommit) never has a tenant, so it sees no tenant rows. `TenantContext` is reset by the kernel between requests, so no tenant survives into the next one.
- **Why `NULLIF`:** on a pooled connection that has already run a transaction-local setting, `current_setting('app.org_id', true)` returns an empty string `''` (not NULL) once that transaction is over. `''::uuid` would raise an error. `NULLIF(…, '')` turns it into NULL, `organization_id = NULL` is never true, so **no rows** match. This fails closed.
- **Two layers, two behaviours when no tenant is set.** The database layer fails closed silently: raw SQL without a tenant returns zero rows with no error (the P00.T3 isolation criterion). The application layer is loud: `TenantFilter`, the Doctrine SQL filter for `TenantOwned` entities, throws `TenantNotSet` for an ORM query when the current transaction has no tenant (also outside any transaction), so a missing tenant fails in tests instead of returning nothing.
- **`TenantFilter`:** adds `organization_id = NULLIF(current_setting('app.org_id', true), '')::uuid` (the `TenantSetting::CURRENT_ORG_SQL` constant) to every `TenantOwned` query. The SQL text is the same for every organization, so Doctrine's query cache entry is shared. Its only filter parameter is the `is_tenant_set` marker, which `TenantFilterMiddleware` sets at each outermost `BEGIN` (true when the transaction has an organization) and clears at `COMMIT` and `ROLLBACK`.
- **The tenant of a web request:** `App\Organization\UI\EnterChosenOrganization` runs on every main request at `kernel.request` priority 7, after the firewall (8) has authenticated the user. It reads the user's organization ids through `resolve_user_orgs` (one indexed query, outside any transaction) and sets `TenantContext` to the organization chosen in the session (key `organization.chosen_id`) when the user is still its member. Otherwise it removes the choice; the user's only organization is then chosen at once, and with two or more, or none, the request is sent to the picker `/org/choose`, except on the routes in the `organization.routes_without_organization` parameter (`config/services.yaml`: the picker, login and its code page, logout, password reset, profile, security, two-step setup, "Confirm it's you", theme, health and the dev gallery). The picker opts out of the request transaction and reads each organization's name in a short transaction of its own with that organization as the tenant (`OrganizationNames::namesOf`); a choice is checked by `OrganizationMemberVoter` against the resolver and stored in the session. It keeps the session id, because a new id would restamp the session's created time and push back the 30-day limit. `RequestTenantTest` proves the request transaction sees `app.org_id` of the chosen organization.
- **One transaction per web request:** `RequestTransaction` begins a transaction on every main request at `kernel.request` priority 6, just below `EnterChosenOrganization`'s 7, so `TenantContext` is already filled. It commits on the response, rolls back on an exception, and has a safety net on `kernel.finish_request` and `kernel.terminate`. A streamed body runs after the commit, so it sees zero tenant rows.
- **No ORM result cache:** the result cache is a `NullAdapter` in every environment, on purpose. The filter SQL holds no organization id, so a result cache key could not tell organizations apart. Never add a result cache for tenant data without a tenant-aware key.
- **Switching organization:** `TenantContext` refuses (`TenantSwitchRefused`) a switch to another organization, or `clear()`, while a database transaction is open or the EntityManager holds any entity. A loop over organizations must end the transaction, flush and clear first.
- **No server-side prepares:** `Pdo\Pgsql::ATTR_DISABLE_PREPARES` is on for every connection (PgBouncer-safe, one round trip per bound query).
- `tests/Integration/Tenancy/TenantIsolationTest` proves this on a test-only table `zz_tenant_probe`, which exists only in the test database while that test class runs (its fixture drops and recreates it through the migrator connection, then drops it; no migration creates it).
- **Messenger workers:** every handler runs inside the `doctrine_transaction` middleware. `App\SharedKernel\Infrastructure\Messenger\TenantStampMiddleware` sits directly before it on every bus (messenger.yaml; `MessengerBusMiddlewareTest` fails for a bus without it there) and reads the message's `TenantStamp` (`App\SharedKernel\Infrastructure\Messenger\TenantStamp`, which holds the organization id as a string). When `doctrine_transaction` opens the transaction, `TenantMiddleware` runs `set_config` as for any other transaction. The middleware runs at dispatch and at handling, so its rules hold for sync and async messages alike. Before them, on dispatch, `AddTenantStampMiddleware` (same namespace, directly before `RejectMessageForAnotherTenantMiddleware`) gives a message dispatched while an organization is set, and without a `TenantStamp`, a stamp for that organization, so it runs under it after a queue, the outbox relay or `dispatch_after_current_bus`. A message that has a stamp keeps it, a received message is left as it was sent, and with no organization set none is added (`AddTenantStampTest`). The cases:
  1. **No stamp** (dispatched with no organization set, or received without one): the message passes on unchanged and runs under whatever organization is set; in a worker that is none. A message without an org (system jobs) can only touch global tables or call the resolver functions below.
  2. **Stamp, no organization set, no open transaction and no entity in the EntityManager:** it sets the stamp's organization, passes the message on, and afterwards (also when the handler throws) clears the EntityManager and then `TenantContext` through `TenantSwitchGuard`, so no entity loaded for that organization stays for the next message. An EntityManager the message closed (a failed flush) is reset with `ManagerRegistry::resetManager()` instead of cleared, so the next message dispatched on the same bus without a worker in between (a command looping over organizations) gets an open one. A transaction the handler leaves open is rolled back; a message that otherwise succeeded then fails with a `LogicException`, because nothing written in it was saved.
  3. **Stamp, no organization set, but a transaction open or an entity in the EntityManager** (`TenantSwitchGuard` would refuse; for example a request without a tenant, such as a signup, queuing a message for the new organization): the message passes on without setting the organization. Queued, it gets the organization when a worker receives it (case 2). Handled at once, it runs with no tenant: raw SQL sees no tenant rows and an ORM query throws `TenantNotSet` (fails closed and loud).
  4. **Stamp, the same organization set** (a dispatch inside a web request or another handler): the message passes on and the organization stays set afterwards.
  5. **Stamp, another organization set:** `MessageForAnotherTenantRefused` is thrown before anything runs, also for a message routed to an async transport, because the middleware runs at dispatch too. Dispatching a message for organization B inside a request of organization A is refused on purpose; the organization never changes in the middle of a request or message. A message a handler dispatches with `DispatchAfterCurrentBusStamp` is refused the same way: `RejectMessageForAnotherTenantMiddleware` (same namespace) checks it against the organization set before `dispatch_after_current_bus` holds it back, because when it is released `TenantStampMiddleware` has already cleared the handler's organization and would see case 2. For that order every bus sets `default_middleware: false` and lists the framework's middleware by hand; `MessengerBusMiddlewareTest` pins the full middleware list of both `command.bus` and `event.bus`, including `AddTenantStampMiddleware`, `RejectMessageForAnotherTenantMiddleware`, `MessageIdStampMiddleware`, `RelayOutboxMessageMiddleware` and `TenantStampMiddleware`.
  - When rolling back a transaction a handler left open fails, `TenantStampMiddleware` logs a warning with the exception class, SQLSTATE and code only (never the message, which can quote row values) and closes the connection.
- **Worker mode** ([ADR-0008](../03-adr/0008-frankenphp-runtime.md)): one kernel serves many requests or messages, so state is reset between them by the container's `services_resetter` (every `kernel.reset` service). It runs `TenantContext::reset()` (clears the tenant without the switch guard), the DoctrineBundle registry's `reset()` (clears an open EntityManager, rebuilds a closed one through `resetManager()`) and `RequestTransaction::reset()` (rolls back a request transaction left open).
  - **Web (FrankenPHP):** `Kernel::handle()` runs `services_resetter` when the next main request starts, also after a request whose controller threw. `tests/Functional/Web/WorkerResetTest` proves it with a `KernelBrowser` on one kernel (`disableReboot()`): after a normal request and after one that threw with the EntityManager closed, the next request has no tenant and an open EntityManager.
  - **Messenger worker:** `messenger:consume` adds `ResetServicesListener`, which runs `services_resetter` after every message, failed or not; never start a worker with `--no-reset`. `TenantStampedMessageTest::theWorkerHandlesTheNextMessageNormallyAfterOneClosedTheEntityManager` runs the real command on the in-memory `test_queue`: an unstamped message (which `TenantStampMiddleware` does not clean up after) closes the EntityManager when row-level security refuses its row at the flush, and the next, stamped message still saves its row under its own organization. `withoutTheWorkerResetTheNextMessageFailsOnTheClosedEntityManager` runs the same queue with `--no-reset` and shows that next message failing with `EntityManagerClosed`. `tests/Integration/Messaging/WorkerStateBetweenMessagesTest` (the P00.T7 state-leak test) runs a message of organization A and then one of B in one `Worker` loop without that reset: the B handler sees only B's rows, `TenantContext` B, its own message id and an empty identity map, also when the A handler threw, and nothing is set after the loop.
  - **Bus alone** (a sync dispatch from a command, no worker in between): nothing resets services, so `TenantStampMiddleware` cleans up after a stamped message itself (case 2 above). `TenantStampedMessageTest::aMessageWhoseFlushClosedTheEntityManagerLeavesAnOpenOneForTheNextMessageOnTheBus` proves it. A sync dispatch inside a web request runs under the request's tenant and transaction (case 3 or 4), so a closed EntityManager stays closed until that request ends and the next one resets it.
- `bin/check-tenant-setting` (run by `composer lint`, so in CI) bans `SET`, `SET LOCAL`, `SET SESSION` and `RESET` of `app.org_id`; any `set_config` for `app.org_id` whose third argument is anything but the bare word `true` (`false`, `'off'`, `0`, a variable), in every file including the allowed ones; and any `set_config` for `app.org_id` outside `TenantConnection`, `TenantMiddleware`, the few test files that assert or simulate them, the backfill migration `Version20261110140000` (plain SQL with no `TenantContext`, writing each organization's core steps under that organization) and the check itself. The setting counts whether it is written literally or as a `TenantSetting::NAME` or `self::NAME` constant concatenated into the string, with its quotes plain or backslash-escaped in either form. It scans `src`, `config`, `migrations`, `docker`, `tests` and `bin` (`.sql`, `.php`, `.yaml`, `.yml`, `.sh`, `.neon`, and files without an extension that start with `#!` or `<?php`); a path given on the command line that does not exist is an error. Reading it with `current_setting('app.org_id', true)` is fine. `TenantSettingCheckTest` proves it on fixtures and on a copy of `TenantConnection` with its third argument flipped.

**Database roles** ([ADR-0005](../03-adr/0005-multi-tenancy.md)) — the same five names everywhere:

| Role | Login | BYPASSRLS | Used by |
|---|---|---|---|
| `pinround_app` | yes | no | web, API, MCP, workers |
| `pinround_migrator` | yes | no | migrations and partition creation (owns the tables; `FORCE RLS` still applies to its data access) |
| `pinround_retention` | yes | **yes — the only one** | the nightly retention purge job only |
| `pinround_readonly` | yes | no | support queries and reporting, with the same tenant setting |
| `pinround_resolver` | **no** | no | nobody logs in as it; it owns the resolver functions only (see "Lookups before the tenant is known" below) |

The roles live in `docker/postgres/roles.sql`. The Postgres admin account runs it on each database before migrating (`make db-roles` in `make up`, a step in CI, the operator in production), with the four login passwords as psql variables. It is safe to re-run. It creates missing roles and re-asserts their attributes. It makes `pinround_migrator` the only member of `pinround_resolver`, with `SET` but without `INHERIT`: the migrator can hand a function to that role, but never acts with its rights or policies. It removes any other member. It makes `pinround_migrator` the owner of the database and of every table, view and sequence in `public`. It revokes all rights of `PUBLIC` on the database and on schema `public`, so only the four login roles get `CONNECT` and nobody but the owner gets `CREATE` or `TEMPORARY`. It grants schema `USAGE` to `pinround_app`, `pinround_readonly`, `pinround_retention` and `pinround_resolver`, and revokes `CREATE` on the schema from `pinround_resolver`. It grants **no table or sequence rights**, sets no default privileges, and removes any that an older version of the script left, including all rights of `pinround_readonly` and every right but `SELECT` of `pinround_app` on `doctrine_migration_versions`.

**Table rights live in the migration that creates the table.** That migration grants `pinround_app` and `pinround_readonly` exactly what they need, for example `SELECT, INSERT` only for an append-only table. It never grants `TRUNCATE`, `REFERENCES` or `TRIGGER`, and never grants on a child partition (only the parent). A plain sequence (not an identity column, which needs no grant) gets `USAGE` in the same migration. Re-running `roles.sql` never widens these grants. `make test` and CI run it once more on the migrated test database before PHPUnit, to test this. `DatabaseRolesTest` checks five things on that database:

- No default privilege grants anything to `pinround_app` or `pinround_readonly`.
- A plain table and a child partition the migrator creates start with no rights, and a partitioned parent has only what was granted on it.
- No child partition in `public` has a grantee other than its owner. `theAuditParentAloneHasGrantsAndTheAppMayOnlyReadAndAdd` pins the exact grants of `aud_event` and checks its 14 partitions have none.
- Neither role holds `TRUNCATE`, `REFERENCES` or `TRIGGER` on anything in `public`.
- On `doctrine_migration_versions`, `pinround_app` has only `SELECT` (`Version20261107180000`, for the migrations check of `/health/ready`) and no other role but the owner has any right. `pinround_retention` gets its table rights from the retention job's migration (P11).

**Lookups before the tenant is known (the only RLS exceptions)**

Some lookups must find a row before the app knows which organization the request belongs to. They do not get `BYPASSRLS`. Each one is a `SECURITY DEFINER` SQL function owned by a role without login (`pinround_resolver`), with `SET search_path = pg_catalog, public, pg_temp` fixed in the function definition (`pg_temp` last, so a temporary table never shadows a table it reads). It takes one exact key, named with a `p_` prefix: in a `LANGUAGE sql` body a column of the same name wins over the parameter, so `token_hash = token_hash` would match every row, and `CREATE OR REPLACE` cannot rename an input later. It **returns ids only** (`uuid` columns; never names, text, secrets, dates or status). The caller then sets the tenant and reads scopes, dates, status and org roles under the normal policy. `pinround_app` gets `EXECUTE` on these functions and nothing else from the owner role; `PUBLIC` and the other roles get no `EXECUTE`.

| Function | Input | Returns | Used by |
|---|---|---|---|
| `resolve_api_token(p_token_hash bytea)` | hash of a presented PAT | `token_id, organization_id, user_id` | API and MCP authentication |
| `resolve_user_orgs(p_user_id uuid)` | the logged-in user | `organization_id`, one row per organization | org picker after login, membership checks |
| `resolve_invitation(p_token_hash bytea)` | hash of an invite token | `invitation_id, organization_id` | accepting an invite |
| `resolve_sso_provider(p_issuer text, p_domain text)` | issuer or email domain at login | `provider_config_id, organization_id` | company login start and callback |
| `resolve_jira_connection(p_connection_id uuid)` | id from the webhook URL | `organization_id` | Jira webhook intake |
| `resolve_laptop_package(p_package_id uuid)` | id of a laptop-AI package | `organization_id, user_id` | laptop AI result intake |
| `search_ticket_ids(p_organization_id uuid, p_query text, p_viewer_id uuid, p_team_ids uuid[], p_is_admin boolean, p_is_triager boolean)` | the tenant, the search text and the viewer's visibility inputs | `ticket_id`, at most 1,000, best first | ticket search (see [Query patterns](#query-patterns)); not a pre-tenant lookup, it returns nothing unless `p_organization_id` is the transaction's tenant |

- **Where they live:** migration `Version20261106140000` creates all six as stubs that return no row, with these signatures. The names are listed once in PHP, `App\SharedKernel\Infrastructure\Doctrine\Migration\ResolverFunctions::NAMES`, which the tests read. The phase that adds a function's table replaces its body with `CREATE OR REPLACE FUNCTION`, keeping the signature (and so the `EXECUTE` grants). `resolve_user_orgs` got its body in `Version20261108140000`: the `organization_id` of every `org_membership` row of the user, in id order (`ResolveUserOrgsTest`). `resolve_invitation` got its body in `Version20261109100000`: the ids of the open `org_invitation` row with that token hash, that is not accepted and with `expires_at > now()` (`ResolveInvitationTest`).
- **How a resolver reads under `FORCE ROW LEVEL SECURITY`:** `pinround_resolver` has no `BYPASSRLS`, so the tenant policy applies to it too. The migration that creates a table a resolver reads adds the statements of `TenantTable::allowResolverRead('<table>', [<columns>])` after `enableRls`: an opt-in policy `resolver_read ... FOR SELECT TO pinround_resolver USING (true)` and `GRANT SELECT (<columns>)` to `pinround_resolver`. Grant only the columns a resolver returns or matches on, and on a partitioned table the parent only.
- **Owner and migrator:** a new owner needs `CREATE` on the schema, so the migration grants it to `pinround_resolver` just for `ALTER FUNCTION ... OWNER TO pinround_resolver` and revokes it again; the role keeps no `CREATE`. `EXECUTE` is granted before the owner change, because the migrator does not inherit the owner's rights. To replace or drop a function later, a migration runs `SET ROLE pinround_resolver` first (and, for `CREATE OR REPLACE`, grants and revokes `CREATE` the same way), then `RESET ROLE`. Because the migrator has no `INHERIT`, the `resolver_read` policy never applies to it.

`tests/Integration/Tenancy/ResolverFunctionTest` checks each function's owner, `SECURITY DEFINER`, fixed `search_path`, `p_` inputs, `uuid`-only output and `EXECUTE` rights, that an unknown key gives no row, and that `pinround_readonly` is refused. On a test-only table it proves a function owned by `pinround_resolver` finds another organization's row through `resolver_read`, cannot read an ungranted column, that an input named like its column matches every row while a `p_` input matches only its key, and that the migrator does not get the policy. That table and its functions are committed while the class runs, since `pinround_app` calls them on another connection; `CatalogTest` drops any a cut-short run left before each of its tests. When the tables arrive, every resolver gets a test: right key → ids; wrong key → no row; it can never return rows of another org for a guessed key. `tests/Integration/Tenancy/CatalogTest` (in CI's `integration` job) fails unless the `SECURITY DEFINER` functions in every schema but the system ones (`pg_catalog`, `information_schema`, `pg_toast` and the per-session temporary schemas; extension functions included, since the migrator owns the extensions and could hide a function in one) are exactly `ResolverFunctions::NAMES` in `public`, one per name, each owned by `pinround_resolver`, and fails on any function `pinround_resolver` owns that `NAMES` does not list.

**Partitioned tables:** grants are given on the **parent** table only. `roles.sql` revokes nothing on partitions and migrations never grant on a child partition, so `pinround_app` and `pinround_readonly` cannot query a partition directly and skip the parent's policy. The one partition job so far is `Ticketing\Infrastructure\Scheduler\AddTicketEventPartitions` (daily, three months ahead, health check `next_ticket_event_partition`; outside prod only, so prod needs a `pinround_migrator` runner before 2027-12, see [Partitions and retention](#partitions-and-retention)); a partition job must run `TenantTable::enableRls` on every child partition it creates as well as on the parent, and `CatalogTest` fails on a child without it: a query through the parent applies the parent's policy, but a direct query on a child uses the child's own settings, and PostgreSQL copies neither the RLS flags nor the policy to a new partition. `allowResolverRead` stays on the parent only. `DatabaseRolesTest::noChildPartitionInTheSchemaHasDirectGrants` reads `pg_class.relispartition` and fails on any grant on a child partition to a role other than its owner.

- `TenantFilter` adds the same `organization_id = NULLIF(current_setting('app.org_id', true), '')::uuid` condition to ORM queries. Both checks must agree; a CI test turns the filter off and expects RLS to still block ([testing-strategy.md](testing-strategy.md)).
- `tests/Integration/Tenancy/CatalogTest` reads the catalog as `pinround_app` in CI's `integration` job. A tenant table is a table with an `organization_id` column in any schema but the system ones (`pg_catalog`, `information_schema`, `pg_toast` and the per-session temporary schemas), plus every partition or inheritance child below it; tables an extension owns count too. The test fails when a tenant table lacks `ENABLE` or `FORCE ROW LEVEL SECURITY`, or lacks a `tenant_isolation` policy identical (permissive, `FOR ALL`, `TO PUBLIC`, same `USING` and `WITH CHECK`) to the one `TenantTable::enableRls` writes; when a tenant table has any other policy that is not identical to the `resolver_read` of `TenantTable::allowResolverRead`; when a role other than `pinround_retention` has `BYPASSRLS` (superusers left out: they bypass RLS anyway, and the only one is the admin account that runs `roles.sql`); when `pinround_resolver` holds a table-wide privilege on any relation, any default privilege, or a column grant that is not `SELECT` on a table with a `resolver_read` policy; and when `pinround_app` is a member of any role. Each table, policy, function and privilege rule is proved to fire on a throwaway object the migrator creates in a rolled-back transaction. The migrator cannot create roles or grant membership, so the two role rules are proved on roles that exist: with `pinround_migrator` as the allowed role the `BYPASSRLS` rule reports `pinround_retention`, and the membership rule run for `pinround_migrator` reports `pinround_resolver`.

## Ticket visibility predicate

This is the **one canonical rule** for who may see a ticket with `visibility = 'team'` (a restricted, security-sensitive ticket). It is defined here and in [ADR-0013](../03-adr/0013-authorization.md); every other document references it instead of restating it. In code it lives in one place: `App\Ticketing\Api\TicketVisibility` (SQL fragment + PHP check used by `TicketVoter`), so list, search, duplicate suggestions, My reports, Home, Whose turn, workload counts, Mercure signals, emails, AI inputs, MCP tools and exports all use the same rule.

```sql
-- :me = current user, :teams = ids of teams the user belongs to (any team role),
-- :is_admin = user has the org role Admin, :triager = org setting triager_id
(   t.visibility = 'all'
 OR t.team_id = ANY(:teams)
 OR :me IN (t.reporter_id, t.assignee_id, t.pending_assignee_id,
            t.reviewer_id, t.tester_id, t.waiting_on_user_id)
 OR :is_admin
 OR (t.team_id IS NULL AND :me = :triager) )
```

- A reporter outside the team keeps seeing their own security report, and can answer a question sent to them.
- A non-admin triager sees restricted tickets that have no team yet.
- Managers see restricted tickets only if one of the lines above is true for them.
- A personal access token never widens this rule; it can only narrow it with scopes.
- Counts follow the same rule: read models keep `visibility` (and the people columns) so counts for outsiders include only `all` tickets ([Read models](#read-models)).
- In code (P03.T2): `TicketVisibility::isVisible()` is the PHP check; `TicketVisibilitySql::of($visibility, $alias)` renders the SQL above, inside `alias.organization_id = :t_visible_org AND (...)`, with named parameters prefixed by the alias (`:t_visible_me`), so two uses in one query do not collide. Every parameter is a string that the SQL casts: `:teams` is one `uuid[]` literal, and `:is_admin` and `:is_triager` (`:me = :triager`, computed in PHP) are `'true'` or `'false'` cast to boolean, so a caller binds them with no types. Both live in `Ticketing\Api`, so Insight filters `ins_ticket_turn` with the same condition; any module gets a viewer's predicate from `TicketVisibilities::of(OrganizationId, UserId)`. The condition is true for a visible row and false or NULL for a hidden one (a NULL people column makes the `IN` list NULL): filter with it, never negate it. `tests/Unit/Architecture/HandWrittenVisibilityConditionTest` (the scanner is `HandWrittenVisibilityConditions`) fails on: a string literal in `src/**/*.php` where `visibility` or `alias.visibility` in SQL or DQL, bare, with a `::type` cast or as `CAST(visibility AS type)` / `CAST(alias.visibility AS type)`, is followed by `=`, `<>`, `!=`, `IN`, `NOT IN` or `IS`, or comes after `=`, `<>` or `!=`, or switches on it with `CASE visibility WHEN`; a `'visibility' =>` or `'alias.visibility' =>` key in the first argument of a `findBy`, `findOneBy` or `count` method call (only the first argument: an `orderBy` sort key and PHP's own `count()` are not flagged); and `'visibility'` or `'alias.visibility'` as the first argument of a Criteria or query-builder `expr()` method (`eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `in`, `notIn`, `isNull`, `isNotNull`, `contains`, `memberOf`, `startsWith`, `endsWith`). Comments, a `CAST` in a `SELECT` list and other PHP code are not read, and migrations are outside `src/`. `HandWrittenVisibilityConditions::ALLOWED` exempts `TicketVisibilitySql`, which renders the predicate, and `DbalTurnProjector`, which writes the column.

## Index catalogue

The single list of indexes on the hot tables. Other documents link here instead of repeating them. Every index starts with `organization_id`; a GIN index holds it through the `btree_gin` extension.

| Table | Index | Serves |
|---|---|---|
| tkt_ticket | `(organization_id, project_id, step_id, priority_rank, updated_at DESC, id DESC)` | list of one project, grouped by step, keyset |
| tkt_ticket | `(organization_id, step_id, priority_rank, updated_at DESC, id DESC)` | list across "All projects" of a team |
| tkt_ticket | `(organization_id, reporter_id, created_at DESC, id DESC)` | My reports |
| tkt_ticket | `(organization_id, assignee_id) WHERE closed_at IS NULL` | person filter, workload |
| tkt_ticket | GIN `(organization_id, search)` (`tkt_ticket_search_idx`); GIN `(organization_id, title gin_trgm_ops)` (`tkt_ticket_title_trgm_idx`), both through `btree_gin` | search and duplicates (see the RLS note under [Query patterns](#query-patterns)) |
| tkt_ticket_event | `(organization_id, ticket_id, at DESC)` | "What happened" |
| ins_ticket_turn | `(organization_id, turn_user_id, base_score DESC)` | Home, part 1 |
| ins_ticket_turn | `(organization_id, check_user_id, base_score DESC) WHERE check_user_id IS NOT NULL` | Home, part 2 (checks) |
| ins_ticket_turn | `(organization_id, team_id, turn_user_id, waiting_since)` | Whose turn (team, all projects), workload |
| ins_ticket_turn | `(organization_id, project_id, turn_user_id, waiting_since)` | Whose turn filtered by project |
| ins_ticket_turn | `(organization_id, team_id, grp)` | manager bars |
| cnv_message | `(organization_id, ticket_id, created_at)`; partial `(organization_id, to_user_id) WHERE kind='question' AND resolved_at IS NULL` | thread, open questions |
| ntf_ping | `(organization_id, to_id, cleared_at, created_at DESC)` | bell, pings tray |
| rvw_screenshot / rvw_pin | `(organization_id, round_id, position)` / `(organization_id, screenshot_id, number)` | rounds |

## Query patterns

All examples run as `pinround_app` with the tenant set, and all add the [ticket visibility predicate](#ticket-visibility-predicate) (written `<visible(t)>` below).

**Ticket list grouped by step — one query for all steps** (the list budget is ≤ 5 queries per page, so no query per step). A `LATERAL` subquery takes the first 51 rows of each step (51 = 50 shown + 1 to know there is more); "Show more" in one step then uses keyset pagination for that step only:

```sql
SELECT listed.step_id, c.ticket_count, x.*
FROM unnest(CAST(:step_ids AS uuid[])) WITH ORDINALITY AS listed(step_id, list_position)  -- Workflow's cached StepQueries::listedSteps, in list order
CROSS JOIN LATERAL (                           -- the step's count, capped: at most 1,001 rows, in the index's order
  SELECT count(*) AS ticket_count FROM (
    SELECT 1 FROM tkt_ticket t
    WHERE t.organization_id = :org AND t.project_id = ANY(:projects) AND t.step_id = listed.step_id AND <visible(t)>
    ORDER BY t.priority_rank, t.updated_at DESC, t.id DESC
    LIMIT 1001
  ) capped
) c
CROSS JOIN LATERAL (
  SELECT t.id, t.key, t.title, t.priority_rank, t.assignee_id, t.updated_at
  FROM tkt_ticket t
  WHERE t.organization_id = :org
    AND t.project_id = ANY(:projects)          -- one project, or all of the team's projects
    AND t.step_id = listed.step_id
    AND <visible(t)>
  ORDER BY t.priority_rank, t.updated_at DESC, t.id DESC
  LIMIT 51
) x
ORDER BY listed.list_position;
```

**"Show more" in one step** (keyset, no `OFFSET`):

```sql
... AND t.step_id = :step
    AND (t.priority_rank > :last_rank
         OR (t.priority_rank = :last_rank AND (t.updated_at, t.id) < (:last_updated, :last_id)))
ORDER BY t.priority_rank, t.updated_at DESC, t.id DESC
LIMIT 51;
```

The step count is the same capped `LATERAL` on the first page and after a cursor. A count of 1,001 means more than 1,000, which the page shows as "1,000+", so a step that holds most of the table (Done) never reads all its rows.

The list never joins `wf_step`: the step ids come from Workflow's `StepQueries::listedSteps`, cached per organization and dropped by any workflow change, so the first load after a step change reads one more query.

The cursor is sent to the browser unsigned: it only names a position (the step, rank, time and id of the last row shown), and the visibility predicate still runs on the page it asks for, so a forged cursor shows nothing the viewer may not see. The turn users of the shown rows are read in one batch from `ins_ticket_turn` through Insight's `TurnUserQueries::turnUsersOf`, not through `tkt_ticket_list_v`.

**Home "needs you"** — two index scans, no full read of the user's turns. The time part of the score is `LEAST(days_waiting, 60) × 0.5`, so it is at most 30 points. A row whose `base_score` is more than 30 below the 5th best `base_score` can never reach the top 5:

```sql
WITH cand AS (
  (SELECT ticket_id, kind, base_score, waiting_since FROM ins_ticket_turn tt
   WHERE organization_id = :org AND turn_user_id = :me AND <visible(tt)>
   ORDER BY base_score DESC LIMIT 100)
  UNION ALL
  (SELECT ticket_id, 'check', base_score, waiting_since FROM ins_ticket_turn tt
   WHERE organization_id = :org AND check_user_id = :me AND <visible(tt)>
   ORDER BY base_score DESC LIMIT 100)
), cut AS (
  SELECT base_score - 30 AS floor FROM cand ORDER BY base_score DESC OFFSET 4 LIMIT 1
)
SELECT ticket_id, kind,
       base_score + LEAST(EXTRACT(EPOCH FROM (now() - waiting_since)) / 86400, 60) * 0.5 AS score
FROM cand
WHERE base_score >= COALESCE((SELECT floor FROM cut), -1)
ORDER BY score DESC
LIMIT 5;
```

The "Also waiting on you" groups come from a **separate count query** (`SELECT kind, count(*) … GROUP BY kind`, same filters), so no limit can cut the counts. A group's items are loaded when it is opened (keyset, 20 at a time). If a ticket comes from both parts (my turn and my check), the app keeps it once, with the higher score. Titles are loaded in one batch (`WHERE id = ANY(:ids)`), never one by one. (`<visible(tt)>` uses the people and visibility columns copied into `ins_ticket_turn`.)

**Duplicate candidates** while typing — take 20 candidates by rank, apply the word rule of FR-INT-15 in PHP, then keep the top 3. Taking only 3 first could return none after the rule:

```sql
SELECT id, key, title,
       ts_rank(search, websearch_to_tsquery('simple', :q)) AS rank,
       similarity(title, :title) AS sim
FROM tkt_ticket t
WHERE t.organization_id = :org
  AND t.closed_at IS NULL
  AND <visible(t)>
  AND (t.search @@ websearch_to_tsquery('simple', :q) OR t.title % :title)
ORDER BY (rank * 0.6 + sim * 0.4) DESC
LIMIT 20;
```

**RLS and these indexes (important).** The text-search operator `@@`, the trigram operator `%` and `similarity()`, and the pgvector distance operators are **not `LEAKPROOF`**. Under row-level security PostgreSQL may then refuse to use the GIN or HNSW index before the policy is applied, and scan the whole organization instead. So:

1. A CI test runs `EXPLAIN` on the search, duplicate and embedding queries **as `pinround_app` with the tenant set**, on a seeded database, and fails if the plan does not use the GIN/HNSW index. For search and duplicates this is `TicketSearchQueryPlanTest` (50,000 tickets, the visibility predicate applied). It also explains each query as the table owner without RLS, where both GIN indexes must be used. Its first run (2026-11-12) showed that under RLS the planner skips both GIN indexes and reads the organization by sequential or B-tree scan, while without RLS it uses them, so the policy is the cause; the RLS case is marked incomplete until the fallback below lands.
2. If it does not, the query moves into a `SECURITY DEFINER` function (owner `pinround_resolver`, fixed `search_path`) that takes the organization id and the visibility inputs as explicit `p_`-prefixed arguments (`p_organization_id`, never `organization_id`), filters on them first, and returns ticket ids only. It is listed with the resolver functions in [Row-level security](#row-level-security).
3. Search uses this fallback (2026-11-12): `search_ticket_ids` (`Version20261112120000`) matches `search @@ websearch_to_tsquery('simple', tkt_unaccent(p_query))`, `title % p_query` or the exact key (`upper(btrim(p_query))`), applies the visibility predicate written out in SQL, then keeps the best 1,000 (exact key, then `ts_rank × 0.6 + similarity × 0.4`, then id). It reads tkt_ticket through `resolver_read` with `SELECT` on the matched and predicate columns only, `title` and `search` included as match columns, never returned. `TicketSearchQueryPlanTest` proves the GIN and key indexes are used (the body explained as `pinround_resolver`, and the real call's scan counts from `pg_stat_get_xact_numscans`); `TicketSearchFunctionLeakTest` proves it finds what `TicketVisibilitySql` shows for each viewer of the cast. Duplicate candidates still run as `pinround_app` (incomplete in the test, P06).

The thresholds are tuned in phase 6. The text-search configuration ('simple' or a language) is chosen per org ([ADR-0022](../03-adr/0022-search-and-duplicates.md)).

**"What happened" (last 6 events)** — add a lower bound so the planner skips old partitions:

```sql
SELECT … FROM tkt_ticket_event
WHERE organization_id = :org AND ticket_id = :ticket AND at >= :ticket_created_at
ORDER BY at DESC LIMIT 6;
```

## Partitions and retention

| Table | Partition | Kept |
|---|---|---|
| tkt_ticket_event | monthly by `at` | per org retention (1y / 2y / always) |
| aud_event | monthly by `at` | per org retention, minimum 1 year for security events |
| ntf_notification | monthly by `created_at` | 90 days |
| ast_usage | monthly by `at` | 13 months (billing and limits) |

- The scheduler creates partitions three months ahead. It runs as the `pinround_migrator` role. For `tkt_ticket_event` this is `Ticketing\Infrastructure\Scheduler\AddTicketEventPartitions` (daily, on the `default` schedule); the health check `next_ticket_event_partition` fails when next month's partition is missing. The job is `#[WhenNot('prod')]`: it uses the migrator connection, which prod does not have. Prod has only the migrations' partitions, 2026-11 to 2027-12 (`Version20261111140000`, `Version20261111150000`), and needs a runner as `pinround_migrator` before 2027-12 ([phase-03 hand-off notes](../05-plan/phase-03-tickets-core.md#hand-off-notes)).
- **Retention** is per organization. Because some orgs choose "always", partitions of `tkt_ticket_event` are normally **not dropped**; the purge deletes rows instead (below). Partitions of `ntf_notification` and `ast_usage` have one fixed lifetime for everyone and are dropped whole. A partition of `aud_event` or `tkt_ticket_event` may be dropped only when no org with a longer retention has rows in it (checked by the job).
- **Ticket events follow the ticket:** events are deleted only together with their closed ticket (§10.3 rule: open tickets and their history are never deleted). The nightly job, as `pinround_retention`, finds closed tickets older than the org's cutoff and deletes, in chunks of 5,000 rows, their events, messages, rounds, pins and media references, then the tickets. Audit events are purged by `at < cutoff` per org, with a minimum of 1 year for security events.
- The purge writes its own audit event per org and run.
- Tables no entity maps are **excluded from the Doctrine schema diff** by the `schema_filter` regex on the default and migrator connections in `config/packages/doctrine.yaml`, and their DDL lives in hand-written migrations. Today it hides `processed_message`, `idn_login_attempt`, `web_home_focus`, `wf_rule_set`, `ins_ticket_turn`, `tkt_summary`, `aud_event` and its partitions `aud_event_YYYYMM`, `tkt_ticket_event` and its partitions `tkt_ticket_event_YYYYMM`; `ntf_notification` and `ast_usage` join it with their migrations. The Messenger tables stay visible: Messenger adds them to the expected schema. `DatabaseRolesTest::doctrineMigrationsDiffFindsNoChanges` runs the diff's comparison and fails on any difference.
- Open tickets are never deleted, whatever their age. Media is deleted from the bucket by a worker. Bucket lifecycle rules catch orphans after 7 days.
- **Export** before deletion is the admin's job. pinround shows a warning 30 days before the first purge after a change.

## Migrations policy

- Doctrine Migrations, one migration per change, reviewed like code.
- **Expand / contract** for zero downtime ([ADR-0030](../03-adr/0030-deployment-model.md)):
  1. expand — add the column or table (nullable or with a default) and deploy code that writes both;
  2. backfill in batches with a Messenger job;
  3. switch reads;
  4. contract — drop the old column in a later release.
- **Never in one migration:** rename a column that is in use, add `NOT NULL` to a big table without a default, create an index without `CONCURRENTLY` on a big table. (`CREATE INDEX CONCURRENTLY` cannot run in a transaction, so such migrations disable the transaction wrapper. Verify the Doctrine Migrations option at implementation.)
- Every new tenant table must come with: an `organization_id` column, the RLS policy, an index that starts with `organization_id`, and a tenant-isolation test. `CatalogTest` fails on a tenant table or partition without the RLS flags and policy (see [Row-level security](#row-level-security)); no check enforces the index yet.

## Size estimates

At the design point in [non-functional-requirements.md](../01-requirements/non-functional-requirements.md): 500 orgs, 50k users, 2M tickets, 20M screenshots. These are planning numbers, not promises.

| Table | Rows | Avg row | Data + indexes |
|---|---|---|---|
| tkt_ticket | 2 M | ~1.5 KB (text + tsvector) | ~5 GB |
| tkt_ticket_event | 40 M (≈20 per ticket) | ~300 B | ~16 GB (partitioned) |
| cnv_message + cnv_reply | 12 M | ~600 B | ~9 GB |
| rvw_round | 6 M | ~150 B | ~1.5 GB |
| rvw_screenshot | 20 M | ~200 B | ~6 GB |
| rvw_pin | 60 M (≈3 per screenshot) | ~200 B | ~18 GB |
| med_object | 20 M | ~350 B | ~9 GB |
| ins_ticket_turn | ~400 k open | ~200 B | < 0.3 GB |
| ntf_notification (90 days) | ~90 M | ~250 B | ~30 GB (partitioned, rolling) |
| aud_event | ~2 M per month | ~400 B | ~1 GB per month |
| ast_embedding (optional, 384 dims) | 2 M | ~1.6 KB | ~4 GB + HNSW index |
| **Total in PostgreSQL** | | | **~100–130 GB** |
| Object storage (originals + WebP variants) | 20 M objects | ~300 KB | **~6 TB** |

The database fits on one good managed PostgreSQL instance. The biggest tables are partitioned history and notifications, which retention keeps flat. See [performance-and-scale.md](performance-and-scale.md) for the capacity plan.
