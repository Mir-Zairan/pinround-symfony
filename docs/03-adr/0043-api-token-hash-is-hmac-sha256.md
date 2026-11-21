# ADR-0043 — The personal access token hash is HMAC-SHA256 keyed by API_TOKEN_PEPPER

Stored token hashes are an HMAC-SHA256 of the token keyed by the pepper, read from the `API_TOKEN_PEPPER` environment variable; supersedes in part the token storage line of ADR-0021 (and the same line in ADR-0012 and ADR-0027).

## Index

- [Status](#status) — Accepted, with the date and what it supersedes.
- [Context and problem statement](#context-and-problem-statement) — what ADR-0021 said and why it is not precise enough.
- [Decision drivers](#decision-drivers) — a keyed hash with no join rule, a fast lookup, a fail-closed secret.
- [Considered options](#considered-options) — SHA-256 of a joined string, HMAC-SHA256.
- [Decision outcome](#decision-outcome) — the hash, where the pepper comes from, and the consequences.
- [Confirmation](#confirmation) — the tests that pin it.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — the trade-offs.
- [More information](#more-information) — related ADRs and revisit triggers.

## Status

Accepted · 2026-11-18 · Deciders: Zairan Mir (lead), with the team.

It supersedes in part [ADR-0021](0021-mcp-server.md): only the line "Stored as SHA-256 of (token + server-side pepper from secrets)". The same words in [ADR-0012](0012-authentication.md) ("stored as a SHA-256 hash (with a server pepper)") and [ADR-0027](0027-secrets-and-encryption.md) ("Personal access tokens: SHA-256 with a pepper") are superseded the same way. The rest of those ADRs stands: the `pr_` prefix, the display prefix, shown once, scopes, expiry and revocation.

## Context and problem statement

Omar makes a token for Claude Code. Pinround keeps only a hash of it, so a copy of the database does not let anyone use it. ADR-0021 says the hash is SHA-256 of the token joined with a pepper "from secrets". It does not say how the two are joined, and a plain hash of a joined string depends on that choice. It also does not say how the pepper reaches the app: the prod entrypoint guard reads only the environment, so a value kept only in the Symfony secrets vault looks empty to it.

How is the token hash computed, and where does its key come from?

## Decision drivers

- A keyed hash whose result does not depend on a join rule someone could change by accident.
- One indexed lookup of a presented token (`UNIQUE (token_hash)`), so no slow, salted password hash.
- A missing or weak pepper must stop the app, never let tokens be issued or accepted without it.

## Considered options

1. SHA-256 of the token joined with the pepper (ADR-0021 as written).
2. HMAC-SHA256 of the token keyed by the pepper.

## Decision outcome

Chosen option: **2**, because HMAC is the standard way to key a hash with a secret and needs no join rule.

- `token_hash` is `hash_hmac('sha256', $plainToken, $pepper, true)`: 32 raw bytes (`HmacApiTokenHashing`, behind `Identity\Domain\ApiTokenHashing`).
- The pepper is the plain environment variable `API_TOKEN_PEPPER`, from the platform's secret store in prod, never a Symfony secret and never in the repo ([config.md](../operations/config.md)).
- It has at least 32 bytes. An unset variable fails when the container builds the hashing service, an empty or shorter one fails its constructor, and the prod entrypoint refuses to start when it is empty, unset or a `change-me` placeholder.

### Consequences

- Good: the hash has one exact definition, and any implementation of HMAC-SHA256 computes the same bytes.
- Good: a lost database copy without the pepper cannot be used to check guessed tokens.
- Bad: changing the pepper makes every issued token stop working; there is no rotation with two keys yet.
- Neutral: the lookup stays one indexed equality on `token_hash`, before any tenant is known (`resolve_api_token`).

Note, 2026-11-21: since `Version20261121193200`, `resolve_api_token` returns a token only while it is neither revoked nor expired and its person still has an `org_membership` row in the token's organization ([ADR-0050](0050-one-click-connect-with-oauth.md)). The hash lookup is unchanged; a leaver's token gives no row, like an unknown one.

## Confirmation

- `tests/Unit/Identity/Infrastructure/Security/HmacApiTokenHashingTest.php`: the hash equals `hash_hmac('sha256', …, true)`, and a short or empty pepper is refused.
- `tests/Integration/Identity/ApiTokenWiringTest.php`: the hashing service the container builds uses the `API_TOKEN_PEPPER` of `.env.test`.
- `tests/Integration/Identity/Infrastructure/Doctrine/DoctrineApiTokensTest.php`: the stored row holds the HMAC and never the plain token.
- `tests/Unit/Architecture/EntrypointProdGuardTest.php`: prod refuses to start without a real pepper.

## Pros and cons of the options

### SHA-256 of a joined string

- Good, because it is what ADR-0021 wrote.
- Bad, because its bytes depend on the join order and separator, which nothing pins, and it is not the standard keyed construction.

### HMAC-SHA256

- Good, because it is the standard keyed hash, with one definition.
- Bad, because the ADRs that said SHA-256 need this note.

## More information

- Related: [ADR-0021](0021-mcp-server.md), [ADR-0012](0012-authentication.md), [ADR-0027](0027-secrets-and-encryption.md), [phase 09 hand-off notes](../05-plan/phase-09-mcp-and-claude-code.md#hand-off-notes).
- Revisit when: the pepper must be rotated without revoking every token (a key id stored with each hash), or a compliance review asks for a different keyed hash.
