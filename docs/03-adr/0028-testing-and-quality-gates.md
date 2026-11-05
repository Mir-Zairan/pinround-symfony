# ADR-0028: Testing strategy and quality gates: nothing merges unless every gate is green

Each change ships with tests at the right level, and CI blocks the merge if any check fails.

## Index

- [Status and people](#status-and-people): status, date, deciders.
- [Context and problem statement](#context-and-problem-statement): the scene: a 3-person team using AI to write code.
- [Decision drivers](#decision-drivers): safety nets that matter most here.
- [Considered options](#considered-options): manual testing, unit-heavy, layered suite with gates.
- [Decision outcome](#decision-outcome): test levels, tools, special suites, CI gates.
  - [Consequences](#consequences): good, bad and neutral effects.
- [Confirmation](#confirmation): how we check the rule is followed.
- [Pros and cons of the options](#pros-and-cons-of-the-options): details per option.
- [More information](#more-information): links and when to revisit.

## Status and people

- Status: Accepted
- Date: 2026-11-06
- Deciders: Zairan (lead), Toyib (developer), Anka (tester)

## Context and problem statement

Much of pinround will be written in Claude Code sessions, one task at a time. A session can write code that passes its own happy path and quietly breaks a voter, a tenant filter or a module boundary built three phases earlier. With three people, nobody can re-test everything by hand. The most expensive bugs here are data leaks (between tenants, or restricted tickets) and wrong "whose turn" results that send work to the wrong person.

## Decision drivers

- Catch leaks and permission bugs automatically, every time.
- Fast feedback: the unit and integration suite under 5 minutes in CI.
- Tests describe behaviour in product words, so Anka (tester) can read them.
- Gates are the same locally and in CI (`composer` scripts).

## Considered options

1. **Manual testing plus a few unit tests.**
2. **Unit-heavy suite only.**
3. **Layered suite (unit, integration, functional, e2e) plus special suites and static gates (chosen).**

## Decision outcome

Chosen option: **3**.

| Level | Tool | What it covers |
|---|---|---|
| Unit | PHPUnit | Domain rules: transition guards, check reasons, needs-you score, turn rules, rate-limit keys, encryption. No DB. |
| Integration | PHPUnit + Symfony KernelTestCase + real PostgreSQL 18 and Valkey (Docker) | Repositories, RLS, voters, Messenger handlers, outbox, migrations. |
| Functional | Symfony WebTestCase / test client | Routes, forms, CSRF, headers, JSON API and MCP responses. |
| End-to-end | Playwright | Key journeys against the running stack: login, report a problem, move through steps, draft round + share, ping, My reports answer. |
| Fixtures | Zenstruck Foundry | Factories with neutral example data (Acme Foods, SHOP). |

Special suites (all required in CI):

- **Tenant isolation suite:** for every tenant entity and every list or search endpoint, data from org B is never returned to a user of org A. RLS is on, and the Doctrine filter is temporarily disabled in the test to prove RLS alone blocks it.
- **Voter matrix suite:** a table of role × action × object state → allow/deny, generated from the roles doc. It covers restricted tickets, drafts and private AI answers.
- **AI safety suite:** see ADR-0020.
- **Accessibility:** axe checks in Playwright on the main pages (WCAG 2.2 AA rules).
- **Load:** k6 scripts for the budgets in non-functional requirements (phase 12, run nightly on staging, not on every PR).

Static and supply-chain gates:

- PHPStan at max level with strict rules and the Symfony and Doctrine extensions.
- Deptrac (module boundaries, ADR-0002).
- PHP-CS-Fixer (Symfony rules).
- Rector in dry-run mode, to catch outdated patterns.
- `composer audit` (fails on known vulnerable packages).
- `composer validate --strict`.
- Doctrine schema validation, and a migrations up/down check on an empty DB.
- Secret scanning, and Twig lint and container lint.

CI on GitHub Actions: one workflow with jobs for static, unit+integration (service containers: postgres:18, valkey), functional, and e2e (on PRs to `main` and nightly). Branch protection requires all jobs to pass.

Coverage: no global percentage gate. Instead, every task's acceptance criteria name the tests. Domain and voter code must have a test per rule. Optional mutation testing (Infection) on the domain folders, run as a weekly report.

### Consequences

- Good: leaks and permission bugs are caught by suites built for them.
- Good: AI-written code meets the same bar as human code.
- Bad: CI time and upkeep. Mitigated by parallel jobs and running e2e only on PRs to `main`.
- Neutral: test data must stay neutral (no real company data), and this is checked in review.

## Confirmation

- Branch protection on `main` lists every required job.
- `composer test`, `composer phpstan`, `composer deptrac`, `composer cs`, `composer audit` exist from phase 0 and run the same commands as CI.
- Every phase file's Definition of done includes "all gates green" and names the new tests.
- CI duration is tracked: alert the team if the median exceeds 12 minutes.

## Pros and cons of the options

### 1. Manual plus a few unit tests

- Good, because it is fast to start.
- Bad, because leaks and regressions go unseen, and it does not scale with AI-written code.

### 2. Unit-heavy only

- Good, because it is fast.
- Bad, because it misses RLS, SQL, voters wired to routes, and real browser flows.

### 3. Layered suite plus gates

- Good, because it targets our real risks.
- Bad, because there is more setup in phase 0.

## More information

- Related: [ADR-0002](0002-modular-monolith.md), [ADR-0005](0005-multi-tenancy.md), [ADR-0013](0013-authorization.md), [ADR-0020](0020-ai-safety-and-prompt-injection.md).
- Architecture: [testing-strategy.md](../02-architecture/testing-strategy.md).
- Revisit when CI exceeds 15 minutes median, or when a leak or permission bug reaches production. Then add the missing suite case first, before the fix.
