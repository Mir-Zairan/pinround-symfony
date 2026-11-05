# Phase 09 — MCP server and Claude Code

Goal: a developer can tell Claude Code "read my pinround notes for SHOP-88 and fix what is marked Not right yet", and Claude Code can read the notes, add screenshots and set verdicts in the developer's own draft round, with exactly the developer's permissions.

## Index
- [Before you start](#before-you-start) — prototype screens, requirements, ADRs and docs to read first
- [Outcome](#outcome) — what people can do when this phase is done
- [Scope](#scope) — what this phase builds
- [Out of scope](#out-of-scope) — what waits
- [Requirements covered](#requirements-covered) — FR and NFR IDs
- [Design notes](#design-notes) — module, tokens, tools, security and speed notes
- [Tasks](#tasks) — P09.T1 to P09.T8, one session each
- [Definition of done](#definition-of-done) — phase checklist
- [Hand-off notes](#hand-off-notes) — what phase 10 must know

## Before you start
1. **Open the prototype.** Link: https://claude.ai/artifact/FmbJosB3s5mDi75cLqMFQ2 (private to its owner). In Claude Code use the Artifact tool with `action: "read"`, `url` = the link and `path: "project/Main.dc.html"`. In the prototype, view as Developer, open a ticket assigned to you, start a **draft round**, add a screenshot, drop pins, set "Looks right" / "Not right yet", and read the **For Claude Code** box. Then press a verb that moves the ticket to see the **Share your draft round?** dialog.
2. **If you cannot open the link**, read: [ticket-page.md › Rounds](../04-prototype/screens/ticket-page.md#rounds), [dialogs-and-popovers.md › Share draft dialog](../04-prototype/screens/dialogs-and-popovers.md#share-draft-dialog), [review-round.md](../04-prototype/screens/review-round.md).
3. **Read the requirements:** all of [MCP](../01-requirements/functional-requirements.md#mcp--mcp-server-and-agent-api) (FR-MCP-01, FR-MCP-02, FR-MCP-03, FR-MCP-04, FR-MCP-05, FR-MCP-06, FR-MCP-07, FR-MCP-08, FR-MCP-09, FR-MCP-10, FR-MCP-11), FR-ID-05 in [ID](../01-requirements/functional-requirements.md#id--identity-and-access), FR-RND-09, FR-RND-10, FR-RND-11, FR-RND-12, FR-RND-13 in [RND](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds), FR-AUD-01 in [AUD](../01-requirements/functional-requirements.md#aud--audit-and-data), [SEC](../01-requirements/non-functional-requirements.md#sec--security) (NFR-SEC-01, NFR-SEC-03, NFR-SEC-04, NFR-SEC-10, NFR-SEC-14), constraint C-12 in [constraints.md](../01-requirements/constraints.md).
4. **Read the decisions and design:** [ADR-0021 MCP server](../03-adr/0021-mcp-server.md), [ADR-0017 private draft rounds](../03-adr/0017-private-draft-rounds.md), [ADR-0012 authentication](../03-adr/0012-authentication.md), [ADR-0013 authorization](../03-adr/0013-authorization.md), [ADR-0011 screenshot storage](../03-adr/0011-screenshot-storage-and-processing.md), [ADR-0026 audit log](../03-adr/0026-audit-log.md); [modules.md › AgentApi](../02-architecture/modules.md#agentapi-api_), [security.md › MCP and personal access token threats](../02-architecture/security.md#mcp-and-personal-access-token-threats), [integrations.md › Claude Code through MCP](../02-architecture/integrations.md#claude-code-through-mcp), [media-pipeline.md › Upload flow](../02-architecture/media-pipeline.md#upload-flow), [testing-strategy.md › Special suites](../02-architecture/testing-strategy.md#special-suites).
5. **Check the previous phase:** [phase 08 Definition of done](phase-08-ai-assist.md#definition-of-done). For the 🏁 hackathon slice you need phase 05 draft rounds (P05) and the media pipeline working; phase 08 is not required for MCP.

## Outcome
Omar creates a token called "Claude Code on my laptop" on his tokens page. The page shows it once and gives him a line to paste: `claude mcp add --transport http pinround https://<host>/mcp --header "Authorization: Bearer pr_…"`. In Claude Code he says "Read my pinround notes for SHOP-88 and fix what is marked Not right yet." Claude Code calls `get_round_notes`, fixes the code, takes a screenshot, calls `add_screenshot` and `set_verdict`. Omar refreshes the ticket and sees the new screenshot in his private draft round. Nobody else sees it until he shares it. Sara, the admin, sees each tool call in the activity log, without content.

## Scope
- Personal access tokens: create, show once, list, revoke, expiry, scopes, last used; admin revoke.
- MCP server at `/mcp` (Streamable HTTP) with tools `list_my_tickets`, `get_ticket`, `get_round_notes`, `create_upload`, `add_screenshot`, `set_verdict`, `add_comment`, `move_ticket`.
- Token auth, scope checks, Origin/Host checks, rate limits, audit of every call.
- REST `/api/v1` for the browser capture plugin (phase 10 uses it).
- Setup docs for Claude Code.

## Out of scope
- The browser extension itself (phase 10).
- OAuth for MCP clients (later; tokens are enough now).

## Requirements covered
FR-MCP-01, FR-MCP-02, FR-MCP-03, FR-MCP-04, FR-MCP-05, FR-MCP-06, FR-MCP-07, FR-MCP-08, FR-MCP-09, FR-MCP-10, FR-MCP-11, FR-ID-05, FR-RND-09, FR-RND-10, FR-RND-11 (MCP side of the hand-off rule), FR-RND-13, FR-AUD-01 (token and MCP events).
NFR-SEC-01, NFR-SEC-03, NFR-SEC-04, NFR-SEC-09, NFR-SEC-10, NFR-SEC-14, NFR-PERF-06, NFR-OPS-01.

## Design notes
- **Modules.** Identity owns tokens (`idn_api_token`: id, organization_id, user_id, name, token_hash SHA-256, prefix, scopes `text[]`, expires_at, last_used_at, revoked_at). AgentApi (`App\AgentApi`) owns the MCP server and REST adapters and `api_idempotency_key`. Tools are thin adapters calling other modules' `Api` (Ticketing, Review, Conversation, Media, Insight) after the same voter check as the web UI.
- **Token rules (binding):** prefix `pr_`; random 32 bytes; stored as SHA-256 hash (with a server pepper, see ADR-0021); shown once; default expiry 90 days, maximum 1 year; Claude Code tokens default to scopes `read` + `rounds:write`. Creating a token needs a recent re-authentication with MFA (within 10 minutes, through `RecentAuth` from P01.T6; before P01.T6 exists, the dev-only hackathon flag from P01.T4 applies). A token can never open admin areas: every `ADMIN_*` voter denies token-authenticated actors.
- **Scopes:** `read`, `rounds:write`, `comments:write`, `tickets:move`. Missing scope → MCP error, never a partial action.
- **Library:** `symfony/mcp-bundle` on the official `mcp/sdk`. Verify the bundle's tool registration (attributes or services), the Streamable HTTP transport config and session handling at implementation time.
- **Security:** Bearer token only (no cookies) on `/mcp` and `/api/v1`; validate `Origin` when present and `Host` against the allow list (DNS rebinding); JSON schema on every tool input; ids must belong to the token's org (else "not found"); size limits on text and images; rate limit per token (60/min, 600/h) and per org; 429 with `Retry-After`; audit each call (token id, tool, target id, result; no content).
- **Draft privacy:** `get_round_notes` returns the caller's own draft round and shared rounds only. A draft of another user is "not found".
- **Images:** MCP requests stay small (1 MB body limit, [ADR-0021](../03-adr/0021-mcp-server.md)), so images never travel inside a tool call. `create_upload` returns a presigned PUT URL and an `upload_id`; Claude Code uploads the file with `curl -T`; `add_screenshot` takes the `upload_id` only. This keeps one upload path (presigned PUT + processing worker) for the web, the extension and MCP. `create_upload` is a small helper tool next to FR-MCP-05.
- **Speed:** each tool ≤ 300 ms p95; image processing happens in the media worker, not in the tool call.

## Tasks

### P09.T1 — Personal access tokens 🏁
- **Why:** Claude Code and the browser plugin need a safe, revocable key.
- **Steps:**
  1. Domain `ApiToken` with `issue(name, scopes, ttl)` returning the plain token once; hash with SHA-256 + pepper; prefix `pr_` + 8 display chars.
  2. `TokenAuthenticator` (Symfony custom authenticator) for `/mcp` and `/api/v1`: look up by hash through the token resolver function (P00.T4; no `BYPASSRLS`), check expiry/revocation, set user + `TenantContext`, mark the actor as token-authenticated, update `last_used_at` (throttled, at most once per minute).
  3. Page `GET/POST /me/tokens`: create (name, scopes checkboxes with plain explanations, expiry select 30/90/365 days; requires re-authentication with MFA within the last 10 minutes), list (name, prefix, scopes, created, last used, expires), revoke.
  4. After create: show token once with copy button and the `claude mcp add …` line.
  5. Admin can revoke any token in the org (Admin › People row action); daily job warns 7 days before expiry.
  6. Audit `ApiTokenIssued`, `ApiTokenRevoked`.
- **Files:** `src/Identity/Domain/ApiToken.php`, `src/Identity/Infrastructure/Security/TokenAuthenticator.php`, `src/Identity/UI/Controller/TokenController.php`, `templates/identity/tokens.html.twig`.
- **Acceptance:**
  - [ ] Plain token never stored or logged; a test searches logs for the token.
  - [ ] Revoked or expired token → 401 at once.
  - [ ] A token can never do more than its user in the UI, and every `ADMIN_*` voter denies it (test with an admin's token on each admin route).
  - [ ] Creating a token without a recent MFA re-check redirects to "Confirm it's you".
- **Tests:** unit (hashing, expiry), functional (create/list/revoke), log-leak test, isolation (token of org A on org B ids).
- **Prototype check:** none in prototype; follow [ux-principles.md](../00-product/ux-principles.md) (one primary button, plain words).
- **Size:** M

### P09.T2 — MCP server skeleton with auth, limits and audit 🏁
- **Why:** one safe endpoint before any tool.
- **Steps:**
  1. Install and configure `symfony/mcp-bundle` (verify config); endpoint `POST /mcp` (Streamable HTTP).
  2. Firewall `mcp` stateless with `TokenAuthenticator`.
  3. Middleware/listener: Origin and Host checks, per-token and per-org RateLimiter, scope check helper `requireScope('rounds:write')`.
  4. `ToolCallAuditor`: after each call write `aud_event` (actor_kind `token`, token id, tool, target id, outcome).
  5. A trivial `ping` or `whoami` tool for smoke tests (remove or keep as `whoami` with scope `read`).
- **Files:** `src/AgentApi/Infrastructure/Mcp/*`, `config/packages/mcp.yaml`, `config/packages/security.yaml`.
- **Acceptance:**
  - [ ] Foreign `Origin` → 403; unknown Host → 400.
  - [ ] 61st call in a minute → 429 with `Retry-After`.
- **Tests:** functional (auth, origin, limits), audit row written per call.
- **Prototype check:** none.
- **Size:** M

### P09.T3 — Read tools: list_my_tickets, get_ticket 🏁 (list_my_tickets)
- **Why:** Claude Code needs to know what to work on.
- **Steps:**
  1. `list_my_tickets`: tickets where it is the caller's turn or they are assignee: key, title, step, Now line (from Insight + Workflow Api), filtered by the TicketVisibility predicate (`Ticketing\Api\TicketVisibility`); max 50; scope `read`.
  2. `get_ticket {key}`: key, title, description, step, Now/Next, people, open questions, checklist; only what the caller can see in the UI; scope `read`.
  3. JSON schemas for input and output; clear tool descriptions written for an AI reader (what it returns, when to use it).
- **Files:** `src/AgentApi/Application/Tool/ListMyTickets.php`, `src/AgentApi/Application/Tool/GetTicket.php`.
- **Acceptance:**
  - [ ] Restricted ticket of another team → "not found" (never "forbidden", to avoid leaking existence); cases added to the visibility leak suite.
- **Tests:** MCP contract tests (valid → shape; invalid → error; wrong scope → denied; other org id → not found).
- **Prototype check:** Now line wording matches the ticket page.
- **Size:** S

### P09.T4 — Round tools: get_round_notes and set_verdict 🏁
- **Why:** Claude Code must read what the developer marked and report back on it.
- **Steps:**
  1. `get_round_notes {key}`: latest shared round + caller's draft round: screenshots (ids, short-lived signed URLs), pins (number, x_pct, y_pct, note), verdicts ("Looks right"/"Not right yet"), notes, checklist items; scope `read`.
  2. `set_verdict {key, screenshot_id, verdict, note?}` on a draft screenshot, or `{key, check_item_id, state: "fixed"}` for checklist items (assignee only); scope `rounds:write`.
  3. Both call Review Api with the same voters as the draft round editor (owner-only drafts; shared drafts are read-only).
- **Files:** `src/AgentApi/Application/Tool/GetRoundNotes.php`, `src/AgentApi/Application/Tool/SetVerdict.php`.
- **Acceptance:**
  - [ ] Another user's draft → "not found"; a shared draft → "read-only".
  - [ ] `get_round_notes` returns exactly what the draft editor shows (same DTO as `RoundQueries::roundNotes`).
- **Tests:** MCP contract tests per tool (valid, invalid input, wrong scope, other org id, other user's draft).
- **Prototype check:** verdict labels match the draft round card in [ticket-page.md › Rounds](../04-prototype/screens/ticket-page.md#rounds).
- **Size:** M

### P09.T5 — Upload tools: create_upload and add_screenshot 🏁
- **Why:** Claude Code adds its own screenshots to the developer's draft round through the same safe upload path as the web.
- **Steps:**
  1. `create_upload {key, mime, bytes, sha256}`: same checks as `POST /media/intents` (P05.T1) with the draft round as target; returns `{upload_id, put_url, headers, expires_at}`; scope `rounds:write`.
  2. Tool description tells the agent how to upload: `curl -T <file> -H "Content-Type: image/png" "<put_url>"`.
  3. `add_screenshot {key, upload_id, note?, pins?[]}`: waits for or checks processing (P05.T2), adds the screenshot to the caller's draft round (creates the draft if none and the caller is the assignee); idempotency key supported; scope `rounds:write`. No base64 input.
- **Files:** `src/AgentApi/Application/Tool/CreateUpload.php`, `src/AgentApi/Application/Tool/AddScreenshot.php`.
- **Acceptance:**
  - [ ] A screenshot added by MCP appears in the owner's draft round within 10 s (processing time).
  - [ ] Polyglot or oversized image rejected with a plain error (media fixtures reused).
  - [ ] A request body over 1 MB is refused before the tool runs.
- **Tests:** MCP contract tests; media security tests reused; e2e: token → create_upload → PUT → add_screenshot → owner sees it, tester does not.
- **Prototype check:** draft round shows the new screenshot like a manually added one.
- **Size:** M

### P09.T6 — Write tools: add_comment, move_ticket
- **Why:** let the agent finish small steps the user already decided.
- **Steps:**
  1. `add_comment {key, text}`: comment only, never a question; scope `comments:write`; same validation as the UI.
  2. `move_ticket {key, verb, draft?: "share" | "keep"}`: run one allowed verb by name (`send_to_code_review`, …); if the caller has an unshared draft with screenshots, `draft` is required (same rule as the hand-off dialog); scope `tickets:move`.
  3. Return the new Now/Next lines.
- **Files:** `src/AgentApi/Application/Tool/AddComment.php`, `MoveTicket.php`.
- **Acceptance:**
  - [ ] Rules still apply (no self-approval, review before test, only tester to Done).
  - [ ] Missing `draft` choice → error that explains the two options.
- **Tests:** contract tests; voter table reused via the tool.
- **Prototype check:** [share draft dialog](../04-prototype/screens/dialogs-and-popovers.md#share-draft-dialog) behaviour.
- **Size:** M

### P09.T7 — REST /api/v1 for the browser plugin
- **Why:** the capture plugin (phase 10) needs simple HTTP endpoints.
- **Steps:**
  1. `POST /api/v1/uploads` (upload intent: presigned PUT), `POST /api/v1/reports` (create a report with media + pins), `POST /api/v1/tickets/{key}/draft/screenshots`, `GET /api/v1/me/tickets`.
  2. Same token auth, scopes and rate limits; idempotency keys (`api_idempotency_key`, 24 h).
  3. CORS: allow only the configured extension origin.
  4. OpenAPI description file `docs/api/v1.yaml` (write it).
- **Files:** `src/AgentApi/UI/Rest/*`, `docs/api/v1.yaml`.
- **Acceptance:**
  - [ ] Replayed POST with the same idempotency key returns the first result.
- **Tests:** functional per endpoint; CORS test; isolation.
- **Prototype check:** none.
- **Size:** M

### P09.T8 — Claude Code setup guide and the For Claude Code box 🏁
- **Why:** people must get from zero to a working loop in a few minutes.
- **Steps:**
  1. Write `docs/guides/claude-code.md`: create a token, `claude mcp add --transport http pinround https://<host>/mcp --header "Authorization: Bearer <token>"` (verify flags in the Claude Code docs), example prompts, what each tool does, how `create_upload` + `curl` + `add_screenshot` work together, safety notes (token = your permissions; revoke when lost).
  2. Ticket page draft round "For Claude Code" box: the copyable sentence "Read my pinround notes for SHOP-88 and fix what is marked "Not right yet"." and a link to the tokens page.
  3. Run the full loop by hand against the dev stack and record the checklist in the guide.
- **Files:** `docs/guides/claude-code.md`, `templates/review/_for_claude_code.html.twig`.
- **Acceptance:**
  - [ ] Anka (who did not build it) follows only the guide on a clean machine and finishes these steps in ≤ 10 minutes, recorded in the guide: create token → `claude mcp add` → `list_my_tickets` shows the seeded ticket → `get_round_notes` returns the pins → a new screenshot appears in the draft round → `set_verdict` changes the verdict in the UI.
  - [ ] The box renders for the draft owner only.
- **Tests:** e2e smoke that the box renders for the draft owner only.
- **Prototype check:** draft round "For Claude Code" box text.
- **Size:** S

## Definition of done
- [ ] All P09 tasks ticked; 🏁 loop demoable: token → `claude mcp add` → `list_my_tickets` → `get_round_notes` → `create_upload` + `curl` → `add_screenshot` → `set_verdict`.
- [ ] MCP contract tests for every tool, isolation and scope tests, log-leak test for tokens, audit per call.
- [ ] Quality gates green; docs and Index updated.

## Hand-off notes
- Phase 10 builds the browser extension on `/api/v1` and tokens with scope `rounds:write`.
- Note here the verified mcp-bundle version and any config quirks.
- Docs follow-up: FR-MCP-05 and ADR-0021 should list `create_upload` next to `add_screenshot` (outside docs/05-plan; flag it if they do not).
