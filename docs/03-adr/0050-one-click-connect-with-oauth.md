# ADR-0050 — One-click connect with OAuth for the extension, Claude Code and a Claude connector

pinround becomes an OAuth 2.1 authorization server, so the extension, Claude Code and a Claude custom connector connect
with a consent page instead of a pasted token. Pasted personal access tokens keep working.

## Index

- [Status](#status) — accepted, date, what it supersedes in ADR-0021.
- [Context and problem statement](#context-and-problem-statement) — Mia and Omar paste tokens; a Claude connector cannot.
- [Decision drivers](#decision-drivers) — one click, same scopes and voters, short-lived tokens, nothing in logs.
- [Considered options](#considered-options) — our own OAuth server, pasted tokens only, an outside identity server.
- [Decision outcome](#decision-outcome) — the flow, redirect URIs, consent (its organization, its CSRF token, step-up), client registration, the tokens and their storage, the clients, pasted tokens.
  - [Consequences](#consequences) — what gets easier and what it costs.
- [Confirmation](#confirmation) — the tests of P14.T20.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — the trade-offs.
- [Decided at acceptance](#decided-at-acceptance) — the product owner's answers on step-up, clients and storage, and the note on leavers' personal tokens.
- [More information](#more-information) — related ADRs, requirements, revisit triggers.

## Status

Accepted · 2026-11-21 · Deciders: Zairan Mir (lead, product owner).

It supersedes in part [ADR-0021](0021-mcp-server.md): the consequence "no OAuth flow for MCP in v1",
and its "Authentication" line, which allows only a personal access token as the Bearer token on `/mcp`. Everything
else in ADR-0021 stands: the same voters, scopes, request checks, limits and audit.
[P14.T20](../05-plan/phase-14-tester-feedback.md#p14t20--one-click-connect-with-oauth) builds it.

## Context and problem statement

Mia installs the pinround extension. To connect it she opens pinround, turns on two-step login, makes a personal access
token, copies it and pastes it into the extension. Omar does the same for Claude Code and pastes the token into a
command line. Lena wants to add pinround to Claude as a custom connector, but a Claude connector does not take a
pasted token at all: it expects OAuth. A token that sits in a config file also lives up to a year.

How do the extension, Claude Code and a Claude connector connect in one click, with no weaker way into the data?

## Decision drivers

- Exactly the same permission rules as the web UI: voters, tenant isolation, visibility, draft privacy
  ([ADR-0021](0021-mcp-server.md), [ADR-0013](0013-authorization.md)).
- The same scopes as personal access tokens; a client never gets more than its user.
- Short-lived access tokens, revocable, never stored or logged in plain text
  ([ADR-0027](0027-secrets-and-encryption.md), [ADR-0034](0034-allow-list-log-redaction.md)).
- A Claude custom connector needs OAuth; there is no paste field.
- People who already use pasted tokens keep them.

## Considered options

1. **OAuth 2.1 authorization server in pinround.** Authorization code with PKCE, a consent page, short-lived access
   tokens with refresh.
2. **Pasted tokens only.** Keep ADR-0021 as it is and make the token page easier.
3. **An outside identity server** (for example Keycloak) as the authorization server for `/mcp` and `/api/v1`.

## Decision outcome

Chosen option: **1, an OAuth 2.1 authorization server in pinround**, because it gives one-click connect to all three
clients, is the only way a Claude connector can connect, and keeps tokens, scopes and voters in one place.

- **Where.** Identity owns the authorization server, as it owns personal access tokens ([ADR-0012](0012-authentication.md)).
- **Flow.** OAuth 2.1 authorization code with PKCE. A code is single use; a wrong PKCE verifier or a reused code is
  refused, and no token is issued.
- **Redirect URIs.** The authorize and token endpoints accept only a redirect URI registered for the client, compared
  as an exact string (no prefix, pattern or normalised match). The token request must carry the same `redirect_uri`
  as the authorize request that issued the code. An unregistered or changed redirect URI is refused on the authorize
  page itself, with no redirect and no code.
- **Consent.** A consent page in the signed-in browser lists the client and the scopes it asks for. The person picks
  one organization they are a member of, then allows or denies. The consent POST checks on the server that the
  person is a member of the posted organization; otherwise it answers 404 and issues no code. The Allow POST needs a
  valid CSRF token; without one it answers 422 ("The page was open too long. Try again.", as the other forms do) and
  issues no code.
- **Step-up on consent.** The consent page asks for the same proof as making a personal access token: password and
  two-step login within the last 10 minutes (`#[RequiresRecentAuth]`, [ADR-0012](0012-authentication.md)). The demo
  switch of [ADR-0049](0049-demo-switch-for-two-step-login.md) counts here as it does for tokens.
- **One organization per grant.** The code, the access token and the refresh token are bound to the organization
  picked on the consent page, as a personal access token is bound to its organization. A token reads and writes only
  that organization, and stops working (access and refresh) when the person's membership of it ends.
- **Tokens.** Access tokens of 1 hour; refresh tokens with rotation; revocation. The same scopes as personal access
  tokens ([ADR-0021](0021-mcp-server.md), [ADR-0039](0039-agents-file-report-drafts-a-person-sends-them.md),
  [ADR-0046](0046-notebooks-and-scenarios-for-understanding-an-app.md)). A token acts as its user with that user's
  voters, and `ADMIN_*` voters still deny every request made with one.
- **Discovery.** Authorization server metadata and protected-resource metadata for `/mcp`, so an MCP client finds the
  server on its own.
- **Client registration.** Clients register themselves with dynamic client registration (RFC 7591), as the MCP
  authorization spec allows; no admin step. Only public clients: no client secret, and PKCE with `S256` is required.
  A redirect URI is `https`, `http` on a loopback host (`localhost`, `127.0.0.1`, `[::1]`) as native clients such as
  Claude Code use, or the extension's `https://<id>.chromiumapp.org/` address; anything else is refused at
  registration. Registration is rate-limited per IP, and the consent page shows the client's name with the host of
  its redirect URI, so a person sees where the code goes.
- **Token storage.** Codes, access tokens and refresh tokens are stored only as HMAC-SHA256 hashes keyed by
  `API_TOKEN_PEPPER`, as [ADR-0043](0043-api-token-hash-is-hmac-sha256.md) stores personal access tokens. The
  database never holds a usable token.
- **Clients.**
  - The extension: "Connect" opens the consent page with `chrome.identity.launchWebAuthFlow`; "Sign out" revokes.
  - Claude Code: the guide documents the steps.
  - A Claude custom connector: the guide documents the steps, and says the connector reaches pinround from Anthropic's
    servers, so it needs a public HTTPS address (not `https://localhost`).
- **Pasted tokens keep working.** Personal access tokens, their page and their step-up rule stay as they are.

### Consequences

- Good: the extension, Claude Code and a Claude connector connect in one click.
- Good: a stolen access token is useful for an hour, not up to a year.
- Good: one token check on `/mcp` and `/api/v1` for both kinds of token, with the same scopes and voters.
- Bad: an authorization server is a new public surface (authorize, token, revoke, metadata) to harden, rate-limit
  and test against OWASP ASVS L2.
- Bad: two kinds of token to keep in step: scopes, revocation, the admin list of tokens, and the audit of each call.
- Bad: a Claude connector cannot reach a local install; a demo on `https://localhost` connects only the extension and
  Claude Code.
- Neutral: a new dependency or a new piece of Identity, chosen in P14.T20.

## Confirmation

- The extension connects without a pasted token, and its calls use the issued token (Playwright, P14.T20).
- A code is single use; a wrong PKCE verifier and a reused code are refused, and no token is issued.
- A refreshed token rotates: the old refresh token is refused after use.
- An unregistered redirect URI, or a registered one changed by a single character, a trailing slash or a different
  case, is refused at authorize with no redirect and no code; a token request with a `redirect_uri` other than the
  authorize request's is refused, and no token is issued.
- A token issued for Acme Foods cannot read another organization: the same call answers not found, as with a personal
  access token of Acme Foods. After the person leaves Acme Foods, its access and refresh tokens are refused.
- A consent submitted with the id of an organization the person is not a member of answers 404, and no code is
  issued.
- An Allow POST with a missing or wrong CSRF token answers 422, and no code is issued.
- Tokens never appear in logs (log test).
- The MCP and REST integration tests of ADR-0021 run with an issued token as with a personal access token: wrong
  tenant not found, missing scope refused, another person's draft not found.

## Pros and cons of the options

### Option 1, OAuth 2.1 in pinround

- Good, because one click connects every client, and a Claude connector can connect at all.
- Good, because tokens are short-lived and the scopes and voters stay in one place.
- Bad, because it is the most code of the three and a new public surface.

### Option 2, pasted tokens only

- Good, because nothing new to build or harden.
- Bad, because a Claude connector cannot connect, and every person still turns on two-step login, makes a token and
  pastes it.

### Option 3, an outside identity server

- Good, because a tested product runs the OAuth flow.
- Bad, because small organizations run one more service, which ADR-0012 already turned down.
- Bad, because scopes, users and revocation live in two places.

## Decided at acceptance

The product owner answered on 2026-11-21:

- **Step-up.** The consent page asks for the same recent password and two-step login as making a personal access
  token.
- **Client registration.** Clients register themselves (dynamic client registration), public clients with PKCE only;
  a Claude connector cannot wait for an admin.
- **Token storage.** Hashes only, as for personal access tokens.

Note, 2026-11-21: the product owner decided that a leaver's personal access tokens are refused too, not only the OAuth
tokens of "One organization per grant". `resolve_api_token` finds a token only while it is neither revoked nor expired and
its person still has a membership row in the token's organization (`Version20261121193200`), so every token a person holds stops at their next request once they leave; the token
rows stay until the retention job purges them.

## More information

- Related: [ADR-0012](0012-authentication.md), [ADR-0013](0013-authorization.md), [ADR-0021](0021-mcp-server.md),
  [ADR-0027](0027-secrets-and-encryption.md), [ADR-0043](0043-api-token-hash-is-hmac-sha256.md).
- Requirements: FR-ID-05, FR-MCP-01, FR-MCP-11, FR-RND-15; the connect requirements P14.T20 writes in the extension
  section.
- Guides: [claude-code.md](../guides/claude-code.md), [browser-extension.md](../guides/browser-extension.md).
- Plan: [phase 14](../05-plan/phase-14-tester-feedback.md), P14.T20.
- Revisit when: fewer than 10% of new connections in 60 days use a pasted token (then pasted tokens may go for the
  extension), or the MCP authorization spec changes the flow Claude Code and the connector use.

## Note of 2026-11-22: optional scopes on the consent page
The consent page also lists, unticked, every scope the app did not ask for. A scope the person ticks joins the code's
scopes; one the page did not offer is refused with `invalid_scope` and no code. This keeps ADR-0039 (report drafts and
notebooks are only ever the person's own tick) and lets Claude Code file report drafts without a pasted token.
