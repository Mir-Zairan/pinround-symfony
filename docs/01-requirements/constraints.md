# Constraints

The fixed limits pinround must live with, what each one costs, and how the architecture answers it.

## Index

- [Why constraints come first](#why-constraints-come-first): the story of a decision made too late.
- [Summary table](#summary-table): all constraints in one view.
- [C-01 Hackathon deadline and required technology](#c-01-hackathon-deadline-and-required-technology)
- [C-02 Team of three](#c-02-team-of-three)
- [C-03 Organizations that cannot pay for AI services](#c-03-organizations-that-cannot-pay-for-ai-services)
- [C-04 Sensitive company data](#c-04-sensitive-company-data)
- [C-05 Coexistence with Jira](#c-05-coexistence-with-jira)
- [C-06 PHP and Symfony versions](#c-06-php-and-symfony-versions)
- [C-07 Browser rules for laptop AI](#c-07-browser-rules-for-laptop-ai)
- [C-08 Symfony AI is still beta](#c-08-symfony-ai-is-still-beta)
- [C-09 GDPR and EU data residency](#c-09-gdpr-and-eu-data-residency)
- [C-10 Self-hosting on small machines](#c-10-self-hosting-on-small-machines)
- [C-11 Screenshots are large and many](#c-11-screenshots-are-large-and-many)
- [C-12 Claude Code as a first-class client](#c-12-claude-code-as-a-first-class-client)
- [C-13 The prototype is the UX contract](#c-13-the-prototype-is-the-ux-contract)

## Why constraints come first

A team once picked a search engine in week one, then learned that their customers' data had to stay in one country and the engine's cloud was elsewhere. They lost a month. We write constraints down first, so every [ADR](../03-adr/README.md) can say which constraint it answers.

## Summary table

| ID | Constraint | Main impact | Answered by |
|---|---|---|---|
| C-01 | Hackathon by 22 Nov 2026 18:00 CET; must use Symfony AI, `mcp/sdk`, Hugging Face | Thin first slice, fixed tech | ADR-0018, ADR-0021, plan 🏁 |
| C-02 | Team of three (lead/dev, tester, dev) | One codebase, little ops | ADR-0002, ADR-0007, ADR-0008, ADR-0030 |
| C-03 | Some orgs cannot pay for AI APIs | AI must run free on laptops | ADR-0018, ADR-0019 |
| C-04 | Sensitive company data (incl. security bugs) | Strong isolation, least privilege, no content in logs | ADR-0005, ADR-0013, ADR-0020, ADR-0026, ADR-0027 |
| C-05 | Companies still use Jira | Import and sync, not replace on day one | ADR-0025 |
| C-06 | PHP ≥ 8.4, Symfony 8.1 now, 8.2 soon, 8.4 LTS later | Upgrade path | ADR-0003 |
| C-07 | Browsers guard access to localhost | Laptop AI needs CORS + user permission | ADR-0019 |
| C-08 | Symfony AI 1.0 is beta | API may change | ADR-0018 |
| C-09 | GDPR, EU data residency | EU hosting, retention, export, erasure | ADR-0026, ADR-0027, ADR-0030 |
| C-10 | Small orgs self-host on one VM | Few moving parts | ADR-0008, ADR-0010, ADR-0030 |
| C-11 | Millions of screenshots | Object storage, async processing | ADR-0011 |
| C-12 | Claude Code must read and write rounds | MCP server with tokens | ADR-0017, ADR-0021 |
| C-13 | Product must match the prototype | Server-rendered UI that can mirror it | ADR-0007 |

## C-01 Hackathon deadline and required technology

**What:** The Symfony AI & MCP Hackathon ends **22 November 2026, 18:00 CET** (16 days from 6 November). Entries must use Symfony AI, the official PHP MCP SDK (`mcp/sdk`) and Hugging Face.
**Impact:** We cannot build everything by then. The demo must show the core idea (rounds with pins + AI + Claude Code via MCP) working end to end.
**Response:**
- A hackathon slice is marked 🏁 in the [requirements](functional-requirements.md) and [plan](../05-plan/README.md): login, one team, tickets list and page, screenshots and pins, private draft round, AI summary and intake suggestions via Symfony AI with a Hugging Face model (and Ollama), MCP tools `list_my_tickets`, `get_round_notes`, `add_screenshot`, `set_verdict`.
- The slice uses the final architecture (modules, tenancy, voters). We cut features, not foundations, so nothing is thrown away after the hackathon.
- Risk: scope creep. Mitigation: anything not 🏁 waits until after 22 Nov.

## C-02 Team of three

**What:** Lena-like lead/developer, one tester, one developer. Claude Code helps.
**Impact:** No time for many services, two frontends or a big ops setup.
**Response:** one modular monolith ([ADR-0002](../03-adr/0002-modular-monolith.md)); server-rendered UI with Symfony UX, no separate SPA ([ADR-0007](../03-adr/0007-server-rendered-ui.md)); one Docker image for app and workers ([ADR-0008](../03-adr/0008-frankenphp-runtime.md), [ADR-0030](../03-adr/0030-deployment-model.md)); tasks sized for one Claude Code session each.

## C-03 Organizations that cannot pay for AI services

**What:** Some organizations want AI features but cannot pay for Claude, OpenAI or hosted inference.
**Impact:** AI must work with a free model on ordinary laptops (CPU only, 8–16 GB RAM).
**Response:**
- Admin chooses where AI runs: cloud, each laptop (Ollama), or laptop first with cloud fallback ([ADR-0018](../03-adr/0018-ai-provider-abstraction.md)).
- In laptop mode the **browser** calls Ollama on `localhost:11434`; the server never reaches into laptops ([ADR-0019](../03-adr/0019-browser-local-ai.md)).
- Recommended models: `qwen3:4b` (~2.5 GB, good at JSON) or `phi4-mini` (lighter). Test on the slowest laptop first.
- Prompts are short and tasks small (summary ≤ 60 words, JSON suggestions) so a 4B model can do them in seconds.
- Features that need heavy models (vision pin suggestions) are optional and only on cloud/server providers.

## C-04 Sensitive company data

**What:** Tickets hold internal plans, customer names and security bugs (for example a data leak between customers). One leak would end trust.
**Impact:** Security is a hard requirement, not a feature.
**Response:**
- Two layers of tenant isolation: app filter + PostgreSQL row-level security ([ADR-0005](../03-adr/0005-multi-tenancy.md)).
- Deny-by-default voters; team-only visibility for security reports ([ADR-0013](../03-adr/0013-authorization.md)).
- AI treats ticket content as untrusted data and never acts alone ([ADR-0020](../03-adr/0020-ai-safety-and-prompt-injection.md)).
- No ticket text, prompts or tokens in logs; credentials encrypted ([ADR-0027](../03-adr/0027-secrets-and-encryption.md)).
- Append-only audit log ([ADR-0026](../03-adr/0026-audit-log.md)).
- MFA for admins; OWASP ASVS L2 baseline ([NFR-SEC-01](non-functional-requirements.md#sec--security)).

## C-05 Coexistence with Jira

**What:** Companies will not switch off Jira on day one. Some teams stay there for months.
**Impact:** pinround must import Jira tickets, map statuses to steps, and push moves back.
**Response:** Jira is an integration, not the source of pinround's own data (rounds, pins, checks stay in pinround) ([ADR-0025](../03-adr/0025-jira-integration.md)); status mapping table; webhooks + nightly reconcile; last-writer-wins per field with activity entries.

## C-06 PHP and Symfony versions

**What:** Symfony 8.1 is the current stable (needs PHP ≥ 8.4, support ends January 2027). Symfony 8.2 is due end of November 2026 (support to July 2027). The next LTS is 8.4 (November 2027). Symfony 7.4 is the current LTS.
**Impact:** We must upgrade twice in the first year.
**Response:** start on 8.1 with PHP 8.4 minimum (run 8.5), move to 8.2 after the hackathon, then to 8.4 LTS within 3 months of release ([ADR-0003](../03-adr/0003-php-and-symfony-versions.md)). Rector and a deprecation-free test run keep upgrades small.

## C-07 Browser rules for laptop AI

**What:** A web page that calls `http://localhost:11434` needs Ollama to allow its origin (`OLLAMA_ORIGINS`), and newer browsers ask the user for permission before a public site may talk to the local network.
**Impact:** Laptop AI needs a one-time setup and a user click; it can fail silently if blocked.
**Response:** check only on click (never on page load); a setup dialog with four plain steps; clear status chip ("Local AI: not found"); cloud fallback when the admin chose "laptop first" ([FR-AI-10](functional-requirements.md#ai--ai-assist), [ADR-0019](../03-adr/0019-browser-local-ai.md)).

## C-08 Symfony AI is still beta

**What:** Symfony AI is at 1.0.0-BETA1 (verified 2026-11-06; check again at implementation). Class names and options can still change.
**Impact:** Upgrades may break AI code.
**Response:** all AI calls go through one pinround `AiGateway` interface in the Assist module; only its infrastructure adapter knows Symfony AI ([ADR-0018](../03-adr/0018-ai-provider-abstraction.md)). Pin exact versions; contract tests with recorded responses; when unsure of an API, check the docs at implementation time.

## C-09 GDPR and EU data residency

**What:** Customers are mostly in the EU. Personal data (names, emails, screenshots that may show customer data) falls under GDPR.
**Impact:** EU hosting, retention, export, erasure, processor contracts, careful AI providers.
**Response:** SaaS in an EU region; org-level retention (1 year / 2 years / always) and export ([FR-AUD-03](functional-requirements.md#aud--audit-and-data), [FR-AUD-04](functional-requirements.md#aud--audit-and-data)); user erasure keeps content as "Former member" ([NFR-PRIV-04](non-functional-requirements.md#priv--privacy)); cloud AI only with no-training settings; laptop mode keeps text on the device ([ADR-0027](../03-adr/0027-secrets-and-encryption.md), [ADR-0030](../03-adr/0030-deployment-model.md)).

## C-10 Self-hosting on small machines

**What:** Small organizations want to run pinround themselves on one VM (4 vCPU, 8 GB RAM).
**Impact:** We cannot require Kubernetes, Kafka or a separate search cluster.
**Response:** Docker Compose with five services (app, worker, PostgreSQL, Valkey, MinIO); FrankenPHP has the Mercure hub built in; search in PostgreSQL ([ADR-0022](../03-adr/0022-search-and-duplicates.md)); same image for SaaS ([ADR-0030](../03-adr/0030-deployment-model.md)).

## C-11 Screenshots are large and many

**What:** Up to 20 million screenshots (~6 TB) at the design point.
**Impact:** Files cannot live in the database or pass through PHP request memory.
**Response:** presigned uploads straight to private object storage, async processing with libvips, signed short-lived read URLs, lifecycle rules per retention ([ADR-0011](../03-adr/0011-screenshot-storage-and-processing.md)).

## C-12 Claude Code as a first-class client

**What:** Developers fix tickets with Claude Code and want it to read round notes and add screenshots.
**Impact:** A machine client needs the same permissions as the person, and private drafts must stay private.
**Response:** MCP server on `mcp/sdk` via the Symfony MCP bundle, personal access tokens with scopes, same voters as the UI, every call audited ([ADR-0021](../03-adr/0021-mcp-server.md), [ADR-0017](../03-adr/0017-private-draft-rounds.md)).

## C-13 The prototype is the UX contract

**What:** A clickable prototype (private claude.ai artifact) defines the screens, words and behaviour. It contains internal data and simulated parts.
**Impact:** The product must look and behave like it, but must not copy its data or its demo devices ("view as" switch, "Big team" toggle, made-up pings).
**Response:** every phase starts by studying the named prototype screens ([prototype guide](../04-prototype/prototype-guide.md)); the server-rendered UI with Live Components can mirror its interactions ([ADR-0007](../03-adr/0007-server-rendered-ui.md)); requirements win when they differ, and differences are noted.
