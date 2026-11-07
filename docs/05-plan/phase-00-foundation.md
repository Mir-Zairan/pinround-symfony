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
- **Composer scripts:** `test`, `test:unit`, `test:integration`, `test:functional`, `phpstan`, `deptrac`, `cs`, `cs:fix`, `rector`, `lint`, `qa` (all). There is no `audit` script: `composer audit` is a built-in command and `qa` calls `@composer audit`. `lint:docs` and `lint:copy` come in P00.T9.
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
- [x] `composer qa` runs every gate locally and passes.
- [x] A test branch that imports `App\Ticketing\Domain\Ticket` from `App\Review\Application` fails `composer deptrac` (keep this as a documented fixture test, not a real import).
- [ ] CI runs on push and pull request and blocks merge when a stage fails.
- [x] Total CI time is under 15 minutes.
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
- [x] With org A set, a query returns only org A rows; with no org set, it returns zero rows and no error.
- [x] On one pooled connection, a transaction without a tenant that follows a transaction with a tenant returns zero rows and no `invalid input syntax for type uuid` error (the `NULLIF` case).
- [x] With the Doctrine filter turned off, RLS still blocks org B rows.
- [x] Inserting a row with another org's id fails (`WITH CHECK`).
- [x] `pinround_migrator` and `pinround_app` both lack `BYPASSRLS` (catalog query test).
**Tests:** integration suite `tests/Integration/Tenancy/*` (isolation, fail-closed, pooled-connection case, roles).
**Security notes:** never log the org id together with ticket content; `pinround_retention` credentials are never in the web or worker env.
**Performance notes:** `set_config` is one round trip per transaction and works with PgBouncer transaction mode ([connection pooling](../02-architecture/performance-and-scale.md#connection-pooling)).
**Prototype check:** none (backend task).
**Size:** M

### P00.T4 — Tenancy part 2: catalog test, resolver functions and reset safety 🏁
**Why:** the tenancy rules must be checked by machines, and the few lookups that run before the org is known must not open a hole ([ADR-0005](../03-adr/0005-multi-tenancy.md), [ADR-0008](../03-adr/0008-frankenphp-runtime.md)).
**Steps:**
1. **Migration catalog test:** queries `pg_class`, `pg_policies` and `pg_inherits` and fails if any table with an `organization_id` column (including every partition) lacks RLS + FORCE + the policy, or if any role other than `pinround_retention` has `BYPASSRLS`.
2. **Resolver functions:** create the `SECURITY DEFINER` functions for lookups before tenant context (`resolve_api_token`, `resolve_user_orgs`, `resolve_invitation`, `resolve_sso_provider`, `resolve_jira_connection`, `resolve_laptop_package`) with `SET search_path` fixed, returning ids only, owned by `pinround_resolver`, `EXECUTE` granted to `pinround_app` only. Stub bodies are fine where the table comes later; each later phase fills its function.
3. **Static check:** a PHPStan rule or CI grep that bans `SET app.org_id` and `SET LOCAL app.org_id` string building; only `set_config(..., true)` through the middleware is allowed.
4. Messenger `doctrine_transaction` middleware on every bus. A stamped message runs under its organization (`TenantStampMiddleware`). A message for another organization than the one already set is refused with `MessageForAnotherTenantRefused`, also for async and delayed dispatch. Inside an open transaction with no tenant it passes through without a tenant and RLS returns no rows. Details: [data-model.md](../02-architecture/data-model.md#row-level-security).
5. Worker reset: after a request or message ends (including after an exception), `TenantContext` is cleared and a closed `EntityManager` is reset (`ManagerRegistry::resetManager()`), so the next request starts clean.
**Files:** `tests/Integration/Tenancy/CatalogTest.php`, `src/SharedKernel/Infrastructure/Messenger/{TenantStamp,TenantStampMiddleware,RejectMessageForAnotherTenantMiddleware}.php`, `src/SharedKernel/Application/MessageForAnotherTenantRefused.php`, `src/SharedKernel/Infrastructure/Doctrine/Migration/ResolverFunctions.php`, `migrations/Version20261106140000.php`, `bin/check-tenant-setting`.
**Acceptance criteria:**
- [x] The catalog test fails when a new tenant table or partition forgets RLS (proved with throwaway tables and partitions built in a rolled-back transaction; PostgreSQL temporary tables are not scanned).
- [x] Each resolver function returns ids only and nothing for an unknown input; calling it as `pinround_readonly` is denied.
- [x] After a request ends, the next request on the same worker has no tenant (worker reset test).
- [x] After a handler throws and closes the EntityManager, the next message is handled normally (EntityManager reset test).
- [x] The static check fails on a fixture that sets `app.org_id` outside the tenant middleware (`tests/Integration/Architecture/TenantSettingCheckTest.php`).
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
**Files:** `assets/styles/*.css`, `assets/fonts/`, `importmap.php`, `src/Web/UI/templates/base.html.twig`, `src/Web/Infrastructure/Security/CspNonce.php`.
**Acceptance criteria:**
- [x] Token names and values match the prototype in light and dark mode (diff check against the guide).
- [x] The page passes an axe check with zero serious issues.
- [x] At 360 px width there is no horizontal scroll.
- [x] No request goes to a third-party host (fonts are local).
**Tests:** Playwright screenshot of the shell in light and dark; axe check.
**Prototype check:** Main board header and colours ([Global layout](../04-prototype/prototype-guide.md#global-layout)): logo, header height, primary colour, surface colours.
**Size:** M

### P00.T6 — Design-system Twig components 🏁
**Why:** every later screen is built from the same small parts, named like the prototype classes.
**Steps:**
1. Create Twig components from [the component table](../02-architecture/frontend.md#design-system-twig-components): `Button` (filled, tonal, outline, text; md/sm), `Chip` (`aria-pressed`), `SegmentedControl`, `Tag`, `AiChip`, `StatusPill` (five group colours), `Avatar`, `AvatarStack` (max 4 + "+N"), `Card`, `EmptyState`, `Toast`, `Dialog` (native `<dialog>`), `Switch` (`role="switch"`).
2. Stimulus controllers `dialog` and `menu` (Escape, click outside, focus return).
3. A dev-only route `/_gallery` showing every component in every state, light and dark.
**Files:** `src/Web/UI/Component/*.php`, `src/Web/UI/templates/components/*.html.twig`, `assets/controllers/{dialog,menu}_controller.js`, `assets/styles/components.css`, `assets/styles/gallery.css`, `config/routes/component_gallery.yaml`, `tests/e2e/components.spec.ts`.
**Acceptance criteria:**
- [x] Each component renders as in the prototype ([Component classes](../04-prototype/prototype-guide.md#component-classes)). Sizes and radii are measured in `tests/e2e/gallery.spec.ts`; light and dark baselines are `tests/e2e/screenshots/gallery-*-linux.png`.
- [x] `AvatarStack` with 7 people shows 4 avatars and "+3" (`tests/Integration/Web/AvatarCardEmptyStateToastComponentsTest.php`).
- [x] `StatusPill` for each of the five groups uses the fixed group colours ([five group colours](../00-product/ux-principles.md#the-five-group-colors)) (`tests/Integration/Web/DesignComponentsTest.php`).
- [x] Keyboard: dialogs trap focus and give it back; menus close on Escape (`tests/e2e/components.spec.ts`).
- [x] `/_gallery` returns 404 in prod (`ComponentGalleryRouteTest`).
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
**Files:** `config/packages/messenger.yaml`, `src/SharedKernel/Infrastructure/Messenger/*`, `src/Audit/**`, `migrations/Version20261107150000.php`, `migrations/Version20261107160000.php`, `migrations/Version20261107170000.php`, `tests/Integration/Messaging/*`, `tests/Integration/Audit/*`, `docs/03-adr/0033-*.md`.
**Acceptance criteria:**
- [x] An event recorded inside a transaction that rolls back is never delivered.
- [x] A handler that fails 3 times lands in `failed` with the error, without ticket content in the log.
- [x] Delivering the same message twice runs the handler's effect once.
- [x] The state-leak test passes.
- [x] `UPDATE` and `DELETE` on `aud_event` fail for `pinround_app`; a direct `SELECT` on a partition fails (grants on the parent only).
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
**Files:** `src/Web/UI/Controller/HealthController.php`, `config/packages/monolog.yaml`, `src/SharedKernel/Infrastructure/Logging/RedactionProcessor.php`, `src/Web/Infrastructure/Security/SecurityHeadersSubscriber.php`.
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
- 2026-11-07 (P00.T5): `--on-error` (light #ffffff, dark #690005) and the bell badge (12px text, 20px box with radius 10 instead of 18px) differ from the prototype, for contrast on the dark `--error` and for minimum text size. `DesignTokensTest` lists `--on-error` as a named extra beyond the guide's table.
- 2026-11-07 (P00.T5): `base.html.twig` links `tokens.css`, `fonts.css`, `base.css` and `shell.css` directly with `asset()` tags; `app.js` does not import CSS. A CSS import in JS becomes a `data:` URL in the importmap, so the CSP needs no `data:` or `'strict-dynamic'` for styles. `importmap_polyfill` is off so no CDN script loads; `HomePageShellTest` fails if a remote script or link appears.
- 2026-11-07 (P00.T5): the header button reads "Report a problem" (FR text, functional-requirements.md) where the prototype says "New report". The sticky header can wrap to two rows (about 105px), so `base.css` defines `--header-height-max` (116px) for `scroll-padding-top`.
- 2026-11-07 (P00.T8): `SecurityHeadersSubscriber` (in `src/Web/Infrastructure/Security/`) sends `Referrer-Policy: strict-origin-when-cross-origin` per [security.md](../02-architecture/security.md#browser-hardening-and-csp), not step 5's `same-origin`, and adds `Cross-Origin-Opener-Policy: same-origin`. Static files Caddy serves from disk get no security headers, because the user chose app-only headers.
- 2026-11-07 (P00.T8): done: the prod image turns the Caddy admin API off (`CADDY_ADMIN=off`, read by the Caddyfile), and the image `HEALTHCHECK` probes `/health/live` through Caddy over HTTPS instead of the admin `/metrics`.
- 2026-11-07 (P00.T8, P12): the health routes answer only loopback and private client IPs (`IpUtils::PRIVATE_RANGES`). Behind a reverse proxy or load balancer on a private network every client looks private, so P12 must configure trusted proxies (`framework.trusted_proxies` and headers, and Caddy's own `trusted_proxies` if it is behind one) or block `/health/*` at the edge. P12 must also set `OBJECT_STORAGE_ENDPOINT` (the storage's address on the app's network) in prod; `/health/ready` fails while it is empty.
- 2026-11-07 (P00.T8): `pinround_app` may read `doctrine_migration_versions` (`SELECT` only, `Version20261107180000`), because prod's app container reads the migrations table as itself for the `/health/ready` migrations check; the user chose this read-only grant. `roles.sql` still revokes every other right on it from `pinround_app` and all from `pinround_readonly`. Run `make db-roles migrate migrate-test` after pulling. CI has no MinIO (no upstream image, ADR-0031; building it costs about five minutes), so its integration job points `OBJECT_STORAGE_ENDPOINT` at a local TCP listener and runs a second Valkey for `TEST_STATE_VALKEY_DSN`.
- 2026-11-07 (P00.T8): prod logs `info` and above straight to stdout as JSON, with no `fingers_crossed` buffer and no console handler (which would print each worker record twice); warnings such as a failed readiness check now reach the log.
- 2026-11-06 (P00.T3): the first migration installs trusted extensions, so `pinround_migrator` needs `CREATE` on the database (or a superuser bootstrap creates them). Test that migration as that role. Its `down()` is irreversible on purpose.
- 2026-11-06 (P00.T7): `src/SharedKernel/Infrastructure/Scheduler/DefaultSchedule.php` is an empty placeholder so the scheduler service starts. Modules add jobs with `#[AsCronTask]` or `#[AsPeriodicTask]`.
- 2026-11-06 (P00.T9, P12): the prod env must provide `APP_SECRET` as a plain environment variable (not a Symfony secret; the entrypoint guard reads only the environment), `DATABASE_URL`, `MAILER_DSN`, `MESSENGER_TRANSPORT_DSN`, `DEFAULT_URI`, `REDIS_CACHE_DSN` and the Mercure keys (prod refuses `change-me` keys). `config.md` and P12 `compose.prod.yaml` must list them. `MESSENGER_TRANSPORT_DSN` points at the state Valkey and names no stream: no path after the host (`redis://valkey-state:6379`, not `.../messages` or `.../1`) and no `stream` query parameter, or the four `async_*` queues merge into one stream. The `dsn_without_stream` env processor makes `worker` and `worker-heavy` fail at start on such a DSN ([ADR-0033](../03-adr/0033-valkey-transports-workers-and-outbox-relay.md)).
- 2026-11-07 (P00.T7, P12): P12 `compose.prod.yaml` runs the three workers of [ADR-0033](../03-adr/0033-valkey-transports-workers-and-outbox-relay.md) as `compose.yaml` does: `worker-outbox` (`outbox`, `--sleep=0.5`), `worker` (`async_notify async_integration`) and `worker-heavy` (`async_media async_ai`). `tests/shell/compose-config-check.sh` checks `compose.yaml` only.
- 2026-11-07 (P00.T7, P11): `processed_message` grows with every message an async handler runs once ([ADR-0033](../03-adr/0033-valkey-transports-workers-and-outbox-relay.md#idempotency-key)). P11 adds a chunked purge job as `pinround_retention` that deletes rows by `processed_at` (indexed), older than the longest redelivery window (the outbox's 3600 s redeliver timeout plus the `failed` retention an operator may retry from). No role has `DELETE` on it yet: `roles.sql` and the migrations give `pinround_retention` no table grants today, so P11's migration grants it `SELECT, DELETE`.
- 2026-11-07 (P00.T7): `doctrine:migrations:diff` finds no changes. The `schema_filter` regex in `config/packages/doctrine.yaml` (default and migrator connections) hides the tables no entity maps: `processed_message`, `aud_event` and `aud_event_YYYYMM`. A new hand-written table that no entity maps (for example `tkt_ticket_event`) must be added to it, or `DatabaseRolesTest::doctrineMigrationsDiffFindsNoChanges` fails.
- 2026-11-07 (P00.T7): `aud_event` partitions exist to 2027-12; a monthly job (and ADR-0026's boot/CI check for next month's partition) must be added before then. The job runs as `pinround_migrator`, names partitions `aud_event_YYYYMM` with UTC bounds, runs `TenantTable::enableRls` on each and revokes all on it from `PUBLIC`, `pinround_app` and `pinround_readonly`, as `Version20261107170000` does. `DbalAuditLogTest` freezes its clock at 2027-03-15, so it does not depend on the current month.
- 2026-11-07 (P00.T7, P11): `pinround_readonly` has `SELECT` on `aud_event` (parent, under RLS) for support. `pinround_retention` has no right on it yet; P11's retention migration grants what the purge needs, on the parent only.
- 2026-11-07 (P00.T7): the actor stamp waits for P01 users; the tenant stamp is added on dispatch now.
- 2026-11-07 (P00.T7): "fails 3 times" is met as one try plus 3 retries (max_retries 3, waits 1 s, 10 s, 60 s per ADR-0009); FailedMessageTest asserts 4 runs.
- 2026-11-07 (P00.T7): after pulling, run `make up` so `worker-outbox` and `worker-heavy` start; without them outbox events and `async_media`/`async_ai` messages wait unread.
- 2026-11-07 (P00.T7, P00.T8): the DBAL debug SQL logger writes bound parameters (ticket text) to the dev and test logs. Since P00.T8, `RedactionProcessor` keeps only allow-listed keys, so the logger's `sql`, `params` and `types` are dropped from every record and `dbal.logging` stays on; `LogLeakMarkerTest` proves a bound value never reaches a written log line. `CapturedLogRecords` runs before the redaction, so `FailedMessageTest` still sees the raw records.
- 2026-11-07 (P00.T7): [ADR-0033](../03-adr/0033-valkey-transports-workers-and-outbox-relay.md) supersedes [ADR-0009](../03-adr/0009-async-messaging-and-outbox.md) in part: transports, workers, the relay and the idempotency key.
- 2026-11-06 (P00.T1): MinIO and `mc` are built from pinned source ([ADR-0031](../03-adr/0031-minio-built-from-pinned-source.md)) because upstream stopped publishing images. `pgvector` is not in the stock postgres image and was skipped.
- 2026-11-06 (P00.T1): the disk on a dev machine can fill up. The MinIO source build needs about 2-3 GB.
- 2026-11-06 (P00.T2): [ADR-0032](../03-adr/0032-module-call-edges-follow-modules-catalogue.md) replaces ADR-0002's allowed-dependency diagram. Module calls follow the "Depends on" lines in modules.md. `DeptracConfigTest` fails if `deptrac.yaml` and those lines differ, so change both together.
- 2026-11-06 (P00.T2): events live in `<Module>\Api\Event`, their own Deptrac layer. Any module's Application and Infrastructure may use them; UI may not.
- 2026-11-06 (P00.T2): Application, Domain, Api and Event layers may not use vendor code; only Infrastructure and UI may. Put framework glue in Infrastructure.
- 2026-11-06 (P00.T2): the CI integration job creates `pinround_test` but runs no migrations. P00.T3 must add a migrate step for the test database.
- 2026-11-06 (P00.T2): ADR-0028 describes one "unit+integration" job and a nightly e2e. The real workflow has separate `unit` and `integration` jobs, and `e2e` is a stub that runs on pull requests only until P00.T9.
- 2026-11-06 (P00.T2): PHPUnit 13 fails an empty suite. Keep it that way: the unit and integration suites have tests, so a suite that finds none (a renamed folder) turns CI red.
- 2026-11-06 (P00.T2): `symfony/property-info` is dev-only (it comes with Foundry), so `config/packages/property_info.yaml` sets it only for dev and test. Move it to `require` before prod code needs it.
- 2026-11-06 (P00.T2): PHP-CS-Fixer prints a "minimum PHP 8.4" warning on PHP 8.5 but runs correctly.
- 2026-11-06 (P00.T2): CI ran green on PR #1: push run 3m01s, pull request run 2m29s (slowest job `build`, about 2.5 min).
- 2026-11-06 (P00.T2): merge blocking is not on. GitHub refuses branch protection on this private personal repo ("Upgrade to GitHub Pro or make this repository public", HTTP 403). The "blocks merge" criterion stays open until the repo gets Pro or a team plan; then require `static`, `unit`, `integration` and `build` on `main`.
- 2026-11-06 (P00.T3): partition jobs must never grant on child partitions; each table migration grants on the parent only.
- 2026-11-06 (P00.T3): `doctrine:migrations:diff` needs the `OrmSchemaProvider` service (`config/services.yaml`). In the test env it also sees the test-only `zz_tenant_probe` table.
- 2026-11-06 (P00.T3): `OrganizationId` accepts UUIDv7 only (rejects nil and other versions).
- 2026-11-06 (P00.T3): `TenantTable::enableRls` rejects names that are not identifiers or are longer than 63 characters, and does not grant.
- 2026-11-06 (P00.T3): ADR-0005 line 103 ("raw SQL without app.org_id must fail") is met as "returns zero rows, no error" per the P00.T3 criterion. The loud failure comes from `TenantFilter` throwing `TenantNotSet`. ADR-0005 is not edited; a superseding ADR is needed if the database itself must throw.
- 2026-11-06 (P00.T3): ADR-0005 line 130: the Messenger middleware only fills `TenantContext` from the `TenantStamp`. `TenantMiddleware` runs `set_config` at BEGIN (`doctrine_transaction`).
- 2026-11-06 (P00.T3): the security firewall must be non-lazy (or whatever fills `TenantContext` must run between kernel.request priority 8 and 7), because `set_config` and the filter mark bind at BEGIN.
- 2026-11-06 (P00.T3): no ORM result cache on tenant data (`NullAdapter` on purpose; the cache key cannot tell organizations apart).
- 2026-11-06 (P00.T3): org switch rule: `TenantContext` refuses (`TenantSwitchRefused`) a switch to another org, or `clear()`, while a DB transaction is open or the EntityManager holds any entity. Loops over orgs must end the transaction, flush and clear first.
- 2026-11-06 (P00.T3): server-side prepares are off app-wide (PgBouncer-safe).
- 2026-11-06 (P00.T3): DBAL quirk: a failed nested savepoint leaves the nesting level one too high (not fixed; DBAL's own behaviour).
- 2026-11-06 (P00.T3): `pinround_retention` has no test connection. The BYPASSRLS path is stood in by disabling RLS through the migrator in tests.
- 2026-11-06 (P00.T3): CI has not yet run on GitHub for this branch (`psql` on the ubuntu-24.04 runner unverified).
- 2026-11-06 (P00.T3): step 3 says the filter adds `organization_id = :org`. As built, the filter reads the transaction's setting (`NULLIF(current_setting('app.org_id', true), '')::uuid`, the `TenantSetting` constant) instead of binding `:org`, so all organizations share one query-cache entry. Its only parameter is the `is_tenant_set` flag.
- 2026-11-07 (P00.T4): worker reset needed no new listener. Web requests rely on `Kernel::handle()` running `services_resetter`; Messenger workers rely on `ResetServicesListener`, so no `messenger:consume` may run with `--no-reset` (P12 `compose.prod.yaml` too). The one gap was a sync dispatch with no worker in between: `TenantStampMiddleware` now resets a closed EntityManager after a stamped message it ran. Inside a web request a closed EntityManager stays closed until the request ends.
- 2026-11-07 (P00.T4): the plan listed a Mercure topic-owner resolver. ADR-0005 and data-model.md list `resolve_sso_provider` and no Mercure resolver, so it is built per the ADR. If Mercure needs one, add it in its phase with a new ADR note.
- 2026-11-07 (P00.T4): a job that creates partitions must run `TenantTable::enableRls` on every child partition, or `CatalogTest` fails.
- 2026-11-07 (P00.T4): P00.T7 must add `command.bus` and `event.bus` to the hand-written middleware list in `config/packages/messenger.yaml` and to `tests/Integration/Messaging/MessengerBusMiddlewareTest.php`.
- 2026-11-07 (P00.T4): buses use `default_middleware: false`. If `framework.lock` is turned on, add `deduplicate_middleware` by hand, and re-check the list after a Symfony upgrade.
- 2026-11-07 (P00.T4): a later phase fills a resolver body with `CREATE OR REPLACE FUNCTION` in a migration, using `SET ROLE pinround_resolver` and a temporary `CREATE` grant on the schema (see `Version20261106140000`). Keep the `p_` parameter names and the ids-only return columns.
- 2026-11-07 (P00.T4): each tenant table a resolver reads opts in with `TenantTable::allowResolverRead(table, columns)`.
- 2026-11-07 (P00.T9): tests/e2e exists with the shell spec; T9 adds the CI stage, axe on more pages, and the trace upload.
- 2026-11-07 (P00.T6): Toast is markup only. The 3.6 s one-at-a-time timer comes with the first real toast (user decision).
- 2026-11-07 (P00.T6): `assets/vendor` is git-ignored and restored by the composer auto-script `importmap:install`. The Docker entrypoint now also runs it when `assets/vendor` is missing.
- 2026-11-07 (P00.T6): the gallery shows one theme at a time via `?theme=` because `data-theme` only works on `:root`.
- 2026-11-07 (P00.T6): the PHP class for Switch is `SwitchControl` (PHP reserves `switch`); it is registered as `<twig:Switch>`.
- 2026-11-07 (P00.T6): status pills follow the ticket-page and UX docs: 30 px high, pill radius, with a decorative dot in the `--group-*` colour (the prototype has no pill CSS class).
- 2026-11-07 (P00.T6): tags, AI chips and pills use 12 px text instead of the prototype's 11 px, because ux-principles.md says text is never below 12 px (the requirement wins).
- 2026-11-07 (P00.T6): the prototype's dialog CSS was not found, so the dialog is styled from `docs/04-prototype/screens/dialogs-and-popovers.md`.
- 2026-11-07 (P00.T6): Dialog has an extra X close button beyond the Cancel button the docs describe.
- 2026-11-07 (P00.T6): the e2e suite needs the stack running (`make e2e`).
- (add dated notes here: shortcuts taken, differences from the plan, follow-ups for P01)
