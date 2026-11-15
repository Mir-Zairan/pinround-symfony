# Testing strategy

What we test, with which tools, and what "done" means for any task.

## Index

- [Why tests are part of every task](#why-tests-are-part-of-every-task) — the scene.
- [Test pyramid](#test-pyramid) — layers and what each catches.
- [Tests per module](#tests-per-module) — the must-have tests for each module.
- [Tools](#tools) — PHPUnit, Foundry, Playwright, PHPStan, Deptrac, k6, axe and more.
- [Special suites](#special-suites) — tenant isolation, voters, query counts, prompts, MCP contracts, log leaks.
- [Fixtures and test data](#fixtures-and-test-data) — neutral data only.
- [CI pipeline](#ci-pipeline) — the five CI jobs, what each runs and its timeout.
- [Definition of done for any task](#definition-of-done-for-any-task) — the checklist.

## Why tests are part of every task

Omar changes the rule "nobody approves their own code review". He makes a small mistake: the rule now also blocks the lead from approving anyone's code. The voter test suite fails in 3 seconds and names the case "lead approves Omar's code: expected allow, got deny". Omar fixes it before lunch. Without that test, Lena's team would have been stuck for a day and lost trust in the tool.

With three people and many sessions with Claude Code, tests are how we keep each session from breaking the last one.

## Test pyramid

| Layer | Share | Speed | Catches |
|---|---|---|---|
| Unit (domain) | ~60% | ms | rules: transitions, turn, scoring, check reasons, policies |
| Integration (DB, voters, repositories, handlers) | ~25% | 10–100 ms | SQL, RLS, voters, events, read models |
| Functional (HTTP, Symfony test client, Live Components) | ~10% | 100 ms–1 s | routes, forms, CSRF, access control, Turbo responses |
| End-to-end (Playwright) | ~5% | seconds | key journeys in a real browser |
| Load (k6) | phase 12 + CI smoke | minutes | budgets |

## Tests per module

| Module | Must-have tests |
|---|---|
| Identity | login, throttling, MFA, invite single-use and expiry, token hashing and scopes, session rotation |
| Organization | org and team roles, role stacking (admin + manager + lead), triager setting, policies |
| Workflow | every transition and guard, policy locked/extend/team, rules (tester-only Done, no self-review, review before test), custom step "move on" |
| Ticketing | key counters under concurrency, visibility, intake check reasons, pending assignee, duplicate closing |
| Conversation | question pauses and answer resumes to previous step, replies one level, resolve |
| Review | rounds numbering, checklist from pins, verdicts, draft privacy, share on hand-off |
| Media | type/size/polyglot rejection, re-encode, signed URL expiry, dedup |
| Notification | ping rate limit, grouping, auto-clear, quiet hours, Mercure topic auth |
| Insight | turn rules (all cases), score formula, needs-you grouping, my-reports grouping (needs/stuck/moving/done), workload counts |
| Assist | policy matrix, prompt building with delimiters, schema validation, laptop package expiry, labeling, metering |
| Integration | Jira import idempotency, webhook auth and replay, status mapping, conflict rule |
| Audit | every admin action writes an event, retention purge, export |
| AgentApi | MCP tool contracts, scopes, revoked tokens |

## Tools

- **PHPUnit** (current major) for unit, integration and functional tests.
- **Zenstruck Foundry** for factories and stories (neutral fixtures).
- **Symfony test client** (`WebTestCase`) and the Live Component test helpers for UI behaviour.
- **Playwright** for e2e journeys (run against the Docker stack). Chosen over Panther for better tooling and traces. A local shell spec exists since P00.T5 (`make e2e`, which runs on the host and needs Node 22.12 or newer; project in `tests/e2e`, baselines in `tests/e2e/screenshots`, one per platform and recorded on Linux, so another OS records its own with `make e2e-update`; the same target refreshes them); CI runs it without the pixel compares (see the `e2e` job below), and more journeys arrive with later phases.
- **PHPStan** at max level with strict rules and the Symfony and Doctrine extensions.
- **Deptrac** for module boundaries ([ADR-0002](../03-adr/0002-modular-monolith.md)).
- **PHP-CS-Fixer** and **Rector** for style and safe upgrades.
- **composer audit** and **roave/security-advisories** for dependencies.
- **axe-core** (through Playwright) for accessibility checks on key pages (WCAG 2.2 AA).
- **k6** for load tests ([performance-and-scale.md](performance-and-scale.md#load-test-plan)).

See [ADR-0028](../03-adr/0028-testing-and-quality-gates.md).

## Special suites

- **Tenant isolation** (always on): two orgs with mirrored data; every list, search, read model, export and MCP tool must return only the caller's org. Plus `CatalogTest`, which reads the catalog and fails on a tenant table or partition without RLS, `FORCE` and the `tenant_isolation` policy (rules in [data-model.md](data-model.md#row-level-security)). See [security.md](security.md#tenant-isolation).
- **Voter suite:** a table-driven test per voter: (role set, ticket state, rule settings) → allow/deny + reason. New roles or rules must add rows.
- **Query count:** hot-path functional tests assert a maximum number of SQL queries per request (Home 8, list 5, ticket page 10).
- **Worker state leak:** two requests through one booted kernel with different orgs; the next request has no tenant and an open EntityManager (`tests/Functional/Web/WorkerResetTest`). For messages, `tests/Integration/Messaging/WorkerStateBetweenMessagesTest` runs one worker loop over a message of org A and then one of org B, and the B handler sees nothing of A.
- **Prompt golden tests:** offline runner for real models (not in CI); in CI, mocked provider responses test parsing, validation, retry and labeling. Includes a prompt-injection set. See [ai-architecture.md](ai-architecture.md#evaluation).
- **MCP contract tests:** for each tool, valid input → expected output shape; invalid input → error; wrong scope → denied; other org's id → not found.
- **Log leak test:** run main journeys with a unique marker in ticket text; fail if the marker appears in logs.
- **Migration test:** run all migrations up on an empty DB and check every new tenant table has `organization_id`, an RLS policy and an index starting with `organization_id`. `CatalogTest` covers the RLS part; the index check is not built yet.
- **Visibility suite:** for every path in the [ticket visibility predicate](data-model.md#ticket-visibility-predicate) list (list, search, duplicates, My reports, Home, Whose turn and workload counts, manager counts, Mercure publishes, emails, AI inputs, MCP tools, exports): a team-only ticket is visible to team members, admins, its reporter from outside the team and the triager of a no-team ticket, and to nobody else — also not as a number in a count.
- **Tenancy tests (built in P00.T3):** `tests/Integration/Tenancy` covers the policy, the `NULLIF` pooled-connection case, the filter (`TenantNotSet`, one shared SQL text, RLS off and filter off cases), the org switch refusal and the no-result-cache rule; `tests/Functional/Web/RequestTransactionTest` covers commit, rollback and the safety nets. `pinround_retention` has no test connection, so the `BYPASSRLS` path is stood in by disabling RLS through the migrator.
- **RLS details:** the policy fails closed when the setting is unset *and* when it reads back as `''` on a reused pooled connection; `bin/check-tenant-setting` (in `composer lint`) bans every `SET`/`SET LOCAL`/`SET SESSION`/`RESET` of `app.org_id`, any `set_config` for it whose third argument is not `true` (even in the tenant middleware files), and any `set_config` for it outside those files, with the name written literally or as `TenantSetting::NAME` and its quotes plain or backslash-escaped, and `TenantSettingCheckTest` proves it on fixtures and on a copy of `TenantConnection` with `true` flipped (also rewritten with escaped quotes) (full rule list in [data-model.md › Row-level security](data-model.md#row-level-security)); a Messenger handler sees only its message's org; in worker mode the next request (after a normal one and after one that threw) has no tenant and an open EntityManager (`tests/Functional/Web/WorkerResetTest`, one kernel through `disableReboot()`), and after a message whose flush closed the EntityManager the next message saves under its own org, through the bus alone (stamped message, `TenantStampMiddleware` resets it) and through `messenger:consume` on the in-memory `test_queue` (unstamped message, only the worker's reset reopens it; the same run with `--no-reset` fails) (`TenantStampedMessageTest`; what resets what is in [data-model.md › Row-level security](data-model.md#row-level-security)).
- **Resolver functions:** each `SECURITY DEFINER` resolver returns ids for the right key, nothing for a wrong key, never text columns; `CatalogTest` fails unless the `SECURITY DEFINER` functions in every non-system schema are exactly `ResolverFunctions::NAMES` in `public`, each owned by `pinround_resolver`, and on any other function that role owns.
- **Partition grants:** `DatabaseRolesTest` reads `pg_class.relispartition` and fails if any child partition has a grant to a role other than its owner.
- **Index use under RLS:** `EXPLAIN` of search, duplicate and embedding queries as `pinround_app` with the tenant set must use the GIN/HNSW index.
- **SSRF:** the guarded HTTP client refuses `127.0.0.1`, `169.254.169.254`, `10.0.0.1`, an IPv6 loopback and a DNS name that resolves to them, and does not follow redirects.
- **Tokens and admin:** a request with a personal access token gets 403 on every `ADMIN_*` action; creating a token without a fresh MFA check is refused.
- **SSO:** an SSO login that asserts an existing user's email never opens that user's account without linking (password + MFA).
- **Uploads:** an object bigger than its intent (uploaded despite the presigned limit) is deleted by the worker; a header claiming more than 40 MP is refused before decoding.

## Fixtures and test data

- Only neutral, invented data: Acme Foods, project SHOP, Lena (lead), Omar (developer), Mia (tester), Raj (manager), Sara (admin), plus a second org "Globex" for isolation tests.
- Screenshots in tests are small generated images. No real product screenshots.
- A "large org" generator for staging and k6 creates the scale shape from [performance-and-scale.md](performance-and-scale.md#capacity-model).
- Never copy data from the prototype (it contains internal company data).

## CI pipeline

GitHub Actions workflow `CI` (`.github/workflows/ci.yml`) runs on every push and on pull requests to `main`. The five jobs run in parallel. Their ids are the names branch protection requires, so do not rename them. PHP 8.5 comes from `shivammathur/setup-php` on the runner, with the extensions of the Docker image (pdo_pgsql, intl, zip, apcu, redis); the PHP version, extensions and cached Composer install live once, in the local action `.github/actions/php-setup`, used by `static`, `unit`, `integration`, `build` and `e2e`. Every action is pinned to a commit SHA, the workflow uses no secrets, and a newer run on the same ref cancels the older one.

| Job id | Runs | Timeout (job limit) | Target budget |
|---|---|---|---|
| `static` | `composer validate --strict`, `composer cs`, `composer rector`, `composer phpstan`, `composer deptrac`, `composer lint` (container, YAML, Twig, tenant setting, docs: a Markdown file over 100 lines needs `## Index` in its first 40 lines, and relative links and anchors must resolve; copy: `bin/lint-copy` fails on any phrase from the "Avoid" column of the glossary's "Words we do not use" table, or on "workflow status", "status changed", "change status", "transition", "transitioned" (a plural `s` also hits), in the text, `aria-label`/`aria-description`/`title`/`alt`/`placeholder`/`label` values and string literals of Twig templates under `src/` and `templates/`, and in the values of `translations/*.{yaml,yml,xlf}`), `composer audit`: the same commands as `composer qa` minus the tests | 5 min | ≤ 3 min |
| `unit` | `composer test:unit` | 3 min | ≤ 1 min |
| `integration` | Service containers `postgres:18.6` and `valkey/valkey:8.1.4`, both pinned by `@sha256` digest (the same tags as `compose.yaml`, which pins no digest), with health checks, the database `pinround_test` (the test env appends `_test` to the database name in `DATABASE_URL` and `MIGRATOR_DATABASE_URL`); the service's admin user `pinround` runs `docker/postgres/roles.sql`, `sh tests/shell/roles-sql-check.sh` proves the script refuses to run when any of its four passwords is missing or empty and changes nothing, `bin/console doctrine:migrations:migrate` runs in the test env as `pinround_migrator`, `roles.sql` runs once more as a re-run test (not a required step), then `composer test:integration` and `composer test:functional` run as `pinround_app`, with `READONLY_DATABASE_URL` on those two steps for the test-only `readonly` connection (`DatabaseRolesTest` checks the roles) | 15 min | ≤ 10 min |
| `build` | `docker build --target prod` with the GitHub Actions layer cache (no push), then `sh tests/shell/compose-config-check.sh`; then `php-setup` (vendor with the dev packages on the runner), `docker build --target dev` (same build args, so the base stage comes from the prod build) and, in a container of that image with the checkout mounted and no network, `phpunit --group libvips --fail-on-skipped --fail-on-empty-test-suite tests/Integration/Media/Processing/VipsImageEncoderTest.php` against the image's vips (libvips 8.14 of bookworm); step "Run the libvips tests in the app image" | 9 min | ≤ 6 min |
| `e2e` | On pull requests and on pushes to `main` (skipped on other pushes, which counts as passing). Installs vendor and the JS packages on the runner with `php-setup` (so the container does not run a cold `composer install`), builds the dev image with its own GitHub Actions layer cache (`scope=e2e-dev`, app user = runner user), starts `app`, `postgres`, `valkey-state` and `valkey-cache` with `COMPOSE_FILE=compose.yaml:compose.ci.yaml` (the override drops `minio`, `minio-init` and `mailpit` from `app`'s `depends_on`; no `worker-heavy`, scheduler or Jaeger), runs `make db-roles migrate` and the seed, then starts `worker-outbox` and `worker` (the live update and bell specs need the relay and `async_notify`), then every Playwright spec in `tests/e2e` (shell, gallery, components, axe) from the runner on Node 22.12 against `https://localhost`. With `CI` set, the four pixel-compare tests (`toHaveScreenshot`) call `test.skip` and show as skipped, because baselines come from a developer's machine; run `make e2e` locally for them. On failure it prints `docker compose logs app worker-outbox worker` and uploads `tests/e2e/test-results/` (traces) as an artifact for 7 days | 20 min | ≤ 12 min |

Not in CI yet: image scan and SBOM (planned for the main branch). The catalog test `tests/Integration/Tenancy/CatalogTest` runs in `integration` (in `composer test:integration`): on the migrated `pinround_test` it fails on a tenant table or child partition without `ENABLE` and `FORCE ROW LEVEL SECURITY` and the `tenant_isolation` policy of `TenantTable::enableRls`, any other policy on a tenant table that is not the `resolver_read` of `TenantTable::allowResolverRead`, a non-superuser role other than `pinround_retention` with `BYPASSRLS`, `SECURITY DEFINER` functions in any non-system schema that are not exactly `ResolverFunctions::NAMES` in `public` owned by `pinround_resolver`, `pinround_resolver` rights beyond column `SELECT` on `resolver_read` tables, and any role membership of `pinround_app` (tenant tables and functions are looked for in every schema but `pg_catalog`, `information_schema`, `pg_toast` and the temporary ones). No tenant tables exist yet, so its table, policy, function and privilege rules are proved on throwaway objects in rolled-back transactions, and its two role rules on existing roles (see [data-model.md](data-model.md#row-level-security)). The tenant isolation tests in `tests/Integration/Tenancy` and `tests/Functional/Web` already run in `integration` (roles.sql, migrations, then the suites). The `integration` job has not yet run on GitHub for the tenancy branch; `psql` on the runner is unverified.

Total target under 15 minutes. A failed job blocks the merge.

Key e2e journeys (grow phase by phase): log in and see Home; report a problem with a screenshot and pins; lead checks a new ticket; developer starts work, sends to review; reviewer approves; tester passes; ask a question and answer it from My reports; draft round shared on hand-off.

## Definition of done for any task

- [ ] Acceptance criteria in the phase file are met and ticked.
- [ ] Matches the prototype screen(s) named in the task, or the difference is written in hand-off notes with the reason.
- [ ] Unit tests for new rules; integration tests for new queries/voters; functional or e2e test for the journey.
- [ ] Tenant isolation and voter suites extended if the task adds data or actions.
- [ ] Hot paths still within query counts.
- [ ] `composer test`, `composer phpstan`, `composer deptrac`, `composer cs`, `composer audit` green.
- [ ] No ticket content, prompts or tokens in logs.
- [ ] Docs updated (and their Index) if the task changed what they describe; new ADR if an architecture decision changed.
- [ ] Hand-off notes written for the next task.
