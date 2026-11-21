# ADR-0018: All server-side AI goes through one gateway on Symfony AI Platform, with an org AI policy

Every AI call is made in one place. That place checks the organization's AI policy before anything leaves the server.

## Index

- [Status and people](#status-and-people): status, date, deciders.
- [Context and problem statement](#context-and-problem-statement): the scene and the problem.
- [Decision drivers](#decision-drivers): cost, privacy, choice of provider, hackathon needs.
- [Considered options](#considered-options): direct SDK calls, Symfony AI behind a gateway, own HTTP client.
- [Decision outcome](#decision-outcome): the gateway, the policy fields, providers, metering, async.
  - [Consequences](#consequences): good, bad and neutral effects.
- [Confirmation](#confirmation): tests, Deptrac rule, metrics.
- [Pros and cons of the options](#pros-and-cons-of-the-options): details per option.
- [More information](#more-information): links and when to revisit.

## Status and people

- Status: Accepted
- Date: 2026-11-06
- Deciders: Zairan Mir (lead)

## Context and problem statement

Sara is the admin at Acme Foods. Her company does not want ticket text sent to a US cloud, and it has no budget for an API subscription. Lena's team at another company wants the best summaries and pays for Claude. A third company runs one small Ollama server in its office. pinround must serve all three. It must also meet the hackathon rule: use Symfony AI and Hugging Face.

pinround uses AI for: summaries ("In short"), private "Explain it to me" answers, intake suggestions (type, priority, duplicates, missing info, risk), optional embeddings for duplicates, and later AI pin suggestions on screenshots.

If every feature calls a provider directly, nobody can enforce "AI off", "leads only" or "title only". Costs and data flows also become invisible.

## Decision drivers

- One place to enforce the org AI policy: on/off, who may use it, what is sent, where it runs.
- Switch providers by configuration, not code.
- Hackathon: Symfony AI Platform and Hugging Face must be used.
- Privacy: no prompt or answer content in logs. Only metadata.
- Cost control: per-org budgets and rate limits.
- Pages must stay fast. AI must never block a page render.

## Considered options

1. **Each module calls a vendor SDK directly.**
2. **Symfony AI Platform behind a pinround `AiGateway` in the Assist module (chosen).**
3. **Own thin HTTP client per provider.**

## Decision outcome

Chosen option: **2**.

- **Gateway.** `Assist\Api\AiGateway` is the only entry point. Other modules ask for a use case ("summarize ticket", "suggest intake fields"). They never send raw prompts. Deptrac forbids any module except Assist from depending on `Symfony\AI\*`.
- **Policy** (stored per organization, set on the Admin AI page):
  - `enabled`: on or off.
  - `who`: everyone, or leads only.
  - `send`: title and text, or title only.
  - `where`:
    - `cloud`: a Symfony AI platform bridge, Hugging Face for the hackathon, Anthropic optional.
    - `server_ollama`: one Ollama host the org runs.
    - `laptop`: browser-local, see ADR-0019.
    - `laptop_then_cloud`: laptop when it runs, cloud otherwise.
  - `summarize_on_sync`: yes or no.
  - Monthly token budget.
- **Prompt templates** are versioned files in the Assist module, for example `summary.v1`. Each stored AI result records the template version, provider, model, and the user who asked.
- **Structured outputs:** intake suggestions ask for JSON. The result is validated against a schema before use (ADR-0020).
- **Async:** summaries on sync, embeddings and intake suggestions run as Messenger jobs (ADR-0009). Interactive answers ("Explain it to me") stream over Mercure (ADR-0023) or HTTP streaming. Verify Symfony AI streaming support at implementation time. The page renders first, and AI content arrives later.
- **Metering:** each call writes an `ast_usage` row with org, user, use case, provider, model, tokens in/out, duration and status. No content. The rate limiter is keyed by org and user (ADR-0010).
- **Failure:** the provider being down or the budget used up shows a plain message, for example "AI is not available right now". Features still work without AI.
- **Bridges:** Hugging Face and Ollama (`symfony/ai-ollama-platform`) exist as Symfony AI platform packages. Check exact package names and versions for Anthropic and Hugging Face at implementation time, because Symfony AI is at 1.0.0-BETA1.

### Consequences

- Good: admins get real control, and the policy is enforced in code, not by convention.
- Good: changing provider is configuration.
- Good: one place for metering, rate limits, redaction and tests.
- Bad: Symfony AI is beta, so APIs may change before 1.0. The gateway limits how much code changes.
- Bad: one more abstraction layer to learn.
- Neutral: model quality differs per provider, so prompts must be tested per provider (see Confirmation).

## Confirmation

- Deptrac rule: only `Assist` may use `Symfony\AI\*`.
- Unit tests for the policy: each policy field blocks or allows the right use cases (for example "title only" sends no description).
- Contract tests with a fake platform: the gateway sends no fields that the policy forbids.
- An evaluation set of 20 anonymized sample tickets per use case, run per provider before a provider is enabled. Intake JSON must be valid in at least 95% of runs.
- Metric: AI job failure rate under 2% per day. Queue age for AI jobs under 60 s at p95.

## Pros and cons of the options

### 1. Vendor SDKs per module

- Good, because it is quick to start.
- Bad, because the policy is not enforceable, provider lock-in spreads, and logging and redaction are inconsistent.

### 2. Symfony AI behind a gateway

- Good, because there is one interface for many providers, including Ollama and Hugging Face.
- Good, because it fits the Symfony DI and config style, and meets the hackathon rule.
- Bad, because it is beta software.

### 3. Own HTTP client per provider

- Good, because there are no beta dependencies.
- Bad, because we maintain request formats, streaming and tool calling for every provider ourselves.

## More information

- Related: [ADR-0019](0019-browser-local-ai.md), [ADR-0020](0020-ai-safety-and-prompt-injection.md), [ADR-0022](0022-search-and-duplicates.md), [ADR-0009](0009-async-messaging-and-outbox.md).
- Architecture: [ai-architecture.md](../02-architecture/ai-architecture.md).
- Revisit when Symfony AI 1.0 is stable (to remove workarounds), when one org spends more than its budget 3 months in a row, or when a needed provider has no bridge.
