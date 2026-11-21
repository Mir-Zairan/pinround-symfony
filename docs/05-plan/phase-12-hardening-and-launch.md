# Phase 12 — Hardening and launch

Goal: prove that pinround is fast, safe, recoverable and accessible at the design point, and write down how to run it, before real company data goes in.

## Index
- [Before you start](#before-you-start) — prototype screens, requirements, ADRs and docs to read first
- [Outcome](#outcome) — what is true when this phase is done
- [Scope](#scope) — what this phase does
- [Out of scope](#out-of-scope) — what waits
- [Requirements covered](#requirements-covered) — NFR and FR IDs
- [Design notes](#design-notes) — tools, datasets, files to create
- [Tasks](#tasks) — P12.T1 to P12.T12, one session each
- [Definition of done](#definition-of-done) — release checklist
- [Hand-off notes](#hand-off-notes) — what comes after launch

## Before you start
1. **Open the prototype.** Link: in your `CLAUDE.local.md` (private to its owner). In Claude Code use the Artifact tool with `action: "read"`, `url` = the link and `path: "project/Main.dc.html"`, `project/Review.dc.html` and `project/Focus.dc.html`. In this phase you compare the finished product with every screen once more (task P12.T11). If you cannot open the link, use [prototype-guide.md](../04-prototype/prototype-guide.md) and every file in [screens](../04-prototype/screens/home-work.md) (start from the guide's [screen files](../04-prototype/prototype-guide.md#screen-files) list).
2. **Read the requirements:** all of [non-functional-requirements.md](../01-requirements/non-functional-requirements.md) — [PERF](../01-requirements/non-functional-requirements.md#perf--performance), [SCAL](../01-requirements/non-functional-requirements.md#scal--scalability), [AVL](../01-requirements/non-functional-requirements.md#avl--availability-and-recovery), [SEC](../01-requirements/non-functional-requirements.md#sec--security), [PRIV](../01-requirements/non-functional-requirements.md#priv--privacy), [USE](../01-requirements/non-functional-requirements.md#use--usability), [A11Y](../01-requirements/non-functional-requirements.md#a11y--accessibility), [OPS](../01-requirements/non-functional-requirements.md#ops--operability), [MNT](../01-requirements/non-functional-requirements.md#mnt--maintainability); [constraints.md](../01-requirements/constraints.md).
3. **Read the decisions and design:** [ADR-0028 testing and quality gates](../03-adr/0028-testing-and-quality-gates.md), [ADR-0029 observability](../03-adr/0029-observability.md), [ADR-0030 deployment model](../03-adr/0030-deployment-model.md), [ADR-0005 multi-tenancy](../03-adr/0005-multi-tenancy.md), [ADR-0027 secrets and encryption](../03-adr/0027-secrets-and-encryption.md); [performance-and-scale.md › Load test plan](../02-architecture/performance-and-scale.md#load-test-plan), [performance-and-scale.md › Latency budgets](../02-architecture/performance-and-scale.md#latency-budgets), [security.md › Controls mapped to OWASP ASVS 5.0 Level 2](../02-architecture/security.md#controls-mapped-to-owasp-asvs-50-level-2), [security.md › Tenant isolation](../02-architecture/security.md#tenant-isolation), [security.md › Security test plan per phase](../02-architecture/security.md#security-test-plan-per-phase), [security.md › Supply chain](../02-architecture/security.md#supply-chain), [operations.md › Backups](../02-architecture/operations.md#backups), [operations.md › Restore drill](../02-architecture/operations.md#restore-drill), [operations.md › Observability](../02-architecture/operations.md#observability), [operations.md › Runbooks](../02-architecture/operations.md#runbooks), [testing-strategy.md › Special suites](../02-architecture/testing-strategy.md#special-suites), [trade-offs.md › Risks register](../02-architecture/trade-offs.md#risks-register).
4. **Check the previous phase:** [phase 11 Definition of done](phase-11-admin-and-governance.md#definition-of-done) and every earlier phase's hand-off notes. Collect open items into one list before you start.

## Outcome
Before Acme Foods moves its first real team in, Lena runs the morning-rush load test: 500 requests per second, every page inside its budget. Mia tries to read another org's ticket in twenty ways and fails every time. Omar restores the database to "10 minutes ago" on staging and the app works again in under an hour. Sara reads a one-page runbook when Jira sync stops. A screen-reader user reports a problem with pins using only the keyboard.

## Scope
- k6 load tests against every NFR-PERF budget; fixes.
- ASVS 5.0 Level 2 review with `docs/security/asvs-checklist.md`; tenant-isolation pen-test checklist; dependency and image scans.
- Backups, PITR and a timed restore drill.
- Observability: dashboards, SLOs, alerts.
- Accessibility audit (WCAG 2.2 AA).
- Runbooks in `docs/operations/runbooks/`.
- Final prototype comparison and release checklist.

## Out of scope
- New features. Bugs found here are fixed here; feature ideas go to a backlog file.

## Requirements covered
NFR-PERF-01, NFR-PERF-02, NFR-PERF-03, NFR-PERF-04, NFR-PERF-05, NFR-PERF-06, NFR-PERF-07, NFR-PERF-08, NFR-PERF-09, NFR-PERF-10, NFR-SCAL-01, NFR-SCAL-02, NFR-SCAL-03, NFR-SCAL-04, NFR-SCAL-05, NFR-SCAL-06, NFR-AVL-01, NFR-AVL-02, NFR-AVL-03, NFR-AVL-04, NFR-AVL-05, NFR-SEC-01, NFR-SEC-02, NFR-SEC-03, NFR-SEC-04, NFR-SEC-05, NFR-SEC-06, NFR-SEC-07, NFR-SEC-08, NFR-SEC-09, NFR-SEC-10, NFR-SEC-11, NFR-SEC-12, NFR-SEC-13, NFR-SEC-14, NFR-PRIV-01, NFR-PRIV-02, NFR-PRIV-03, NFR-PRIV-04, NFR-PRIV-05, NFR-PRIV-06, NFR-USE-01, NFR-USE-02, NFR-USE-03, NFR-USE-04, NFR-USE-05, NFR-USE-06, NFR-A11Y-01, NFR-A11Y-02, NFR-A11Y-03, NFR-A11Y-04, NFR-OPS-01, NFR-OPS-02, NFR-OPS-03, NFR-OPS-04, NFR-OPS-05, NFR-OPS-06, NFR-MNT-01, NFR-MNT-02, NFR-MNT-03, NFR-MNT-04, NFR-MNT-05, NFR-MNT-06.
FR check (regression only): all FR IDs through the e2e suite.

## Design notes
- **Dataset:** the "largest org" shape from [performance-and-scale.md › Capacity model](../02-architecture/performance-and-scale.md#capacity-model) — 2,000 users, 200,000 tickets, 2 M screenshot metadata rows (images stubbed), plus a second org "Globex" for isolation. Generated, never copied from real data.
- **Environments:** staging that mirrors production sizes (or a scaled copy with the scale factor written down).
- **Files to create:** `docs/security/asvs-checklist.md`, `docs/security/tenant-isolation-pentest.md`, `docs/operations/runbooks/*.md`, `docs/operations/release-checklist.md`, `docs/operations/prototype-comparison.md`, `tests/load/*.js` (k6), `docs/operations/slo.md`. Every new file over 100 lines starts with `## Index`.
- **Tools (verify versions):** k6, Playwright + axe-core, Trivy (or similar) image scan, CycloneDX SBOM, cosign signing, pgBackRest or WAL-G (choose here for self-host; SaaS uses managed PITR).

## Tasks

### P12.T1 — Load tests and performance fixes
- **Why:** budgets are promises; prove them.
- **Steps:**
  1. Write k6 scenarios: morning rush (60% Home, 25% ticket page, 10% list, 5% actions; ramp to 500 req/s), review session, intake burst (50 reports/min with live duplicate suggestions), search heavy (100/s), AI backlog (1,000 summary jobs), soak (2 h at 50 req/s).
  2. Run on staging with the large dataset; record p50/p95/p99 per route, error rate, queue ages, worker memory.
  3. Fix the worst offenders (indexes, batch loading, read models, cache tags); re-run.
  4. Keep a 1-minute smoke version in CI.
- **Files:** `tests/load/*.js`, `docs/operations/performance-report.md`.
- **Acceptance:**
  - [ ] Home ≤ 250 ms, list ≤ 200 ms, ticket page ≤ 250 ms, search ≤ 300 ms, thumbnail first byte ≤ 150 ms, actions ≤ 150 ms (p95); error rate < 0.1%.
  - [ ] Notification queue age stays < 5 s during the AI backlog test.
  - [ ] No memory growth in workers during soak.
- **Tests:** the k6 runs themselves; query-count tests still green.
- **Prototype check:** none.
- **Size:** L

### P12.T2 — ASVS Level 2 review, part 1
- **Why:** a checklist catches what a busy team forgets.
- **Steps:**
  1. Create `docs/security/asvs-checklist.md` with every ASVS 5.0 Level 2 requirement (verify the official chapter list and numbering), status (pass / fail / n.a.), evidence link (test, config, code).
  2. Review the first half of the chapters (in the official order) using [security.md](../02-architecture/security.md); fix fails or record accepted risks with an owner and date.
- **Files:** `docs/security/asvs-checklist.md`.
- **Acceptance:**
  - [ ] Every requirement of the first half has a status and an evidence link.
  - [ ] No open "fail" item at High or worse in that half; accepted risks signed off by the lead.
- **Tests:** add automated tests for any control found missing.
- **Prototype check:** none.
- **Size:** M

### P12.T3 — ASVS Level 2 review, part 2 and headers
- **Why:** finish the checklist and check what the browser sees.
- **Steps:**
  1. Review the second half of the chapters; fix fails or record accepted risks.
  2. Check security headers (CSP with nonces and the exact bucket hostname in `img-src`, HSTS, Permissions-Policy, frame-ancestors), cookie flags, error pages, debug off.
- **Files:** `docs/security/asvs-checklist.md`.
- **Acceptance:**
  - [ ] Every requirement has a status and evidence; no open "fail" at High or worse.
  - [ ] Header test passes against staging.
- **Tests:** functional header tests; tests for controls found missing.
- **Prototype check:** none.
- **Size:** M

### P12.T4 — Tenant-isolation pen-test
- **Why:** the worst possible bug is one company seeing another's tickets.
- **Steps:**
  1. Create `docs/security/tenant-isolation-pentest.md`: a checklist of attacks with how to run them and the result: IDOR on every route with ids/keys, ticket key guessing across orgs, Mercure topic subscription for another user/org and after logout, signed URL reuse after expiry and across orgs, MCP tools with other org ids, export links, search and duplicate suggestions, read models and counts (team-only tickets), resolver functions with foreign input, worker messages with foreign ids, cache key collisions, `SET` without `LOCAL` (static check), RLS catalog check including partitions.
  2. Run with a peer who did not build the feature (or an external tester); fix and add regression tests.
- **Files:** `docs/security/tenant-isolation-pentest.md`.
- **Acceptance:**
  - [ ] Every line has a result and a test link; zero open cross-tenant findings.
- **Tests:** regression tests for every finding.
- **Prototype check:** none.
- **Size:** M

### P12.T5 — Authentication and session pen-test
- **Why:** a stolen session or token is as bad as a tenant leak.
- **Steps:**
  1. Add an auth section to `docs/security/tenant-isolation-pentest.md`: login throttling, session fixation, MFA bypass, `RecentAuth` bypass, invite reuse, token reuse after revoke, token on admin routes, OIDC state/nonce/PKCE and the cross-org SSO case, CSRF on every form, admin pages without two-step login or with a code over an hour old.
  2. Run with a peer; fix and add regression tests.
- **Files:** `docs/security/tenant-isolation-pentest.md`.
- **Acceptance:**
  - [ ] Every line has a result and a test link; zero open High findings.
- **Tests:** regression tests for every finding.
- **Prototype check:** none.
- **Size:** M

### P12.T6 — Supply chain and image hardening
- **Why:** many breaches come through dependencies and images.
- **Steps:**
  1. Dependabot/Renovate grouped weekly for Composer, importmap, Actions, Docker base image.
  2. Pin Actions to commit SHAs, least-privilege `permissions:`.
  3. Image build: non-root user, read-only file system where possible, base image by digest; scan (fail on High); SBOM (CycloneDX); sign with cosign; verify signature on deploy.
  4. GitHub secret scanning and push protection on.
- **Files:** `.github/workflows/*.yml`, `Dockerfile`, `.github/dependabot.yml` (or `renovate.json`).
- **Acceptance:**
  - [ ] Release image has zero High/Critical known vulnerabilities, an SBOM and a valid signature.
- **Tests:** CI jobs green.
- **Prototype check:** none.
- **Size:** M

### P12.T7 — Backups and timed restore drill
- **Why:** RPO ≤ 15 min and RTO ≤ 4 h are only true if we have done it.
- **Steps:**
  1. SaaS: confirm managed PITR (30 days) and monthly snapshots (12 months). Self-host: choose pgBackRest or WAL-G, write the Compose config, document it.
  2. Bucket versioning + replication; deleted versions kept 30 days.
  3. Restore drill on staging: restore Postgres to a point in time; point a copy of the app at it and at bucket versions; run smoke tests; open sample screenshots; write down the time.
  4. Record the drill in `docs/operations/runbooks/restore-postgres-pitr.md` (results section).
- **Files:** `docs/operations/runbooks/restore-postgres-pitr.md`, `docs/operations/runbooks/restore-deleted-screenshots.md`, `compose.backup.yaml` (self-host).
- **Acceptance:**
  - [ ] Measured RTO ≤ 4 h and data loss ≤ 15 min in the drill.
- **Tests:** smoke suite against the restored copy.
- **Prototype check:** none.
- **Size:** M

### P12.T8 — Observability, SLOs and alerts
- **Why:** we must know about problems before users tell us.
- **Steps:**
  1. Dashboards: request rate/errors/latency per route, queue depth and oldest message per transport, worker memory, DB connections, cache hit rate, AI calls and cost per org, storage per org.
  2. SLOs in `docs/operations/slo.md` (availability 99.9%, Home p95, ticket p95, notification in 5 s, screenshot ready in 10 s) with error budgets.
  3. Alerts: error rate > 2% for 5 min, `notify` oldest > 30 s, `media` > 2 min, DB connections > 85%, disk > 80%, any RLS violation error, backup failed; capacity warnings from operations.md.
  4. Health endpoints `/health/live` and `/health/ready` (P00.T8, [operations.md](../02-architecture/operations.md#observability)) checked by the platform from its internal network. They answer only loopback and private client IPs (others get a 404), so configure trusted proxies: otherwise a request a proxy forwards from the internet carries the proxy's private IP and gets through. The body says only ok or fail.
  5. Log-leak marker test runs in CI on the main journeys.
- **Files:** `docs/operations/slo.md`, `deploy/observability/*` (dashboards as code where possible).
- **Acceptance:**
  - [ ] Each alert fired once in a staged test and reached the on-call channel.
- **Tests:** synthetic alert tests; log-leak test.
- **Prototype check:** none.
- **Size:** M

### P12.T9 — Accessibility audit
- **Why:** pinround must work for everyone, including keyboard and screen-reader users.
- **Steps:**
  1. Run axe on every page in the e2e suite (Home all roles, Tickets list and Whose turn, ticket page, Report a problem, My reports, round view, full-screen review, Team, every Admin tab, dialogs).
  2. Manual checks: keyboard-only journeys (including placing and editing pins), focus order and visible focus, screen reader labels (NVDA or VoiceOver), 360 px width, 200% zoom, color never the only signal (group bars, dots, verdicts).
  3. Fix issues; write `docs/operations/accessibility-report.md`.
- **Files:** `tests/e2e/a11y.spec.ts`, `docs/operations/accessibility-report.md`.
- **Acceptance:**
  - [ ] Zero axe violations of level serious/critical; manual checklist complete (WCAG 2.2 AA).
- **Tests:** a11y e2e in CI.
- **Prototype check:** dark and light tokens from [prototype-guide.md › Design tokens](../04-prototype/prototype-guide.md#design-tokens) keep contrast ≥ 4.5:1 for text.
- **Size:** M

### P12.T10 — Runbooks
- **Why:** people on call need short, tested steps.
- **Steps:**
  1. Write runbooks in `docs/operations/runbooks/` (one file each, sections: symptoms, checks, fix steps, how to confirm): restore Postgres PITR, restore deleted screenshots, rotate the field-encryption key, revoke all sessions and tokens for a user or org, Jira sync paused, queue backlog, AI provider down, disk full on self-host, security incident first hour.
  2. Add `docs/operations/runbooks/README.md` listing them.
- **Files:** `docs/operations/runbooks/*.md`.
- **Acceptance:**
  - [ ] Each runbook was tried once on staging (or a dry run noted).
- **Tests:** none new.
- **Prototype check:** none.
- **Size:** M

### P12.T11 — Final prototype comparison
- **Why:** the product must look and behave like what we promised.
- **Steps:**
  1. Walk every prototype screen side by side with the product (all roles, all Admin tabs, Review and Focus boards), using the screen list in [prototype-guide.md › Screen files](../04-prototype/prototype-guide.md#screen-files).
  2. List differences; fix or justify each in the phase hand-off notes and, if lasting, in the prototype guide.
- **Files:** `docs/operations/prototype-comparison.md`.
- **Acceptance:**
  - [ ] The difference list has zero unexplained items.
  - [ ] The e2e suite is green after the fixes.
- **Tests:** e2e suite.
- **Prototype check:** every screen.
- **Size:** M

### P12.T12 — Release checklist and go-live
- **Why:** one list, ticked, before real data enters.
- **Steps:**
  1. Write `docs/operations/release-checklist.md`: gates green, ASVS checklist done, pen-test clean, load test report, restore drill result, SLO dashboards live, runbooks ready, data-protection notes (GDPR, EU region, AI providers with no training on data), admin onboarding guide, support contact.
  2. Tag the release; deploy with expand/contract migrations; watch dashboards for 24 h.
- **Files:** `docs/operations/release-checklist.md`.
- **Acceptance:**
  - [ ] Every item ticked with a link to evidence.
- **Tests:** post-deploy smoke tests.
- **Prototype check:** none.
- **Size:** S

## Definition of done
- [ ] All NFR budgets met in the load report, with evidence.
- [ ] `docs/security/asvs-checklist.md` and `docs/security/tenant-isolation-pentest.md` complete; zero open cross-tenant or High findings.
- [ ] Restore drill timed and within RPO/RTO.
- [ ] Accessibility report complete; axe clean.
- [ ] Runbooks and release checklist written and tried.
- [ ] Quality gates green; docs and their Index updated (the doc Index lint passes).

## Hand-off notes
- After launch: keep the restore drill quarterly, the dependency updates weekly, and the k6 smoke in CI.
- Write here the measured numbers (p95 per route, RTO, data loss) so the next team can see the baseline.
