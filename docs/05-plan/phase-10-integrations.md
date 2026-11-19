# Phase 10 — Integrations: Jira, company login, browser capture

Goal: teams that still use Jira can work in pinround without double entry, people sign in with their company account, and anyone can capture a page into a report with one click.

## Index
- [Before you start](#before-you-start) — prototype screens, requirements, ADRs and docs to read first
- [Outcome](#outcome) — what people can do when this phase is done
- [Scope](#scope) — what this phase builds
- [Out of scope](#out-of-scope) — what waits
- [Requirements covered](#requirements-covered) — FR and NFR IDs
- [Design notes](#design-notes) — modules, tables, routes, security and speed notes
- [Tasks](#tasks) — P10.T1 to P10.T9, one session each
- [Definition of done](#definition-of-done) — phase checklist
- [Hand-off notes](#hand-off-notes) — what phase 11 must know

## Before you start
1. **Open the prototype.** Link: https://claude.ai/artifact/FmbJosB3s5mDi75cLqMFQ2 (private to its owner). In Claude Code use the Artifact tool with `action: "read"`, `url` = the link and `path: "project/Main.dc.html"`. Study: Admin › **Connections** (Jira CONNECTED, status mapping table, company login), the **Team** page "Projects" with the **Add a project** dialog (search, multi-select), the ticket page note "Jira status: … · updated …" and "Moved in pinround just now. It syncs back to Jira.", and the "Capture with pinround" button on Report a problem.
2. **If you cannot open the link**, read: [admin.md › Connections](../04-prototype/screens/admin.md#connections), [team.md › Projects](../04-prototype/screens/team.md#projects), [dialogs-and-popovers.md › Add a project](../04-prototype/screens/dialogs-and-popovers.md#add-a-project), [ticket-page.md › Header](../04-prototype/screens/ticket-page.md#header), [report-a-problem.md › Fields and copy](../04-prototype/screens/report-a-problem.md#fields-and-copy).
3. **Read the requirements:** all of [JIRA](../01-requirements/functional-requirements.md#jira--jira-integration) (FR-JIRA-01, FR-JIRA-02, FR-JIRA-03, FR-JIRA-04, FR-JIRA-05, FR-JIRA-06, FR-JIRA-07, FR-JIRA-08, FR-JIRA-09), FR-ID-04 in [ID](../01-requirements/functional-requirements.md#id--identity-and-access), FR-ORG-07 in [ORG](../01-requirements/functional-requirements.md#org--organization-teams-and-roles), FR-TKT-17 in [TKT](../01-requirements/functional-requirements.md#tkt--tickets), FR-RND-15, FR-RND-16 in [RND](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds), FR-ADM-08 in [ADM](../01-requirements/functional-requirements.md#adm--admin), FR-AI-17 in [AI](../01-requirements/functional-requirements.md#ai--ai-assist); constraint C-05 in [constraints.md](../01-requirements/constraints.md); [SEC](../01-requirements/non-functional-requirements.md#sec--security).
4. **Read the decisions and design:** [ADR-0025 Jira integration](../03-adr/0025-jira-integration.md), [ADR-0012 authentication](../03-adr/0012-authentication.md), [ADR-0027 secrets and encryption](../03-adr/0027-secrets-and-encryption.md), [ADR-0009 async and outbox](../03-adr/0009-async-messaging-and-outbox.md), [ADR-0014 status model](../03-adr/0014-status-model-five-groups.md); [integrations.md](../02-architecture/integrations.md) (all sections), [workflow-engine.md › Jira status mapping](../02-architecture/workflow-engine.md#jira-status-mapping), [modules.md › Integration](../02-architecture/modules.md#integration-int_), [media-pipeline.md › Other inputs](../02-architecture/media-pipeline.md#other-inputs), [security.md › Threat model (STRIDE)](../02-architecture/security.md#threat-model-stride).
5. **Check the previous phase:** [phase 09 Definition of done](phase-09-mcp-and-claude-code.md#definition-of-done) (tokens and `/api/v1` are needed for the extension).

## Outcome
Acme Foods still uses Jira for some teams. Sara connects Jira Cloud in Admin › Connections. Lena links project SHOP to her team from the Team page. Open SHOP issues arrive with their Jira status mapped to pinround steps. When Mia presses "Pass it", the Jira issue moves to Closed too. When someone edits the priority in Jira, pinround updates within a minute and writes "Jira changed the priority to Major" in the ticket's activity. Sara turns on company login with Microsoft Entra ID, and people sign in with their work account. Ben installs the pinround browser extension and sends a screenshot with pins in one click.

## Scope
- Jira Cloud connect (OAuth 2.0 3LO preferred, API token fallback, encrypted).
- Link Jira projects to a team; import; webhooks; nightly reconcile; status mapping UI; sync back transitions; conflicts.
- OIDC company login (Entra ID, Google, any provider by discovery URL), "require SSO" with break-glass admin.
- Chrome MV3 capture extension using `/api/v1`.

## Out of scope
- Copying comments to Jira (off in v1; admin switch later).
- Slack and Microsoft Teams channels (later, through the Notification channel interface).
- SSO group-to-role sync (later).

## Requirements covered
FR-JIRA-01, FR-JIRA-02, FR-JIRA-03, FR-JIRA-04, FR-JIRA-05, FR-JIRA-06, FR-JIRA-07, FR-JIRA-08, FR-JIRA-09, FR-ID-04, FR-ORG-07 (projects part), FR-TKT-17, FR-RND-15, FR-RND-16 (title and summary sent by the plugin), FR-ADM-08, FR-AI-17 (trigger from import), FR-AUD-01 (connection changes).
NFR-SEC-01, NFR-SEC-05, NFR-SEC-10, NFR-SEC-14, NFR-AVL-05, NFR-PERF-09, NFR-OPS-03.

## Design notes
- **Module:** Integration (`App\Integration`). Tables: `int_jira_connection` (base_url, auth_type, `secret_enc`, `key_id`, status), `int_project_link`, `int_status_mapping`, `int_external_ref`, `int_sync_log`, `int_sync_state` (import cursor). Identity owns SSO (`idn_sso_identity`); provider config lives in `org_settings.data` (secrets encrypted).
- **Routes:** `GET/POST /admin/connections`, `GET /integrations/jira/oauth/callback`, `POST /integrations/jira/{connectionId}/webhook`, `/login/sso/{provider}`, `/login/sso/{provider}/callback`, Team page `POST /teams/{slug}/projects`.
- **Queues:** all Jira work on the `integration` transport, one lane per connection, RateLimiter token bucket; respect `Retry-After` on 429; failure transport + admin notice.
- **Rules (binding):** comments are not synced; same field changed on both sides within 60 s → Jira wins and an activity note is written; otherwise last writer wins per field; pinround-only data (rounds, pins, verdicts, checks, private data) never goes to Jira; a webhook is only a **trigger**: the handler stores the issue id and queues a job, and the job re-fetches the issue over REST, so the webhook body is never trusted.
- **Outbound URL guard (binding, also for OIDC discovery and server Ollama):** https only; resolve DNS and refuse private, loopback and link-local addresses **at connect time** (not only when the URL is saved); no redirects. Jira base URLs must match `*.atlassian.net` or `api.atlassian.com`.
- **Restricted imports:** a Jira issue with a security level, or whose text matches the security word list (P06.T3), is imported with `visibility = 'team'`.
- **SSO identity (binding):** SSO identities are keyed by `(provider_config_id, iss, sub)`. pinround never links an SSO login to an existing account by email alone; linking needs the current password plus MFA. A session that came through org X's provider opens only org X memberships. Allowed email domains need DNS TXT proof. Entra `email`/`upn` claims are never used as identity.
- **Security:** webhook authenticity (shared secret or signature; verify what Jira Cloud offers) on top of the re-fetch rule; the connection is found through its resolver function (P00.T4); unknown connection or bad signature → 404 without detail; replay safe by `external_version`; Jira tokens field-encrypted; OIDC Authorization Code + PKCE, `state` and `nonce` checked; extension CORS allows only its origin.
- **Speed:** import is paged with a stored cursor and never blocks web requests; webhook endpoint answers in < 100 ms.
- **Verify at implementation:** current Jira Cloud REST API version and fields, ADF → Markdown conversion library, webhook registration method, OIDC client library for Symfony, Chrome MV3 capture APIs.

## Tasks

### P10.T1 — Connect Jira and store credentials safely
- **Why:** everything else needs a working, secure connection.
- **Steps:**
  1. Admin › Connections › Jira: "Connect with Atlassian" (OAuth 2.0 3LO) or "Use an API token" (email + token).
  2. Base URL through the outbound URL guard (design notes; only `*.atlassian.net` or `api.atlassian.com`). Store secrets with field encryption (libsodium secretbox, key id); test call to Jira; show site, status CONNECTED, last sync, project count.
  3. Disconnect and reconnect (rotates secret); audit every change.
- **Files:** `src/Integration/Application/ConnectJira.php`, `src/Integration/Infrastructure/Jira/JiraClient.php`, `templates/admin/connections.html.twig`.
- **Acceptance:**
  - [ ] Secret never appears in logs, HTML or exports.
  - [ ] Only admins (voter `ADMIN_CONNECTIONS`) with a recent MFA check can connect.
  - [ ] Base URLs `http://…`, `https://10.0.0.5`, `https://example.com` and a host that resolves to `127.0.0.1` are refused; a redirect from Jira is not followed.
- **Tests:** functional (connect, disconnect), log-leak test with a marker token, encryption round trip with key rotation.
- **Prototype check:** [admin.md › Connections](../04-prototype/screens/admin.md#connections).
- **Size:** M

### P10.T2 — Link projects to a team (Team page)
- **Why:** leads decide which Jira projects belong to their team.
- **Steps:**
  1. Team page "Projects": list linked projects with ticket counts, link to the filtered list, remove (lead only; others see "Only the team lead can change projects and steps.").
  2. "Add project" dialog: search Jira projects by key or name, multi-select, "Add N projects".
  3. Linking emits an `ImportProject` job through the outbox.
- **Files:** `src/Integration/UI/Controller/ProjectLinkController.php`, `templates/team/_projects.html.twig`, Stimulus for the dialog search.
- **Acceptance:**
  - [ ] A project can be linked to one team only per org (`UNIQUE (organization_id, jira_key)`).
- **Tests:** voter (lead only), functional, isolation.
- **Prototype check:** [team.md › Projects](../04-prototype/screens/team.md#projects), [Add a project](../04-prototype/screens/dialogs-and-popovers.md#add-a-project).
- **Size:** M

### P10.T3 — Import
- **Why:** bring existing work in without retyping.
- **Steps:**
  1. Worker pages through issues (open + updated in the last 90 days) with a cursor in `int_sync_state`; resumes after a crash.
  2. Upsert tickets by `int_external_ref` (idempotent): title, description (ADF → safe Markdown), type, priority, assignee/reporter matched by email (else name only), releases, components, parent, status → step via mapping. Issues with a Jira security level, or whose text matches the security word list, are imported with `visibility = 'team'`.
  3. Turn rows projected; summaries on sync triggered when the AI policy allows (phase 08).
  4. Import progress shown in Connections ("Importing SHOP: 340 of 1,200").
- **Files:** `src/Integration/Infrastructure/Messenger/ImportProjectHandler.php`, `src/Integration/Infrastructure/Jira/AdfToMarkdown.php`.
- **Acceptance:**
  - [ ] Running the import twice creates no duplicates.
  - [ ] Imported HTML/Markdown is sanitized (no script, no raw HTML).
  - [ ] An issue with a security level is imported as team-only and passes the visibility leak suite.
- **Tests:** integration with a recorded Jira fixture (neutral data), idempotency test, XSS test on descriptions.
- **Prototype check:** list row note "Jira: <status>", ticket note "Jira status: … · updated …".
- **Size:** L

### P10.T4 — Webhooks and nightly reconcile
- **Why:** keep pinround current within a minute.
- **Steps:**
  1. Register a webhook per connection (issue created/updated/deleted).
  2. `POST /integrations/jira/{connectionId}/webhook`: find the connection through its resolver function, verify authenticity, store only the issue id and event type, queue `ApplyJiraChange`, answer fast.
  3. Handler re-fetches the issue over REST (never uses the webhook body), compares `external_version`, skips older or equal, applies field changes, writes activity lines ("Jira changed the priority to Major").
  4. Nightly reconcile job re-fetches issues updated since the last processed event.
- **Files:** `src/Integration/UI/Controller/JiraWebhookController.php`, `src/Integration/Infrastructure/Messenger/ApplyJiraChangeHandler.php`, `src/Integration/Infrastructure/Scheduler/JiraReconcile.php`.
- **Acceptance:**
  - [ ] Bad signature or unknown connection → 404, nothing stored.
  - [ ] Replayed event changes nothing.
  - [ ] A forged webhook body with a changed priority does not change pinround (the re-fetched issue wins).
- **Tests:** functional (auth, replay), integration (apply change), scheduler test.
- **Prototype check:** ticket activity wording.
- **Size:** M

### P10.T5 — Status mapping UI and sync back
- **Why:** moves in pinround must move Jira, and Jira statuses must land on the right step.
- **Steps:**
  1. Admin › Connections mapping table: Jira status → step (with its group); defaults by name; unmapped statuses listed as "needs mapping" with an Admin card alert; fallback by Jira category (done → Done, in progress → In progress, else To do).
  2. On `TicketMoved` (outbox) → `PushJiraTransition`: find a Jira transition that reaches the mapped status; run it; on failure retry with backoff; after 3 failures show "Could not update Jira: no transition to Testing" on the ticket.
  3. Ticket note "Moved in pinround just now. It syncs back to Jira."
  4. Conflict rule: within 60 s Jira wins + activity note; else last writer wins per field.
- **Files:** `src/Integration/UI/Controller/StatusMappingController.php`, `src/Integration/Infrastructure/Messenger/PushJiraTransitionHandler.php`.
- **Acceptance:**
  - [ ] Mapping is data (`int_status_mapping`), never code.
  - [ ] Rounds, pins, verdicts, checks and private data are never sent.
- **Tests:** unit (conflict rule, fallback by category), integration with fake Jira client, functional mapping UI.
- **Prototype check:** Connections mapping table.
- **Size:** M

### P10.T6 — Company login (OIDC SSO)
- **Why:** people should sign in with their work account; admins want one place to remove access. Done wrong, one org's admin could log in as a person of another org.
- **Steps:**
  1. Choose and verify a maintained OIDC client for Symfony; presets for Microsoft Entra ID and Google; any provider by discovery URL (through the outbound URL guard).
  2. `idn_sso_identity` keyed by `(provider_config_id, iss, sub)`. Authorization Code + PKCE; check `state`, `nonce`, issuer and audience.
  3. First SSO login of an unknown `(iss, sub)`: if an invite for this org matches, create the account; if an account with the same email exists, **do not auto-link** — show "Log in with your password once to connect company login", then link only after password + MFA. Never use Entra `email`/`upn` claims as identity.
  4. A session that came through org X's provider opens only org X memberships; switching to another org asks for that org's login.
  5. Allowed email domains for self-join need DNS TXT proof (`pinround-verify=<token>`), checked again daily.
  6. Admin › Connections › Company login: set up / turn off, "Require company login for everyone" with one break-glass admin (MFA required).
  7. Audit setup, changes, links and SSO logins.
- **Files:** `src/Identity/Infrastructure/Security/OidcAuthenticator.php`, `src/Identity/UI/Controller/SsoController.php`, `src/Identity/Application/LinkSsoIdentity.php`, `templates/admin/_company_login.html.twig`.
- **Acceptance:**
  - [ ] Login fails safely on wrong `state`, `nonce`, issuer or audience.
  - [ ] An IdP of org B that asserts the email of a user of org A cannot log in as that user and gets no org A membership (test with two mocked IdPs).
  - [ ] Linking an SSO identity to an existing account needs the password and an MFA code.
  - [ ] With SSO required, password login is refused except for the break-glass admin.
  - [ ] An unverified domain cannot be used for self-join.
- **Tests:** functional with mocked IdPs (two orgs), security tests for state/nonce/PKCE and the cross-org case.
- **Prototype check:** Connections "Company login" texts.
- **Size:** L

### P10.T7 — Browser capture extension: capture and upload
- **Why:** reporting should start with one click on the broken page.
- **Steps:**
  1. Create `extension/` (Chrome MV3): popup with "Capture this page".
  2. Capture the visible tab, ask `/api/v1/uploads` for an upload intent, PUT the image to the presigned URL, then open pinround "Report a problem" (or the draft round of a chosen ticket) with the media attached. The popup has an optional "Title" (≤ 120 characters) and "What this picture shows" (≤ 600 characters) per capture; they are sent with the report or draft screenshot ([FR-RND-16](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds)) and checked against the same caps. The extension's token has scope `rounds:write` only: it never creates a ticket or a report draft; a person sends the report in the app ([ADR-0039](../03-adr/0039-agents-file-report-drafts-a-person-sends-them.md)).
  3. Minimal permissions in the manifest (verify the exact permission names in the Chrome docs).
- **Files:** `extension/manifest.json`, `extension/src/capture/*`.
- **Acceptance:**
  - [ ] Works end to end on the dev stack with a hard-coded dev token; the screenshot passes the normal media validation.
  - [ ] The manifest asks only for the permissions listed in the guide.
- **Tests:** API functional tests (already from P09.T7); a manual test checklist.
- **Prototype check:** Report a problem screenshot area.
- **Size:** M

### P10.T8 — Browser capture extension: pairing, options and guide
- **Why:** the extension must connect to the right pinround safely and be easy to install.
- **Steps:**
  1. Options page for the pinround address and pairing: either paste a token (scope `rounds:write`) or a pairing page in pinround that issues one (decide; document the choice).
  2. The extension talks only to the configured address; token stored in extension storage; clear sign-out that also revokes the token.
  3. "Capture with pinround" on Report a problem explains how to install the extension when it is not present.
  4. Write `docs/guides/browser-extension.md` (install, pair, capture, sign out, permissions and why).
- **Files:** `extension/src/options/*`, `docs/guides/browser-extension.md`.
- **Acceptance:**
  - [ ] Sign-out revokes the token on the server.
  - [ ] Requests to any host other than the configured address are refused by the extension.
- **Tests:** functional test of the pairing endpoint (if built); manual checklist in the guide.
- **Prototype check:** "Capture with pinround" button text on Report a problem.
- **Size:** M

### P10.T9 — Integration hardening
- **Why:** integrations fail in the real world; failures must be visible and safe.
- **Steps:**
  1. Admin notices: "Jira sync paused: token expired", rate limited, mapping missing; shown on Admin card and Connections.
  2. Sync log view in Connections (last 50 events, no content).
  3. Runbook draft "Jira sync paused" (final runbooks in phase 12).
- **Files:** `src/Integration/Application/SyncHealth.php`, `templates/admin/_sync_log.html.twig`.
- **Acceptance:**
  - [ ] A revoked Jira token pauses sync within 5 minutes and alerts admins.
- **Tests:** integration (pause on 401), functional (alerts).
- **Prototype check:** Admin card alert style.
- **Size:** S

## Definition of done
- [ ] All P10 tasks ticked; Jira round trip (import → move in pinround → Jira transition → Jira edit → pinround update) works on a test Jira site with neutral data.
- [ ] Webhook auth/replay/re-fetch tests, outbound URL guard tests, OIDC security tests (including the cross-org case), encryption and log-leak tests green.
- [ ] Integration queue isolated; import does not slow Home (measure).
- [ ] Quality gates green; integrations.md updated with verified API details; docs and Index updated.

## Hand-off notes
- Phase 11 builds the remaining admin pages; Connections from this phase stays as is.
- Record the chosen OIDC library, Jira API version and extension pairing choice here.
- 2026-11-19 (plan change): [phase 13](phase-13-capture-explore-and-scenarios.md) builds on P10.T7 and P10.T8 (pin overlay, session target, notebooks, scenario player). [ADR-0045](../03-adr/0045-evidence-is-captured-where-the-person-works.md) was accepted on 2026-11-19, so give the pairing page optional `reports:write` and `notebooks:write` scopes instead of `rounds:write` only, and request `activeTab` and `chrome.scripting` so P13.T5 needs no permission change.
