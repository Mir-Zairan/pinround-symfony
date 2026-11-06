# Phase 00 — Foundation

Goal: a running, tested, secure skeleton that every later phase builds on: Docker, Symfony 8.1, modules, CI gates, tenancy with row-level security, design tokens and base components.

## Index
- [Before you start](#before-you-start) — prototype parts, requirements, ADRs and docs to read
- [Outcome](#outcome) — what works after this phase
- [Scope](#scope) — what this phase builds
- [Out of scope](#out-of-scope) — what waits for later phases
- [Requirements covered](#requirements-covered) — NFR, C and FR ids
- [Design notes](#design-notes) — services, folders, tenancy flow, CI stages
- [Tasks](#tasks) — P00.T1 to P00.T9, one session each
- [Definition of done](#definition-of-done) — phase checklist
- [Hand-off notes](#hand-off-notes) — notes for P01

## Before you start
1. **Prototype.** Open https://claude.ai/artifact/FmbJosB3s5mDi75cLqMFQ2. In Claude Code use the Artifact tool: `action: "read"`, that `url`, `path: "project/Main.dc.html"`. Study the `<style>` block (tokens, light and dark) and the header. If you cannot open it, read the [prototype guide](../04-prototype/prototype-guide.md): [Global layout](../04-prototype/prototype-guide.md#global-layout), [Design tokens](../04-prototype/prototype-guide.md#design-tokens), [Component classes](../04-prototype/prototype-guide.md#component-classes).
2. **Requirements:** [NFR-SEC](../01-requirements/non-functional-requirements.md#sec--security) (NFR-SEC-03 tenant isolation, NFR-SEC-07 headers, NFR-SEC-09 no content in logs, NFR-SEC-10 secrets, NFR-SEC-11 dependency audit), [NFR-SCAL](../01-requirements/non-functional-requirements.md#scal--scalability) (NFR-SCAL-02, NFR-SCAL-03), [NFR-OPS](../01-requirements/non-functional-requirements.md#ops--operability) (NFR-OPS-01, NFR-OPS-02, NFR-OPS-04, NFR-OPS-05), [NFR-MNT](../01-requirements/non-functional-requirements.md#mnt--maintainability) (NFR-MNT-01, NFR-MNT-02, NFR-MNT-03, NFR-MNT-04, NFR-MNT-05), [NFR-A11Y-01](../01-requirements/non-functional-requirements.md#a11y--accessibility). Constraints [C-06](../01-requirements/constraints.md#c-06-php-and-symfony-versions), [C-10](../01-requirements/constraints.md#c-10-self-hosting-on-small-machines).
3. **ADRs:** [0002 modular monolith](../03-adr/0002-modular-monolith.md), [0003 versions](../03-adr/0003-php-and-symfony-versions.md), [0004 PostgreSQL](../03-adr/0004-postgresql.md), [0005 multi-tenancy](../03-adr/0005-multi-tenancy.md), [0006 identifiers](../03-adr/0006-identifiers.md), [0007 server-rendered UI](../03-adr/0007-server-rendered-ui.md), [0008 FrankenPHP](../03-adr/0008-frankenphp-runtime.md), [0009 messaging and outbox](../03-adr/0009-async-messaging-and-outbox.md), [0010 Valkey](../03-adr/0010-cache-sessions-locks.md), [0027 secrets](../03-adr/0027-secrets-and-encryption.md), [0028 quality gates](../03-adr/0028-testing-and-quality-gates.md), [0029 observability](../03-adr/0029-observability.md), [0030 deployment](../03-adr/0030-deployment-model.md).
4. **Architecture docs:** [modules: directory layout](../02-architecture/modules.md#directory-layout-and-namespaces), [Deptrac rules](../02-architecture/modules.md#deptrac-rules), [data model: general rules](../02-architecture/data-model.md#general-rules), [row-level security](../02-architecture/data-model.md#row-level-security), [request lifecycle](../02-architecture/architecture-overview.md#request-lifecycle), [frontend: design tokens](../02-architecture/frontend.md#design-tokens-and-theming), [Twig components](../02-architecture/frontend.md#design-system-twig-components), [operations: services](../02-architecture/operations.md#services), [configuration](../02-architecture/operations.md#configuration), [CI pipeline](../02-architecture/testing-strategy.md#ci-pipeline), [CSP](../02-architecture/security.md#browser-hardening-and-csp), [logging without leaking](../02-architecture/security.md#logging-without-leaking), [app server tuning](../02-architecture/performance-and-scale.md#app-server-tuning).
5. **Previous phase:** none. Read [CLAUDE.md](../../CLAUDE.md) and the [plan README](README.md).

## Outcome
Omar clones the repo, runs `make up`, and opens https://localhost. He sees the empty pinround shell in the prototype's colours, in light and dark mode. `make test` runs all gates. A test proves that a query without the tenant context returns no rows. A pull request with a cross-module import fails CI.

## Scope
- Symfony 8.1 app on PHP 8.4+, Docker Compose stack, Makefile.
- Module skeletons for all modules in [modules.md](../02-architecture/modules.md#module-catalogue) with Deptrac.
- Quality gates in CI: PHPUnit, PHPStan max, Deptrac, PHP-CS-Fixer, Rector dry-run, `composer audit`, doc Index lint, plain-copy lint.
- `TenantContext`, Doctrine SQL filter, PostgreSQL RLS set per transaction with `set_config('app.org_id', …, true)`, the four database roles, the `SECURITY DEFINER` resolver functions (the only RLS exceptions), the migration catalog test.
- Messenger with the outbox transport and Valkey transports, tenant context per message; Scheduler skeleton; a minimal append-only audit store (`aud_event`).
- Design tokens, fonts, base layout, design-system Twig components, a dev-only component gallery.
- Health endpoints, OpenTelemetry baseline, JSON logs with redaction, security headers.
- `docs/operations/config.md`.

## Out of scope
- Users, login, organizations (P01). Any business table except a test-only table for tenancy tests and the minimal `aud_event` table (P00.T7).
- The Activity log UI, audit filters and export (P11).
- Production deploy pipeline and backups (P12), image signing beyond a CI build (P12).

## Requirements covered
NFR-SEC-03, NFR-SEC-07, NFR-SEC-09, NFR-SEC-10, NFR-SEC-11, NFR-SCAL-02, NFR-SCAL-03, NFR-OPS-01, NFR-OPS-02, NFR-OPS-04, NFR-OPS-05, NFR-MNT-01, NFR-MNT-02, NFR-MNT-03, NFR-MNT-04, NFR-MNT-05, NFR-A11Y-01 (tokens and focus styles), C-06, C-10. Foundation for FR-ORG-01 (tenant scoping).

## Design notes
- **Folders:** `src/<Module>/{Api,Domain,Application,Infrastructure,UI}` for SharedKernel, Identity, Organization, Workflow, Ticketing, Conversation, Review, Media, Notification, Insight, Assist, Integration, Audit, AgentApi, Web. Namespaces `App\<Module>\…`.
- **Compose services:** `app` (FrankenPHP worker mode, built-in Mercure), `worker` (`messenger:consume`), `scheduler`, `postgres` (18, extensions `pg_trgm`, `citext`, `pgcrypto`; `pgvector` optional), `valkey-state` (`maxmemory-policy noeviction`: sessions, locks, rate limits, queues), `valkey-cache` (`allkeys-lru`: cache pools), `minio` (private bucket), `mailpit`. See [ADR-0010](../03-adr/0010-cache-sessions-locks.md) for why there are two Valkey instances.
- **Database roles** (names from [ADR-0005](../03-adr/0005-multi-tenancy.md) and [data-model.md](../02-architecture/data-model.md)): `pinround_app` (no `BYPASSRLS`; web, API and workers), `pinround_migrator` (owns the schema and runs migrations; **no** `BYPASSRLS`), `pinround_retention` (the **only** `BYPASSRLS` role; used only by the retention purge job in P11), `pinround_readonly` (reports and support, `SELECT` under RLS). No role is shared between services.
- **Tenancy flow:** firewall → `TenantContext` resolves the org → Doctrine middleware starts each transaction with `SELECT set_config('app.org_id', $1, true)` (a bound parameter; `true` = local to the transaction) → RLS policy `USING (organization_id = NULLIF(current_setting('app.org_id', true), '')::uuid)` with the same `WITH CHECK` → the Doctrine SQL filter adds the same condition. `NULLIF` matters: on a pooled connection that ran an earlier transaction, `current_setting(..., true)` returns `''` (not NULL), and `''::uuid` would raise an error. No org set means zero rows (fails closed). Plain `SET app.org_id …` without `LOCAL` is banned.
- **Lookups before the org is known** (personal access token by hash, org picker after login, invite token, webhook `connectionId`, Mercure and laptop-package resolution) never use `BYPASSRLS`. They call `SECURITY DEFINER` resolver functions that return ids only (for example `pinround_resolve_token(hash) → (user_id, organization_id)`). These functions are the only RLS exceptions; the list lives in [data-model.md](../02-architecture/data-model.md) and each has its own test.
- **Worker mode:** no static state; stateful services implement `ResetInterface` ([ADR-0008](../03-adr/0008-frankenphp-runtime.md)).
- **Composer scripts:** `test`, `test:unit`, `test:integration`, `phpstan`, `deptrac`, `cs`, `cs:fix`, `rector`, `audit`, `lint:docs`, `lint:copy`, `qa` (all).
- **Security:** CSP with nonces and no inline scripts, HSTS, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, frame-ancestors none.
- **Performance:** OPcache with preloading, FrankenPHP worker count from env, AssetMapper with long cache headers.

## Tasks

### P00.T1 — Symfony skeleton and Docker stack 🏁
**Why:** everybody needs the same running stack on day one.
**Steps:**
1. Create the Symfony 8.1 skeleton (webapp pack minus anything unused), PHP ≥ 8.4 in `composer.json`, `declare(strict_types=1)` everywhere.
2. Write `compose.yaml` with the services from the design notes; health checks for each; non-root users; named volumes.
3. FrankenPHP image: `Dockerfile` with multi-stage build (dev and prod targets), worker mode through the Symfony Runtime (verify the runtime package name at implementation), OPcache settings.
4. `Makefile` targets: `up`, `down`, `sh`, `test`, `qa`, `migrate`, `seed` (empty for now), `logs`.
5. `.env` with safe defaults and no secrets; `.env.local` ignored.
6. MinIO: create a private bucket `pinround` at start (init container or script), no public policy.
**Files:** `compose.yaml`, `Dockerfile`, `Makefile`, `.env`, `config/packages/*`, `public/`, `docker/`.
**Acceptance criteria:**
- [x] `make up` on a clean clone starts all services; `docker compose ps` shows them healthy.
- [x] https://localhost answers with the Symfony app over HTTP/2 (local TLS from Caddy).
- [x] Worker mode is on (verified by a request counter in a dev-only debug header that grows across requests).
- [x] Mail sent from the app appears in Mailpit.
- [x] The MinIO bucket refuses anonymous reads.
**Tests:** a smoke functional test `GET /` returns 200; a shell test in CI that runs `docker compose config`.
**Prototype check:** none yet.
**Size:** M

### P00.T2 — Modules, Deptrac and CI gates 🏁
**Why:** module boundaries rot if nothing enforces them from the first commit ([ADR-0002](../03-adr/0002-modular-monolith.md)).
**Steps:**
1. Create the folder skeleton for every module with a `README.md` (one paragraph: purpose, owns, Api) copied from [modules.md](../02-architecture/modules.md#module-catalogue).
2. `deptrac.yaml`: one layer per module part; rules from [Deptrac rules](../02-architecture/modules.md#deptrac-rules). Other modules may use only `<Module>\Api`. Domain may use only SharedKernel.
3. PHPStan at max level with strict rules and the Symfony and Doctrine extensions; baseline empty.
4. PHP-CS-Fixer (Symfony + risky rules), Rector (dry-run in CI), `composer audit`.
5. PHPUnit config with suites `unit`, `integration`, `functional`; Zenstruck Foundry installed.
6. GitHub Actions workflow with the stages from [CI pipeline](../02-architecture/testing-strategy.md#ci-pipeline): static, unit, integration (PostgreSQL 18 + Valkey services), build. E2E stage stub (filled in P00.T9).
**Files:** `src/*/`, `deptrac.yaml`, `phpstan.dist.neon`, `.php-cs-fixer.dist.php`, `rector.php`, `phpunit.dist.xml`, `.github/workflows/ci.yml`, `composer.json` scripts.
**Acceptance criteria:**
- [ ] `composer qa` runs every gate locally and passes.
- [ ] A test branch that imports `App\Ticketing\Domain\Ticket` from `App\Review\Application` fails `composer deptrac` (keep this as a documented fixture test, not a real import).
- [ ] CI runs on push and pull request and blocks merge when a stage fails.
- [ ] Total CI time is under 15 minutes.
**Tests:** CI itself; a unit test that the Deptrac config lists every module folder.
**Prototype check:** none.
**Size:** M

### P00.T3 — Tenancy part 1: TenantContext, roles and RLS policy 🏁
**Why:** one org must never see another org's data, even if a query forgets a filter ([ADR-0005](../03-adr/0005-multi-tenancy.md), NFR-SEC-03).
**Steps:**
1. `SharedKernel`: `OrganizationId` (UUIDv7, [ADR-0006](../03-adr/0006-identifiers.md)), `TenantContext` (set, get, require, clear; implements `ResetInterface` for worker mode).
2. Doctrine DBAL middleware: at the start of every transaction run `SELECT set_config('app.org_id', :id, true)` (bound parameter) when a tenant is set. Wrap every web request in a transaction.
3. Doctrine SQL filter `organization_id = :org` for every entity that implements `TenantOwned`.
4. The four DB roles from the design notes; migrations run as `pinround_migrator`; the app connects as `pinround_app`; `pinround_retention` exists but no service is configured with it yet.
5. A migration helper `TenantTable::enableRls('table')` that adds `ENABLE` + `FORCE ROW LEVEL SECURITY` and the `tenant_isolation` policy with `NULLIF(current_setting('app.org_id', true), '')::uuid` in `USING` and `WITH CHECK` ([row-level security](../02-architecture/data-model.md#row-level-security)).
6. Test-only table `zz_tenant_probe` (created only in the test database) to prove the setup.
**Files:** `src/SharedKernel/…`, `src/SharedKernel/Infrastructure/Doctrine/TenantMiddleware.php`, `config/packages/doctrine.yaml`, `migrations/`, `tests/Integration/Tenancy/`.
**Acceptance criteria:**
- [ ] With org A set, a query returns only org A rows; with no org set, it returns zero rows and no error.
- [ ] On one pooled connection, a transaction without a tenant that follows a transaction with a tenant returns zero rows and no `invalid input syntax for type uuid` error (the `NULLIF` case).
- [ ] With the Doctrine filter turned off, RLS still blocks org B rows.
- [ ] Inserting a row with another org's id fails (`WITH CHECK`).
- [ ] `pinround_migrator` and `pinround_app` both lack `BYPASSRLS` (catalog query test).
**Tests:** integration suite `tests/Integration/Tenancy/*` (isolation, fail-closed, pooled-connection case, roles).
**Security notes:** never log the org id together with ticket content; `pinround_retention` credentials are never in the web or worker env.
**Performance notes:** `set_config` is one round trip per transaction and works with PgBouncer transaction mode ([connection pooling](../02-architecture/performance-and-scale.md#connection-pooling)).
**Prototype check:** none (backend task).
**Size:** M

### P00.T4 — Tenancy part 2: catalog test, resolver functions and reset safety 🏁
**Why:** the tenancy rules must be checked by machines, and the few lookups that run before the org is known must not open a hole ([ADR-0005](../03-adr/0005-multi-tenancy.md), [ADR-0008](../03-adr/0008-frankenphp-runtime.md)).
**Steps:**
1. **Migration catalog test:** queries `pg_class`, `pg_policies` and `pg_inherits` and fails if any table with an `organization_id` column (including every partition) lacks RLS + FORCE + the policy, or if any role other than `pinround_retention` has `BYPASSRLS`.
2. **Resolver functions:** create the `SECURITY DEFINER` functions for lookups before tenant context (token by hash, user's org list, invite by token hash, webhook connection by id, Mercure topic owner, laptop package owner) with `SET search_path` fixed, returning ids only, `EXECUTE` granted to `pinround_app` only. Stub bodies are fine where the table comes later; each later phase fills its function.
3. **Static check:** a PHPStan rule or CI grep that bans `SET app.org_id` and `SET LOCAL app.org_id` string building; only `set_config(..., true)` through the middleware is allowed.
4. Messenger `doctrine_transaction` middleware on every bus, and a middleware that restores `TenantContext` from the message stamp before the handler and clears it after (the stamp itself is added in P00.T7).
5. Worker reset: after a request or message ends (including after an exception), `TenantContext` is cleared and a closed `EntityManager` is reset (`ManagerRegistry::resetManager()`), so the next request starts clean.
**Files:** `tests/Integration/Tenancy/CatalogTest.php`, `migrations/*_resolver_functions.php`, `phpstan-rules/` or `bin/check-set-local`, `src/SharedKernel/Infrastructure/Messenger/TenantMiddleware.php`.
**Acceptance criteria:**
- [ ] The catalog test fails when a new tenant table or partition forgets RLS (proved with a temporary table in the test).
- [ ] Each resolver function returns ids only and nothing for an unknown input; calling it as `pinround_readonly` is denied.
- [ ] After a request ends, the next request on the same worker has no tenant (worker reset test).
- [ ] After a handler throws and closes the EntityManager, the next message is handled normally (EntityManager reset test).
**Tests:** integration tests above; a fixture test for the static check.
**Prototype check:** none (backend task).
**Size:** M

### P00.T5 — Design tokens and base layout 🏁
**Why:** the product must look like the prototype from the first screen ([C-13](../01-requirements/constraints.md#c-13-the-prototype-is-the-ux-contract)).
**Steps:**
1. Copy every CSS custom property from the prototype (light and dark) into `assets/styles/tokens.css` with the same names (`--primary`, `--surface`, `--s-low`, `--waiting-container` …). Take values from [Design tokens](../04-prototype/prototype-guide.md#design-tokens).
2. Theme switch: `system` (media query), `light`, `dark` via `data-theme` on `<html>`. Explicit `body` background.
3. Self-host Atkinson Hyperlegible Next and Mono (WOFF2) through AssetMapper; `font-display: swap`.
4. `src/Web/UI/templates/base.html.twig`: header (logo "pinround", nav placeholder, "Report a problem" button placeholder, bell placeholder, avatar placeholder), one `<main>`, toast region (`role="status"`, `aria-live="polite"`), dialog root.
5. Focus styles (`:focus-visible` 2 px outline), reduced motion, 360 px layout without horizontal scroll.
6. CSP nonce helper for any `<script>` tag; no inline handlers.
**Files:** `assets/styles/*.css`, `assets/fonts/`, `importmap.php`, `src/Web/UI/templates/base.html.twig`.
**Acceptance criteria:**
- [ ] Token names and values match the prototype in light and dark mode (diff check against the guide).
- [ ] The page passes an axe check with zero serious issues.
- [ ] At 360 px width there is no horizontal scroll.
- [ ] No request goes to a third-party host (fonts are local).
**Tests:** Playwright screenshot of the shell in light and dark; axe check.
**Prototype check:** Main board header and colours ([Global layout](../04-prototype/prototype-guide.md#global-layout)): logo, header height, primary colour, surface colours.
**Size:** M

### P00.T6 — Design-system Twig components 🏁
**Why:** every later screen is built from the same small parts, named like the prototype classes.
**Steps:**
1. Create Twig components from [the component table](../02-architecture/frontend.md#design-system-twig-components): `Button` (filled, tonal, outline, text; md/sm), `Chip` (`aria-pressed`), `SegmentedControl`, `Tag`, `AiChip`, `StatusPill` (five group colours), `Avatar`, `AvatarStack` (max 4 + "+N"), `Card`, `EmptyState`, `Toast`, `Dialog` (native `<dialog>`), `Switch` (`role="switch"`).
2. Stimulus controllers `dialog` and `menu` (Escape, click outside, focus return).
3. A dev-only route `/_gallery` showing every component in every state, light and dark.
**Files:** `src/Web/UI/Component/*.php`, `templates/components/*.html.twig`, `assets/controllers/{dialog,menu}_controller.js`.
**Acceptance criteria:**
- [ ] Each component renders as in the prototype ([Component classes](../04-prototype/prototype-guide.md#component-classes)).
- [ ] `AvatarStack` with 7 people shows 4 avatars and "+3".
- [ ] `StatusPill` for each of the five groups uses the fixed group colours ([five group colours](../00-product/ux-principles.md#the-five-group-colors)).
- [ ] Keyboard: dialogs trap focus and give it back; menus close on Escape.
- [ ] `/_gallery` returns 404 in prod.
**Tests:** component unit tests (render with props → expected HTML attributes); Playwright for dialog and menu keyboard behaviour.
**Prototype check:** buttons, chips, tags, pills, avatars in the Main board header and Home.
**Size:** M

### P00.T7 — Messenger, outbox, scheduler and minimal audit store 🏁
**Why:** side effects (notifications, audit, Mercure) must never be lost or run twice ([ADR-0009](../03-adr/0009-async-messaging-and-outbox.md)); later 🏁 tasks (P08, P09) already write audit events ([ADR-0026](../03-adr/0026-audit-log.md)).
**Steps:**
1. Buses: `command.bus` (sync, with `doctrine_transaction` middleware), `event.bus`.
2. Outbox transport: Doctrine transport table in PostgreSQL, written in the same transaction as the change; a relay consumer moves messages to Valkey transports `async_media`, `async_ai`, `async_notify`, `async_integration` (configured now, used later).
3. Retry strategy per transport, a `failed` transport, idempotent handler helper (processed-message table keyed by message id).
4. Envelope stamp carrying `organization_id` and actor id, added on dispatch; the P00.T4 middleware restores `TenantContext` from it, so each message runs in its own tenant.
5. Symfony Scheduler service with an empty schedule; `scheduler` container runs it.
6. **Minimal audit store:** `aud_event` (id, organization_id, at, actor_kind, actor_id, action, target_type, target_id, data jsonb without content) as a table partitioned by month with primary key `(id, at)`; RLS on the parent and every partition; grants **on the parent only** (`INSERT`, `SELECT` for `pinround_app`), all privileges revoked on the partitions; `Audit\Api\AuditLog::record(...)`. P11 adds the UI, filters, export and retention.
7. **Worker state-leak test:** run two messages for two orgs in one worker loop and assert no tenant or user state leaks.
**Files:** `config/packages/messenger.yaml`, `src/SharedKernel/Infrastructure/Messenger/*`, `src/Audit/**`, `tests/Integration/Messaging/*`, `tests/Integration/Audit/*`.
**Acceptance criteria:**
- [ ] An event recorded inside a transaction that rolls back is never delivered.
- [ ] A handler that fails 3 times lands in `failed` with the error, without ticket content in the log.
- [ ] Delivering the same message twice runs the handler's effect once.
- [ ] The state-leak test passes.
- [ ] `UPDATE` and `DELETE` on `aud_event` fail for `pinround_app`; a direct `SELECT` on a partition fails (grants on the parent only).
**Tests:** integration tests above.
**Prototype check:** none (backend task).
**Size:** M

### P00.T8 — Health, observability and security headers
**Why:** we must see problems without leaking data ([ADR-0029](../03-adr/0029-observability.md), NFR-SEC-09).
**Steps:**
1. `/health/live` (process up) and `/health/ready` (DB, both Valkeys, object storage reachable); no auth, no details beyond ok/fail.
2. Monolog JSON format with `request_id`, `trace_id`, `organization_id`, `user_id`; a **redaction processor** that drops keys like `body`, `description`, `prompt`, `text`, `token`, `password`, `authorization`.
3. **Log-leak marker test:** a test sends a request containing a unique marker string in a body field and asserts the marker never appears in the logs.
4. OpenTelemetry baseline: HTTP server spans, Doctrine spans, Messenger spans; exporter configurable by env (verify package names at implementation).
5. Security headers listener: CSP with nonce, HSTS (prod), `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `Permissions-Policy` (camera, microphone, geolocation off), `frame-ancestors 'none'`.
**Files:** `src/Web/UI/Controller/HealthController.php`, `config/packages/monolog.yaml`, `src/SharedKernel/Infrastructure/Logging/RedactionProcessor.php`, `src/Web/Infrastructure/SecurityHeadersSubscriber.php`.
**Acceptance criteria:**
- [ ] `/health/ready` returns 503 when PostgreSQL is stopped.
- [ ] The log-leak marker test passes.
- [ ] Response headers match the CSP example in [security.md](../02-architecture/security.md#browser-hardening-and-csp).
- [ ] A trace shows one span per DB query for `GET /`.
**Tests:** functional tests for health and headers; the marker test.
**Prototype check:** none (backend task).
**Size:** M

### P00.T9 — Doc lints, plain-copy lint, e2e skeleton and config docs
**Why:** the docs are part of the product; Claude Code relies on the Index rule (NFR-MNT-05, NFR-USE-04).
**Steps:**
1. `bin/lint-docs`: fails when a Markdown file has more than 100 lines and no `## Index` in its first 40 lines, or when a relative link or anchor is broken. Run in CI stage 1.
2. `bin/lint-copy`: scans Twig templates and translation files for words from "Words we do not use" in the [glossary](../00-product/glossary.md#words-we-do-not-use) and for status jargon; prints file and line.
3. Playwright project under `tests/e2e/` with one journey: open `/`, see the shell. Add the CI e2e stage with the Docker stack and axe.
4. Write `docs/operations/config.md`: every env var, default, which service reads it, whether it is secret. Give it an Index when it passes 100 lines.
5. Seed command stub `app:demo:seed` (filled in P01).
**Files:** `bin/lint-docs`, `bin/lint-copy`, `tests/e2e/`, `.github/workflows/ci.yml`, `docs/operations/config.md`.
**Acceptance criteria:**
- [ ] Removing the Index from a long doc makes CI fail.
- [ ] Adding "workflow status" or another banned word to a template makes `lint:copy` fail.
- [ ] The e2e stage runs in CI and uploads a trace on failure.
- [ ] `docs/operations/config.md` lists every variable in `.env`.
**Tests:** the lints have their own small fixture tests.
**Prototype check:** none (tooling task).
**Size:** S

## Definition of done
- [ ] All tasks P00.T1–P00.T9 ticked.
- [ ] `make up` + `make test` work on a clean clone in under 10 minutes.
- [ ] CI green on `main` with all gates: PHPUnit, PHPStan max, Deptrac, CS, Rector dry-run, `composer audit`, doc lint, copy lint, e2e.
- [ ] Tenant isolation suite, catalog test (tables and partitions), resolver-function tests, worker state-leak and EntityManager reset tests, and the log-leak marker test exist and pass.
- [ ] `aud_event` exists with parent-only grants; no role except `pinround_retention` has `BYPASSRLS`.
- [ ] The shell matches the prototype tokens in light and dark mode.
- [ ] `docs/operations/config.md` written; any doc you changed has an up-to-date Index.

## Hand-off notes
- Later phases that look something up before the org is known (P01 org picker and invites, P04 Mercure, P08 laptop packages, P09 tokens, P10 webhooks) must fill their resolver function from P00.T4, never use `BYPASSRLS`.
- `aud_event` is the real audit table; P11 adds UI, export and retention on top of it.
- 2026-11-06 (P00.T1): ADR-0008 says `max_requests` 500, but FrankenPHP 1.13.5 has no such directive. Workers restart through the Symfony Runtime's `FRANKENPHP_LOOP_MAX=500`.
- 2026-11-06 (P00.T5): the prod image must run `asset-map:compile`. Caddy already sends `Cache-Control: public, max-age=31536000, immutable` for `/assets/*`.
- 2026-11-06 (P00.T8): turn the Caddy admin API off in prod and point the image `HEALTHCHECK` at the app health route.
- 2026-11-06 (P00.T3): the first migration installs trusted extensions, so `pinround_migrator` needs `CREATE` on the database (or a superuser bootstrap creates them). Test that migration as that role. Its `down()` is irreversible on purpose.
- 2026-11-06 (P00.T7): `src/SharedKernel/Infrastructure/Scheduler/DefaultSchedule.php` is an empty placeholder so the scheduler service starts. Modules add jobs with `#[AsCronTask]` or `#[AsPeriodicTask]`.
- 2026-11-06 (P00.T9, P12): the prod env must provide `APP_SECRET` as a plain environment variable (not a Symfony secret; the entrypoint guard reads only the environment), `DATABASE_URL`, `MAILER_DSN`, `MESSENGER_TRANSPORT_DSN`, `DEFAULT_URI`, `REDIS_CACHE_DSN` and the Mercure keys (prod refuses `change-me` keys). `config.md` and P12 `compose.prod.yaml` must list them.
- 2026-11-06 (P00.T1): MinIO and `mc` are built from pinned source ([ADR-0031](../03-adr/0031-minio-built-from-pinned-source.md)) because upstream stopped publishing images. `pgvector` is not in the stock postgres image and was skipped.
- 2026-11-06 (P00.T1): the disk on a dev machine can fill up. The MinIO source build needs about 2-3 GB.
- (add dated notes here: shortcuts taken, differences from the plan, follow-ups for P01)
