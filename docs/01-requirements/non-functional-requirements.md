# Non-functional requirements

How fast, safe, available, usable and maintainable pinround must be, how each target is checked, and where the numbers come from.

## Index

- [How to read this document](#how-to-read-this-document): ID scheme and verification column.
- [Requirement analysis: where the numbers come from](#requirement-analysis-where-the-numbers-come-from): capacity math for users, tickets, screenshots, requests.
- [PERF — Performance](#perf--performance): latency budgets, frontend speed, background jobs.
- [SCAL — Scalability](#scal--scalability): design point and growth rules.
- [AVL — Availability and recovery](#avl--availability-and-recovery): uptime, RPO, RTO, degradation.
- [SEC — Security](#sec--security): ASVS, tenant isolation, auth, uploads, secrets, logs.
- [PRIV — Privacy](#priv--privacy): GDPR, data minimization, AI data, retention.
- [USE — Usability](#use--usability): one primary action, plain language, task times.
- [A11Y — Accessibility](#a11y--accessibility): WCAG 2.2 AA.
- [OPS — Operability](#ops--operability): observability, deploys, backups, alerts.
- [MNT — Maintainability](#mnt--maintainability): module rules, quality gates, docs.

## How to read this document

- **ID:** `NFR-<CAT>-NN`. Each has a measurable **target** and a **verify** line saying which test or tool proves it.
- Latency numbers are **server time at p95, warm cache, at the design load**, measured at the app (not the network) unless stated.
- Targets apply to v1 (after phase 12). Earlier phases must not make them impossible (for example: no N+1 queries even in phase 3).

## Requirement analysis: where the numbers come from

*Story:* A mid-size org has 300 people in 25 teams. Each tester reports 5 problems a day with 2 screenshots each. Each review round adds 4–6 screenshots. After two years they have 150,000 tickets and 2 million screenshots. Home is opened by everyone each morning between 8:45 and 9:15. These numbers decide the design.

### Design point (headroom, not day-1 load)

| Quantity | Design point | Reasoning |
|---|---|---|
| Organizations | 500 | SaaS goal for year 2; self-hosted installs are 1 org. |
| Users | 50,000 | 500 orgs × 100 average. |
| Largest org | 2,000 people, 200,000 tickets | Big enterprise department. |
| Tickets | 2,000,000 | 500 orgs × 4,000 average after 2 years. |
| Ticket events | ~40,000,000 | ~20 events per ticket (moves, checks, verdicts). Partitioned by month. |
| Messages | ~16,000,000 | ~8 per ticket. |
| Screenshots | 20,000,000 | ~10 per ticket (report + rounds). |
| Screenshot storage | ~6 TB (+ ~1 TB thumbnails) | 20M × ~300 KB after WebP re-encode; thumbnails ~40 KB each. |
| Database size | ~150–250 GB | Rows above with indexes; descriptions ~2 KB avg; events ~0.5 KB. |
| Requests | 50 req/s average, 500 req/s peak | 50k users × ~80 requests per working day ≈ 4M/day ≈ 46 req/s over 24 h; morning peak ~10× average. |
| Live connections (Mercure) | ~15,000 concurrent at peak | 30% of users online at once. |
| AI calls | ~200,000 per day | Summaries on create/sync + explanations; mostly async. |

### What follows from the numbers

- Home cannot compute "whose turn" by scanning tickets: 200k tickets in one org × 2,000 morning Home loads = too slow. ⇒ stored turn read model with indexes ([ADR-0015](../03-adr/0015-turn-read-model.md)).
- 6 TB of images cannot sit in PostgreSQL or pass through PHP. ⇒ object storage, presigned uploads, CDN/signed URLs ([ADR-0011](../03-adr/0011-screenshot-storage-and-processing.md)).
- 40M events need cheap deletes for retention. ⇒ monthly partitions.
- 500 req/s peak with p95 ≤ 250 ms ⇒ ~10–20 PHP workers in worker mode on 2–3 app nodes; database must answer hot queries in < 20 ms ⇒ composite indexes that start with `organization_id`.
- AI is slow (1–20 s). ⇒ never in the request path except streamed explanations; summaries async.

## PERF — Performance

| ID | Target | Verify |
|---|---|---|
| NFR-PERF-01 | Home page ≤ 250 ms p95 server time for a user with 200 open items in an org of 200k tickets. | k6 scenario `home_morning_peak` (phase 12); Blackfire/OTel trace budget in CI smoke test. |
| NFR-PERF-02 | Ticket list page (50 rows, any filter) ≤ 200 ms p95. | k6 `tickets_list`; query count assertion ≤ 5 queries per request in functional tests. |
| NFR-PERF-03 | Ticket page ≤ 250 ms p95 with 200 messages and 10 rounds. | k6 `ticket_page`; query count ≤ 10. |
| NFR-PERF-04 | Search and duplicate suggestions ≤ 300 ms p95 on 200k tickets. | k6 `search`; `EXPLAIN` checks in integration tests use GIN/trigram indexes. |
| NFR-PERF-05 | Screenshot thumbnail first byte ≤ 150 ms p95 (from object store/CDN). | Synthetic check in staging. |
| NFR-PERF-06 | Action (move, answer, verdict) ≤ 150 ms p95 server time; side effects (notifications, Jira, AI) async. | k6 `actions`; tests assert no external HTTP call inside the request. |
| NFR-PERF-07 | Frontend: LCP ≤ 2.0 s on a mid laptop over 4G-fast; INP ≤ 200 ms; JS ≤ 120 KB gzipped per page; CLS ≤ 0.1. | Lighthouse CI on 5 key pages; bundle size check. |
| NFR-PERF-08 | Screenshot processing (verify, re-encode, thumbnail) done within 10 s p95 after upload. | Worker metric `media_processing_seconds`. |
| NFR-PERF-09 | Background queues: job age p95 ≤ 30 s (default queue), ≤ 5 min (AI and Jira queues). | Messenger queue-age metric + alert. |
| NFR-PERF-10 | No N+1 queries and no lazy loading in templates. | Doctrine query counter in functional tests; PHPStan rule/review checklist. |

## SCAL — Scalability

| ID | Target | Verify |
|---|---|---|
| NFR-SCAL-01 | Meets PERF targets at the design point in [the table above](#design-point-headroom-not-day-1-load). | k6 at 500 req/s with seeded data (phase 12). |
| NFR-SCAL-02 | App nodes are stateless (sessions, cache, locks in Valkey; files in object storage); adding nodes adds capacity linearly to 4 nodes. | Load test with 1, 2, 4 nodes. |
| NFR-SCAL-03 | Every tenant table and index starts with `organization_id`; large append tables (ticket events, audit, notifications) are partitioned by month. | Schema test that checks indexes and partitions. |
| NFR-SCAL-04 | Lists use keyset pagination; no `OFFSET` above 1,000 rows. | Code review rule + query tests. |
| NFR-SCAL-05 | Workers scale independently per queue (media, AI, Jira, notifications). | Deployment config; load test. |
| NFR-SCAL-06 | A module can later be split into a service without changing other modules' code (only its Api adapter). | Deptrac rules ([ADR-0002](../03-adr/0002-modular-monolith.md)). |

## AVL — Availability and recovery

| ID | Target | Verify |
|---|---|---|
| NFR-AVL-01 | SaaS availability 99.9% per month (≤ 43 min downtime). | Uptime monitor; SLO dashboard. |
| NFR-AVL-02 | RPO ≤ 15 minutes (PostgreSQL WAL archiving / point-in-time recovery; bucket versioning). | Quarterly restore drill. |
| NFR-AVL-03 | RTO ≤ 4 hours for full region loss of the database. | Restore drill timed. |
| NFR-AVL-04 | Deploys without downtime; migrations follow expand/contract. | CI migration lint; deploy runbook. |
| NFR-AVL-05 | Graceful degradation: if AI, Jira, email or Mercure is down, core work (view, move, comment, rounds) still works; the UI says what is paused. | Chaos tests in staging (provider stubs return errors). |

## SEC — Security

| ID | Target | Verify |
|---|---|---|
| NFR-SEC-01 | Meets OWASP ASVS 5.0 Level 2 for all in-scope chapters. | ASVS checklist review per release ([security.md](../02-architecture/security.md)). |
| NFR-SEC-02 | No OWASP Top 10 issue open with severity High or above at release. | SAST, dependency audit, manual review, pen-test checklist (phase 12). |
| NFR-SEC-03 | Tenant isolation: no user, token or job can read or write another organization's data. Enforced twice: app filter and PostgreSQL row-level security. | Isolation test suite runs every HTTP route, MCP tool and repository with a second org's ids; RLS tests with a raw connection. |
| NFR-SEC-04 | Deny by default: every controller action, MCP tool and API route has a voter check. | Test that lists routes without `IsGranted`/voter call fails the build. |
| NFR-SEC-05 | MFA required for admins; passwords Argon2id; login throttling; session rotation. | Functional tests. |
| NFR-SEC-06 | Uploads: only PNG/JPEG/WebP ≤ 15 MB, magic bytes checked, re-encoded, metadata stripped, served from a separate origin or with `Content-Disposition` and strict `Content-Type`; never executed. | Upload tests with polyglot and SVG files. |
| NFR-SEC-07 | Strict CSP with nonces (no inline scripts), HSTS, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `frame-ancestors 'none'`. | Header test on every page. |
| NFR-SEC-08 | CSRF protection on every state-changing form and Live Component action. | Functional tests. |
| NFR-SEC-09 | No ticket text, AI prompts/answers, tokens, passwords or secrets in logs or error reports. | Log redaction tests; review of Monolog processors. |
| NFR-SEC-10 | Secrets only in Symfony secrets vault or the runtime environment; Jira/OIDC/AI credentials encrypted in the DB with key rotation. | Secret scanning in CI (gitleaks); encryption tests. |
| NFR-SEC-11 | Dependencies: `composer audit` and importmap audit clean of known High/Critical issues before merge. | CI gate. |
| NFR-SEC-12 | AI safety: ticket content treated as untrusted data; AI never writes without a click; MCP acts only with the user's rights. | Prompt-injection test cases ([ADR-0020](../03-adr/0020-ai-safety-and-prompt-injection.md)). |
| NFR-SEC-13 | Team-only tickets never appear in lists, search, counts, duplicates, notifications, exports or AI prompts for people outside the team (admins excepted). | Visibility test suite. |
| NFR-SEC-14 | Rate limits: login, invites, pings, uploads, AI, API/MCP (per token: 120 requests/min default). | Functional tests with limiter. |

## PRIV — Privacy

| ID | Target | Verify |
|---|---|---|
| NFR-PRIV-01 | GDPR: data of EU customers stays in the EU (SaaS region EU); a data processing agreement template exists. | Hosting config; legal doc. |
| NFR-PRIV-02 | Data minimization: AI gets only the fields the policy allows ("title only" sends no description); laptop AI keeps text on the device. | Tests on prompt builder per policy. |
| NFR-PRIV-03 | Retention: data older than the org setting is deleted within 7 days of passing the limit, including files and backups after their own rotation (35 days). | Retention job test; backup rotation config. |
| NFR-PRIV-04 | Right to erasure: an admin can remove a user's personal data (name, email) — their content stays, shown as "Former member". | Functional test. |
| NFR-PRIV-05 | Export: an org can export all its data in open formats. | FR-AUD-04 tests. |
| NFR-PRIV-06 | Cloud AI providers used only with a "no training on customer data" setting/contract; shown in the admin AI page. | Provider config review. |

## USE — Usability

| ID | Target | Verify |
|---|---|---|
| NFR-USE-01 | Each screen part has at most one primary (filled) button. | UI review checklist ([ux-principles.md](../00-product/ux-principles.md#review-checklist-for-a-new-screen)). |
| NFR-USE-02 | A new user can report a problem with a screenshot and a pin in under 2 minutes without help. | Usability test with 5 people (phase 12). |
| NFR-USE-03 | A lead can find the 5 most urgent items in under 10 seconds from login. | Usability test. |
| NFR-USE-04 | All UI copy is plain English (B1) and uses glossary words; no status jargon. | Copy review; glossary lint (word list) in CI for templates. |
| NFR-USE-05 | Every ticket shows Now (and Next when not done). | Functional tests on ticket page. |
| NFR-USE-06 | 80% of surveyed users agree "I know what to do next" after 2 weeks. | In-app survey. |

## A11Y — Accessibility

| ID | Target | Verify |
|---|---|---|
| NFR-A11Y-01 | WCAG 2.2 Level AA on all pages, light and dark. | axe-core in Playwright e2e on key pages; manual keyboard and screen-reader pass per phase. |
| NFR-A11Y-02 | Full keyboard use, including placing pins (arrow keys + Enter) and reviewing pins. | e2e keyboard tests. |
| NFR-A11Y-03 | Works at 360 px width and 200% zoom without horizontal scrolling. | Playwright viewport tests. |
| NFR-A11Y-04 | Color is never the only signal (pills have text, bars have numbers). | Review checklist. |

## OPS — Operability

| ID | Target | Verify |
|---|---|---|
| NFR-OPS-01 | OpenTelemetry traces for HTTP, Doctrine, Messenger, AI and Jira calls; JSON logs with trace id and org id. | Trace visible in local Jaeger/Tempo in phase 0. |
| NFR-OPS-02 | Health (`/health/live`) and readiness (`/health/ready`: DB, Valkey, storage) endpoints. | Functional test. |
| NFR-OPS-03 | Alerts: error rate > 1% for 5 min, p95 above budget for 10 min, queue age above target, failed jobs > 10/h, disk/DB > 80%. | Alert rules in repo. |
| NFR-OPS-04 | One-command local setup (`make up` or `docker compose up`) in under 10 minutes on a fresh laptop. | Fresh-clone test in CI. |
| NFR-OPS-05 | Self-hosted small org runs on one 4 vCPU / 8 GB VM with Docker Compose. | Compose profile test. |
| NFR-OPS-06 | Runbooks for deploy, rollback, restore, key rotation, incident. | Docs exist ([operations.md](../02-architecture/operations.md)). |

## MNT — Maintainability

| ID | Target | Verify |
|---|---|---|
| NFR-MNT-01 | Module boundaries enforced: modules use only other modules' `Api` namespace or events. | Deptrac in CI. |
| NFR-MNT-02 | PHPStan at max level with strict rules, zero errors (baseline only for vendor issues). | CI. |
| NFR-MNT-03 | Domain and application code ≥ 85% line coverage; every FR acceptance criterion has a test. | Coverage report; test names include FR IDs. |
| NFR-MNT-04 | Code style fixed by PHP-CS-Fixer; Rector for upgrades. | CI. |
| NFR-MNT-05 | Every architecture decision has an ADR; docs over 100 lines start with an Index. | Doc lint script in CI. |
| NFR-MNT-06 | Symfony and PHP kept on supported versions; upgrade to each Symfony minor within 2 months; move to 8.4 LTS within 3 months of its release. | Dependency calendar ([ADR-0003](../03-adr/0003-php-and-symfony-versions.md)). |
