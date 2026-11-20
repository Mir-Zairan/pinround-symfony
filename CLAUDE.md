# CLAUDE.md — how to work in this repo

Rules for Claude Code sessions in pinround-symfony. Read this fully before any task.

## Index
- [Start of every session](#start-of-every-session) — the 6 steps before writing code
- [The prototype](#the-prototype) — where it is, how to read it, what is prototype-only
- [Where things are](#where-things-are) — doc map and code layout
- [Architecture rules you must not break](#architecture-rules-you-must-not-break) — modules, tenancy, security, performance
- [Coding rules](#coding-rules) — style, tests, quality gates
- [Writing docs](#writing-docs) — the 100-line index rule and other doc rules
- [Data and secrets](#data-and-secrets) — what may never enter the repo
- [When you finish a task](#when-you-finish-a-task) — definition of done and hand-off

## Start of every session
1. The user names a phase and a task, for example "P05.T3". Open that phase file in [docs/05-plan](docs/05-plan/README.md).
2. Do the phase file's **Before you start** list. Always look at the prototype screens it names (see below).
3. Read the ADRs and architecture docs that the task links. Do not guess a decision that an ADR already made.
4. Check the previous phase's **Definition of done**. If something is missing, tell the user before you build on it.
5. Plan the task in a few lines and share it before large changes.
6. Work only on that task. If you find work for another task, write it in the phase file's **Hand-off notes**.

## The prototype
- Link: https://claude.ai/artifact/FmbJosB3s5mDi75cLqMFQ2. It is private to its owner.
- In Claude Code, read it with the Artifact tool: `action: "read"`, `url` = the link, `path` = `project/Main.dc.html` (the app), `project/Review.dc.html` (review round), `project/Focus.dc.html` (full-screen review), `project/Shot.dc.html` (simulated screenshot).
- If you cannot open it, use [docs/04-prototype/prototype-guide.md](docs/04-prototype/prototype-guide.md). It describes every screen, text, state and rule.
- Prototype-only devices: the "view as" role switch, the "Big team" toggle, simulated screenshots, made-up pings and example teams. Do not build these into the product.
- The product must match the prototype's layout, words and behaviour unless a requirement or ADR says otherwise. When they differ, the requirement wins. Note the difference in the task's hand-off notes.
- The prototype contains internal company data. Never copy names, ticket keys, titles or text from it into code, tests, fixtures or docs.

## Where things are
- Docs map and reading order: [docs/README.md](docs/README.md).
- Code (from phase 0): `src/<Module>/{Domain,Application,Infrastructure,UI,Api}`. Module list and rules: [docs/02-architecture/modules.md](docs/02-architecture/modules.md).
- `demo-shop/`: a small Node shop with planted bugs to try pinround on. Not part of the product; its own `CLAUDE.md` applies there.
- Prompt templates for AI: `src/Assist/Infrastructure/Prompt/` (versioned files, see [ai-architecture.md](docs/02-architecture/ai-architecture.md)).

## Architecture rules you must not break
- **Modules talk only through `src/<Module>/Api`** or domain events. Deptrac fails the build otherwise ([ADR-0002](docs/03-adr/0002-modular-monolith.md)).
- **Every tenant table has `organization_id`**, every index starts with it, and PostgreSQL row-level security is on ([ADR-0005](docs/03-adr/0005-multi-tenancy.md)). Never query without the tenant context.
- **Every action goes through a Voter.** Deny by default ([ADR-0013](docs/03-adr/0013-authorization.md)).
- **No ticket text, AI prompts, AI answers or tokens in logs** ([ADR-0027](docs/03-adr/0027-secrets-and-encryption.md)).
- **AI never writes on its own.** It suggests. A person clicks ([ADR-0020](docs/03-adr/0020-ai-safety-and-prompt-injection.md)).
- **Screenshots never pass through PHP memory as raw uploads.** They use presigned uploads and the processing worker ([ADR-0011](docs/03-adr/0011-screenshot-storage-and-processing.md)).
- **Hot pages read from read models.** No N+1 queries, no lazy loading in templates, keyset pagination ([performance-and-scale.md](docs/02-architecture/performance-and-scale.md)).
- **Worker mode is on (FrankenPHP).** No static state, and services that hold state implement reset ([ADR-0008](docs/03-adr/0008-frankenphp-runtime.md)).

## Coding rules
- PHP 8.4+, `declare(strict_types=1);`, final classes by default, readonly value objects, constructor injection only.
- Names in the domain use the product words from [glossary.md](docs/00-product/glossary.md) (round, pin, verdict, step, group, turn).
- Every task ships with tests: unit tests for domain rules, integration tests for repositories and voters, a functional or e2e test for the user journey.
- Quality gates must be green before you say "done": `composer test`, `composer phpstan` (max), `composer deptrac`, `composer cs`, `composer audit`. These scripts are set up in P00.
- UI copy is plain English, short, active voice. Follow [ux-principles.md](docs/00-product/ux-principles.md).

## Writing docs
- **Any Markdown file over 100 lines starts with `## Index`** right after the title and a one-line purpose. List every `##` section with a short note. Claude Code often reads only the first ~100 lines of a file first, so the index must show what is further down.
- Keep docs in plain English. Use Mermaid for diagrams. Link with relative paths.
- A new decision that changes architecture needs a new ADR ([docs/03-adr/README.md](docs/03-adr/README.md)). Do not edit an accepted ADR's decision; supersede it.

## Data and secrets
- No real company, customer or people data anywhere in the repo. Use the neutral examples: Acme Foods, project SHOP, Lena (lead), Omar (developer), Mia (tester), Raj (manager), Sara (admin).
- No secrets in the repo. Use Symfony secrets or environment variables.

## When you finish a task
- Tick the task's acceptance criteria in the phase file.
- Update docs you changed the truth of (and their Index).
- Write anything the next session must know in **Hand-off notes**.
- Commit with a clear message. Do not push unless the user asks.
- Never add `Co-Authored-By` or any other attribution lines to commits or PRs.
