# Phase 08 — AI assist

Goal: AI helps people understand and sort tickets, through Symfony AI on the server or Ollama on each laptop, always under the organization's policy, and never acting on its own.

## Index
- [Before you start](#before-you-start) — prototype screens, requirements, ADRs and docs to read first
- [Outcome](#outcome) — what people can do when this phase is done
- [Scope](#scope) — what this phase builds
- [Out of scope](#out-of-scope) — what waits
- [Requirements covered](#requirements-covered) — FR and NFR IDs
- [Design notes](#design-notes) — modules, tables, endpoints, safety and speed notes
- [Tasks](#tasks) — P08.T1 to P08.T11, one session each
- [Definition of done](#definition-of-done) — phase checklist
- [Hand-off notes](#hand-off-notes) — what phase 09 must know

## Before you start
1. **Open the prototype.** Link: https://claude.ai/artifact/FmbJosB3s5mDi75cLqMFQ2 (private to its owner). In Claude Code use the Artifact tool with `action: "read"`, `url` = the link and `path: "project/Main.dc.html"` (ticket page, Report a problem, Admin › AI, header Local AI chip) and `path: "project/Review.dc.html"` (dashed AI suggestion pins). In the prototype: open a ticket and look at "The ask" (In short, Show original text, Summarize with AI, Explain it to me, Share, Use as main summary); open Report a problem and press "Check with AI"; open Admin › AI and set "Where the AI runs" to a laptop option to see the Local AI chip and sheet.
2. **If you cannot open the link**, read: [ticket-page.md › The ask](../04-prototype/screens/ticket-page.md#the-ask), [report-a-problem.md › Duplicate suggestions](../04-prototype/screens/report-a-problem.md#duplicate-suggestions), [report-a-problem.md › Where it goes](../04-prototype/screens/report-a-problem.md#where-it-goes), [admin.md › AI](../04-prototype/screens/admin.md#ai), [dialogs-and-popovers.md › Local AI sheet](../04-prototype/screens/dialogs-and-popovers.md#local-ai-sheet), [review-round.md › Screenshots and pins](../04-prototype/screens/review-round.md#screenshots-and-pins).
3. **Read the requirements:** all of [AI](../01-requirements/functional-requirements.md#ai--ai-assist) (FR-AI-01, FR-AI-02, FR-AI-03, FR-AI-04, FR-AI-05, FR-AI-06, FR-AI-07, FR-AI-08, FR-AI-09, FR-AI-10, FR-AI-11, FR-AI-12, FR-AI-13, FR-AI-14, FR-AI-15, FR-AI-16, FR-AI-17, FR-AI-18), FR-ADM-05 in [ADM](../01-requirements/functional-requirements.md#adm--admin), FR-INT-03 and FR-INT-15 in [INT](../01-requirements/functional-requirements.md#int--intake-and-checks), FR-RND-06 and FR-RND-07 in [RND](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds), [SEC](../01-requirements/non-functional-requirements.md#sec--security) (NFR-SEC-09, NFR-SEC-12), [PRIV](../01-requirements/non-functional-requirements.md#priv--privacy) (NFR-PRIV-06), constraints C-03, C-07, C-08 in [constraints.md](../01-requirements/constraints.md).
4. **Read the decisions and design:** [ADR-0018 provider abstraction](../03-adr/0018-ai-provider-abstraction.md), [ADR-0019 browser-local AI](../03-adr/0019-browser-local-ai.md), [ADR-0020 AI safety](../03-adr/0020-ai-safety-and-prompt-injection.md), [ADR-0022 search and duplicates](../03-adr/0022-search-and-duplicates.md), [ADR-0027 secrets](../03-adr/0027-secrets-and-encryption.md), [ADR-0009 async](../03-adr/0009-async-messaging-and-outbox.md); the whole [ai-architecture.md](../02-architecture/ai-architecture.md) (start with [Org AI policy](../02-architecture/ai-architecture.md#org-ai-policy) and [Laptop mode protocol](../02-architecture/ai-architecture.md#laptop-mode-protocol)), [security.md › AI-specific threats](../02-architecture/security.md#ai-specific-threats), [security.md › Browser hardening and CSP](../02-architecture/security.md#browser-hardening-and-csp), [modules.md › Assist](../02-architecture/modules.md#assist-ast_), [data-model.md › Notification, Assist, Integration, Audit, AgentApi](../02-architecture/data-model.md#notification-assist-integration-audit-agentapi).
5. **Check the previous phase:** [phase 07 Definition of done](phase-07-home-and-my-reports.md#definition-of-done). For the 🏁 hackathon slice you only need phases 00, 01, 02 (core steps), 03 (ticket page) and 05 (rounds) done; skip what depends on 06/07 and note it.

## Outcome
Acme Foods cannot pay for an AI service. Sara sets "Where the AI runs: Each person's laptop". Omar installs Ollama and pulls `qwen3:4b`. He presses the "Local AI" chip, then "Check again"; it says "Local AI: ready". He opens a long ticket and presses "Summarize with AI". The summary is made on his laptop and shows "posted by Omar using local model qwen3:4b (unverified)"; Lena can make it the main summary with one click. Another org uses Hugging Face in the cloud: tickets synced from Jira get an "In short" summary in the background, and Mia's "Explain it to me" answer is private until she shares it.

## Scope
- `AiGateway` on Symfony AI Platform with providers Hugging Face (🏁 default), Anthropic, and a server-side Ollama box (self-host: org admin setting; SaaS: operator setting only, never a tenant-entered URL).
- Org AI policy and its admin tab.
- Summaries: on demand, on sync, history, "Use as main summary".
- Explain it to me (private, streamed), Share, Clear.
- Intake suggestions JSON (type, priority, duplicates, missing, risky) on Report a problem.
- AI duplicates with optional embeddings; risk reason from AI.
- Laptop mode: status chip, setup sheet, prompt package and result endpoints.
- Usage metering, limits and budget alerts; labels everywhere.
- Optional: AI check per pin ("Looks fixed") and AI suggestion pins (vision).

## Out of scope
- AI re-ranking of the Home top 10: **deferred, not planned in v1.** The brief allows it later as a Could (only inside the top 10 and only when the org allows AI); `Workflow\Api\NeedsYouScore` stays the only ranking. Revisit after launch.
- MCP tools (phase 09) — they are not "pinround's AI", they are the user's own agent.

## Requirements covered
FR-AI-01, FR-AI-02, FR-AI-03, FR-AI-04, FR-AI-05, FR-AI-06, FR-AI-07, FR-AI-08, FR-AI-09, FR-AI-10, FR-AI-11, FR-AI-12, FR-AI-13, FR-AI-14, FR-AI-15, FR-AI-16, FR-AI-17, FR-AI-18, FR-ADM-05, FR-INT-03 (AI part), FR-INT-08 (AI risk/missing reasons), FR-INT-15 (AI layer), FR-RND-06, FR-RND-07, FR-AUD-01 (AI policy changes).
NFR-SEC-09, NFR-SEC-12, NFR-SEC-13, NFR-PRIV-02, NFR-PRIV-06, NFR-PERF-06, NFR-PERF-09, NFR-AVL-05.

## Design notes
- **Module:** Assist (`App\Assist`). Public Api: `AiPolicy`, `Summaries`, `Explanations`, `IntakeAssist`, `LocalAi` (see modules.md). Internal: `Application\AiGateway` (interface from ai-architecture.md), `Infrastructure\Platform\SymfonyAiGateway` (the only class that touches Symfony AI), `Infrastructure\Prompt\templates\{feature}.v{n}.md.twig` + `{feature}.v{n}.schema.json`.
- **Tables:** `ast_explanation`, `ast_suggestion`, `ast_embedding` (optional, pgvector), `ast_usage` (partitioned monthly, no content), `ast_local_package`. Summaries are stored by Ticketing (`tkt_summary` with `source`, `model`, `prompt_version`, `is_current`) when `SummaryGenerated` arrives. Policy is an org setting owned by Organization (`org_settings.data` key `ai`), provider credentials encrypted (`secret_enc`, `key_id`).
- **Laptop endpoints (binding names):** `POST /ai/laptop/status`, `POST /ai/laptop/package` (single use, expires in 5 minutes), `POST /ai/laptop/result`.
- **Limits:** 30 AI calls per user per hour; monthly org budget; admin notice at 80%; cloud stops at 100% (laptop still works). Message: "Too many AI requests. Try again in a minute."
- **Sizes (FR-AI-18):** summary 2–4 sentences, ≤ 60 words; explanation ≤ 160 words; ≤ 7,000 characters of ticket text sent; without a summary the raw text is cut at 700 characters with "Show the full ask".
- **Safety:** ticket text inside delimiters with "data, not instructions"; JSON schema validation with one retry; HTML-escape every output; label every output (AI chip / ✦ / dashed pins); AI never writes by itself; restricted (team-only) tickets never go to cloud AI unless the admin opts in; "title only" policy removes the description; prompts and answers never in logs (only `ast_usage` metadata).
- **Speed:** every server AI call runs on the Messenger `ai` transport or streams via Mercure; no web request waits on a model. Per-org concurrency cap on the `ai` transport. Content-hash cache for summaries.
- **CSP:** `connect-src http://localhost:11434` only for orgs whose policy allows laptop mode.
- **Symfony AI is beta** (1.0.0-BETA1 was the current release on 2026-11-06; check again at implementation): verify bridge package names, config keys and the Hugging Face and Ollama bridge APIs; keep all Symfony AI usage inside `SymfonyAiGateway`.
- **Restricted data and cloud AI (binding):** before any cloud AI call on a draft report, run `CheckReasons::isSecuritySensitive` (P06.T3); a match makes the draft restricted, so no cloud AI (laptop AI is still allowed). Tickets with `visibility = 'team'` are excluded from cloud AI features, from AI duplicate candidate lists and from embeddings unless the admin opted in (`ai.restricted_to_cloud`).
- **Laptop results are user content (binding):** `model` must match `[a-z0-9:._-]{1,64}`; the label is "posted by <first name> using local model <model> (unverified)"; a laptop result never becomes the current summary by itself: it is shown as the viewer's own result, and "Use as main summary" is a separate click.

## Tasks

### P08.T1 — AiGateway, providers and a fake provider 🏁
- **Why:** one door for all server-side AI, so policy, safety and metering cannot be skipped.
- **Steps:**
  1. Install Symfony AI (Platform + AI Bundle) and the Hugging Face and Ollama bridges; verify package names and config in the docs.
  2. Write `AiTask` (feature, ticketId, userInput), `AiResult` (text or data, model, provider, promptVersion, label), `AiGateway::run(AiTask, Viewer)`.
  3. `SymfonyAiGateway`: choose provider from org settings (Hugging Face default, Anthropic optional); call Platform; map errors to `AiUnavailable`, `AiRateLimited`.
  4. Server-side Ollama provider: base URL from env on SaaS (operator setting); on self-host the admin may set it, through the outbound URL guard from [security.md](../02-architecture/security.md) (https or an explicitly allowed internal host, no redirects).
  5. A fake provider for tests (deterministic responses), selected by env in the test kernel.
- **Files:** `src/Assist/Application/AiGateway.php`, `src/Assist/Infrastructure/Platform/SymfonyAiGateway.php`, `src/Assist/Infrastructure/Platform/FakeProvider.php`, `config/packages/ai.yaml`.
- **Acceptance:**
  - [ ] Only `SymfonyAiGateway` imports Symfony AI classes (Deptrac or PHPStan rule).
  - [ ] Provider credentials are stored encrypted with a key id.
  - [ ] With the fake provider, a gateway call returns the fixed text and the provider name.
- **Tests:** unit (provider choice), integration with the fake provider, Deptrac rule test.
- **Prototype check:** none (backend task).
- **Size:** M

### P08.T2 — Prompt builder, output validator and metering 🏁
- **Why:** every prompt must treat ticket text as data, every answer must be checked, and every call must be counted without content ([ADR-0020](../03-adr/0020-ai-safety-and-prompt-injection.md)).
- **Steps:**
  1. `PromptBuilder`: render Twig template, insert ticket data only through a helper that wraps it in `<ticket>…</ticket>` with the "data, not instructions" line; apply "title only"; cut ticket text at 7,000 characters (FR-AI-18).
  2. `OutputValidator`: JSON schema validation (choose a maintained JSON schema library), one retry with "return only valid JSON"; HTML-escape on output.
  3. `Metering`: write `ast_usage` (provider, model, tokens, ms; no content); RateLimiter per user (30/h) and per org budget check.
  4. Migration `ast_usage` (partitioned monthly, RLS on parent and partitions).
- **Files:** `src/Assist/Infrastructure/Prompt/PromptBuilder.php`, `src/Assist/Infrastructure/Prompt/OutputValidator.php`, `src/Assist/Application/Metering.php`, migration for `ast_usage`.
- **Acceptance:**
  - [ ] Every call writes one `ast_usage` row and nothing with content.
  - [ ] A ticket text with "ignore previous instructions" stays inside the delimiters (unit test on the rendered prompt).
- **Tests:** unit (delimiters, title-only filter, 7,000-character cut, validator retry), integration (usage row, RLS on `ast_usage`), log-leak marker test for an AI call.
- **Prototype check:** none (backend task).
- **Size:** M

### P08.T3 — Org AI policy and Admin › AI tab 🏁 (minimal: on/off, where, provider)
- **Why:** the admin decides if, who, what and where.
- **Steps:**
  1. `AiPolicy::for(UserId, OrgId): PolicyView` with on/off, who (everyone/leads only), what is sent (title+text/title only), summarize on sync, where (cloud/laptop/laptop-then-cloud), restricted-to-cloud opt-in, provider + credentials.
  2. `PolicyEvaluator` implements the decision flow in [Org AI policy](../02-architecture/ai-architecture.md#org-ai-policy); returns allow or a reason code with a plain sentence ("Your admin turned AI help off.", "Your admin allows AI help for leads only.").
  3. Admin › AI tab exactly as the prototype: switches, chips, the note under "Where the AI runs", "Model for laptops" card, footnote.
  4. Every change writes an audit event (old/new value).
- **Files:** `src/Assist/Application/Policy/PolicyEvaluator.php`, `src/Organization/UI/Admin/AiSettingsController.php`, `templates/admin/ai.html.twig`.
- **Acceptance:**
  - [ ] When AI is not allowed for the viewer, AI buttons are hidden and one line says why.
  - [ ] Only admins can change the policy (voter `ADMIN_AI`), MFA re-check for credential changes. The page asks `ADMIN_AREA`, which needs two-step login and a code of the last hour (P01.T6); mark the credential actions `RequiresRecentAuth`.
- **Tests:** table test for the policy matrix (on/off × who × where × restricted × laptop ready); voter test; functional tab test.
- **Prototype check:** [admin.md › AI](../04-prototype/screens/admin.md#ai) texts and notes.
- **Size:** M

### P08.T4 — Summaries: on demand, history, main summary 🏁 (on demand only)
- **Why:** "In short" makes long tickets readable.
- **Steps:**
  1. Template `summary.v1.md.twig`: 2–4 sentences, ≤ 60 words, only facts in the ticket, plain text.
  2. `Summaries::request(TicketId, UserId)` dispatches an `ai` job; result → `SummaryGenerated` → Ticketing stores `tkt_summary` (old current → history).
  3. Ticket page "The ask": "In short" with AI chip and source note ("written just now at Omar's request"), "Show original text", raw text cut at 700 chars + "Show the full ask" when no summary, "Summarize with AI" with a busy state, "Show N earlier summaries".
  4. "Use as main summary" on a shared explanation (lead or reporter): old summary to history; toast "Now the main summary. The old one is kept in history."
  5. Content-hash cache (template version + text + policy).
- **Files:** `src/Assist/Infrastructure/Prompt/templates/summary.v1.md.twig`, `src/Assist/Infrastructure/Messenger/GenerateSummaryHandler.php`, `src/Ticketing/Infrastructure/Messenger/StoreSummaryHandler.php`, `templates/ticket/_the_ask.html.twig`.
- **Acceptance:**
  - [ ] Summary saved for everyone; history keeps every replaced summary.
  - [ ] Result pushed by Mercure refresh signal; no content in the Mercure message. 🏁 Hackathon: Mercure (P04.T7) is cut, so the ticket page polls the summary frame every 3 s while a job runs; accept "summary appears without a manual reload".
  - [ ] Over-long or invalid output is rejected with "The AI answer failed. Try again."
- **Tests:** unit (word limit check), integration (history), functional (button hidden when not allowed), golden tests (mocked) for parsing.
- **Prototype check:** [ticket-page.md › The ask](../04-prototype/screens/ticket-page.md#the-ask).
- **Size:** M

### P08.T5 — Summaries on sync
- **Why:** imported tickets should already be readable.
- **Steps:**
  1. Subscribe to `TicketCreated`/ticket text changed events with origin `jira`; when the policy's "summarize on sync" is on and where = cloud (or server Ollama), dispatch a low-priority `ai` job.
  2. Source note "written from the Jira text when the ticket was synced".
  3. Skip restricted tickets unless the admin opted in.
- **Files:** `src/Assist/Infrastructure/Messenger/SummarizeOnSyncHandler.php`.
- **Acceptance:**
  - [ ] While 1,000 summary-on-sync jobs run, the oldest message on the `async_notify` transport stays under 5 s (separate transport, concurrency cap; measured in an integration test with the fake provider).
- **Tests:** integration with fake provider; queue isolation test.
- **Prototype check:** Admin › AI switch text.
- **Size:** S

### P08.T6 — Explain it to me (private) and Share
- **Why:** each person can understand a ticket at their own level without changing it for others.
- **Steps:**
  1. "Explain it to me" box with chips ("Explain it in simpler words", "What does "done" look like?", "Explain the product terms", "Why does this matter to users?") and free text.
  2. Stream the answer to the viewer only (per-user Mercure topic or streamed response), ≤ 160 words, "Stop" button (cancels the job).
  3. Store `ast_explanation(user_id, shared_at NULL)`; voter: owner only until shared.
  4. "Share" sets `shared_at`; shown under the summary as "explanation by Omar"; "Clear" deletes.
- **Files:** `src/Assist/UI/Component/ExplainBox.php`, `src/Assist/UI/Voter/ExplanationVoter.php`, template `explain.v1.md.twig`.
- **Acceptance:**
  - [ ] Another user (even admin) cannot read a private explanation (HTTP and repository).
  - [ ] Stream stops within 1 s of "Stop".
- **Tests:** voter test; isolation test; functional share/clear; e2e explain → share → use as main summary.
- **Prototype check:** ticket page Explain it to me flow.
- **Size:** M

### P08.T7 — Intake suggestions, AI duplicates and risk 🏁 (suggestions without embeddings)
- **Why:** help reporters choose type and priority and spot duplicates.
- **Steps:**
  1. Template `intake.v1.md.twig` + `intake.v1.schema.json` (schema in ai-architecture.md).
  2. "Check with AI" on Report a problem (🏁 hackathon: on the simple New ticket form from P05.T5, because P06 is cut; for the same reason this task creates the security word list `Ticketing\Domain\Intake\WordLists` and `CheckReasons::isSecuritySensitive`, which P06.T3 later extends): first call `CheckReasons::isSecuritySensitive`; if it matches, hide "Check with AI" for cloud providers and say "This report mentions security, so it stays out of cloud AI." Otherwise send report text, pin notes and the top 80 open ticket keys + titles the reporter may see, **excluding `visibility = 'team'` tickets** unless the admin opted in (titles only); unknown keys dropped.
  3. Fill ✦ markers for type and priority; AI duplicates replace word-match suggestions with header "The AI thinks this may already be reported"; `missing: true` adds reason `info`; `risky: true` adds reason `risk` (never visibility).
  4. Optional embeddings (not 🏁): when the org enables semantic duplicates, store `ast_embedding` (title + summary) through Symfony AI Store with pgvector under RLS; **never for `visibility = 'team'` tickets** unless the admin opted in; candidates = top 20 by similarity, then the FR-INT-15 word rule, then top 3. Laptop-only orgs skip this. Verify the pgvector Store bridge first; if missing, write a small Doctrine adapter. Add an `EXPLAIN` test as `pinround_app` (pgvector operators are not leakproof; use the `SECURITY DEFINER` search function from data-model.md if the index is skipped).
- **Files:** `src/Assist/Application/IntakeAssistService.php`, `src/Assist/Infrastructure/Store/*`, `templates/report/_ai_check.html.twig`.
- **Acceptance:**
  - [ ] Invalid JSON → "The AI answer failed. Try again." and the form still works.
  - [ ] Candidate list never contains `visibility = 'team'` tickets (unless opted in) and never tickets the reporter cannot see (cases added to the visibility leak suite).
  - [ ] A draft with a security word never produces a cloud AI call (spy on the gateway).
  - [ ] AI never sets visibility.
- **Tests:** golden set (mocked) for parsing; injection set ("ignore previous instructions…") still matches schema; integration for embeddings isolation.
- **Prototype check:** Report a problem → "Check with AI" (✦, header text, preview sentence).
- **Size:** L

### P08.T8 — Laptop AI: status chip and setup sheet 🏁 (stretch)
- **Why:** orgs without an AI budget still get AI.
- **Steps:**
  1. Header chip "Local AI: not checked / checking… / ready / not found" when policy uses laptops (dot colors from the prototype).
  2. Sheet "AI on your laptop" with fallback sentence, status line, model chips, the four setup steps, Close / Check again.
  3. Stimulus `local_ai_controller.js`: on click only, `GET http://localhost:11434/api/tags` with 3 s timeout; pick `qwen3*`, else `phi4*`/`gemma*`, else first; remember choice in localStorage; `POST /ai/laptop/status {ready, model}`.
  4. CSP `connect-src` includes `http://localhost:11434` only for laptop-mode orgs.
- **Files:** `assets/controllers/local_ai_controller.js`, `templates/ai/_local_ai_sheet.html.twig`, `src/Assist/UI/Controller/LaptopController.php` (status).
- **Acceptance:**
  - [ ] No request to localhost on page load (e2e asserts no such request).
  - [ ] Works when Ollama is missing (state "not found", no console errors that break the page).
- **Tests:** e2e with a stubbed Ollama (Playwright route mock); CSP header test per policy.
- **Prototype check:** [Local AI sheet](../04-prototype/screens/dialogs-and-popovers.md#local-ai-sheet).
- **Size:** M

### P08.T9 — Laptop AI: prompt package and result intake 🏁 (stretch)
- **Why:** run AI features on the laptop while the server keeps control.
- **Steps:**
  1. `POST /ai/laptop/package {feature, ticketId}`: policy + voter check, build the prompt from the same template, store `ast_local_package` (single use, expires in 5 minutes), return `{packageId, prompt, schema, expiresAt}`.
  2. Browser calls `POST http://localhost:11434/api/generate` with `stream:false`, `think:false`, `format:"json"` for JSON features; strips `<think>…</think>`.
  3. `POST /ai/laptop/result {packageId, model, text}`: package (found through its resolver function, P00.T4) must exist, belong to the user, be unused and not expired; `model` must match `[a-z0-9:._-]{1,64}`; validate size and schema; store as user-provided content with the label "posted by <first name> using local model <model> (unverified)". It never becomes the current summary by itself.
  4. Wire summary, explain and intake features to choose laptop or server by the policy flow (laptop-then-cloud falls back).
- **Files:** `src/Assist/Application/LocalAiService.php`, `src/Assist/UI/Controller/LaptopController.php`, migration `ast_local_package`.
- **Acceptance:**
  - [ ] Reused or expired package id → 410 and nothing stored.
  - [ ] Result HTML-escaped and length-limited like a comment.
  - [ ] "title only" policy also applies to the laptop package.
  - [ ] A `model` value like `qwen3:4b<script>` is refused with 422.
  - [ ] After a laptop summary, the main summary is unchanged until someone presses "Use as main summary".
- **Tests:** functional (package lifecycle), voter (other user's package), e2e with stubbed Ollama for summary.
- **Prototype check:** summary label and fallback behaviour.
- **Size:** M

### P08.T10 — Usage limits, budgets and labels
- **Why:** keep cost under control and make AI visible.
- **Steps:**
  1. RateLimiter 30/user/hour (configurable); monthly org budget (count, tokens, cost estimate); notice to admins at 80% (Admin card alert + email); stop cloud at 100%.
  2. Monthly reset job (Scheduler) with usage summary to admins.
  3. Check every AI surface has its label: AI chip "AI SUMMARY", ✦ on suggestions, "AI ORDER", dashed AI pins, model label on laptop results.
- **Files:** `src/Assist/Application/Budget/*`, `src/Assist/Infrastructure/Scheduler/ResetAiBudget.php`.
- **Acceptance:**
  - [ ] At 100% budget, cloud features show "Too many AI requests. Try again in a minute." or a budget sentence; laptop features keep working.
- **Tests:** unit (budget math), integration (limiter), functional (alerts).
- **Prototype check:** labels on ticket page and Report a problem.
- **Size:** S

### P08.T11 — Review AI helpers (optional): AI check per pin and AI suggestion pins
- **Why:** help testers spot fixed and new visual problems. Could-have; do only if time allows.
- **Steps:**
  1. "Looks fixed" hint per earlier pin: vision model compares before/after crop; advice only.
  2. "Look for visual problems": vision model returns `{x_pct, y_pct, note}` candidates (schema `pins.v1.schema.json`); shown as dashed AI pins with Keep / Dismiss; Keep creates a normal pin with `source = ai`.
  3. Cloud only (small laptop models are weak at vision); hidden for laptop-only orgs.
- **Files:** `src/Assist/Infrastructure/Prompt/templates/pins.v1.md.twig`, `src/Review/UI/Component/AiPinLayer.php`.
- **Acceptance:**
  - [ ] AI pins never change verdicts without a click; Keep/Dismiss audited in ticket activity.
- **Tests:** schema parsing (mocked); voter (only people who can review).
- **Prototype check:** [review-round.md › Screenshots and pins](../04-prototype/screens/review-round.md#screenshots-and-pins) and [full-screen-review.md](../04-prototype/screens/full-screen-review.md).
- **Size:** L

## Definition of done
- [ ] All non-optional P08 tasks ticked; 🏁 tasks demoable with Hugging Face (and with a laptop Ollama if the stretch tasks were done).
- [ ] Restricted-data rules proven: no cloud AI call for security drafts or team-only tickets without opt-in (gateway spy tests).
- [ ] Policy matrix table test, prompt-injection golden set (mocked in CI), log-leak marker test and AI isolation tests green.
- [ ] No web request waits on a model; `ai` queue isolated from `notify`.
- [ ] `bin/console assist:eval --feature=intake` runs against the golden set (offline, not in CI).
- [ ] Quality gates green; docs and Index updated.

## Hand-off notes
- Phase 09 (MCP) must not route through `AiGateway`: MCP callers are the user's own agent, so the "what is sent" policy does not apply, but voters and scopes do.
- Record the chosen Hugging Face models and measured laptop seconds per summary here.
