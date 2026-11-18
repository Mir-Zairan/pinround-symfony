# ADR-0027: Secrets, encryption and redaction: encrypt credentials in fields, encrypt everything in transit and at rest, keep content out of logs

Credentials are encrypted inside the database. Every connection uses TLS. Logs never hold ticket text, prompts or tokens.

## Index

- [Status and people](#status-and-people): status, date, deciders.
- [Context and problem statement](#context-and-problem-statement): the scene: a leaked backup and a noisy log.
- [Decision drivers](#decision-drivers): what must be protected and why.
- [Considered options](#considered-options): disk encryption only, field encryption for credentials, encrypt all content.
- [Decision outcome](#decision-outcome): transport, rest, field encryption with key ids, secrets, logs, browser headers.
  - [Consequences](#consequences): good, bad and neutral effects.
- [Confirmation](#confirmation): tests and CI checks.
- [Pros and cons of the options](#pros-and-cons-of-the-options): details per option.
- [More information](#more-information): links and when to revisit.
- [Clarifications (2026-11-08)](#clarifications-2026-11-08): stored format, key variables, errors, what is not built yet.

## Status and people

- Status: Accepted · Superseded in part by [ADR-0034](0034-allow-list-log-redaction.md): the first "Logs and traces" bullet (redact known keys and token patterns) and the `[redacted]` Confirmation line. Logs keep only allow-listed keys instead. Superseded in part by [ADR-0043](0043-api-token-hash-is-hmac-sha256.md): the "Personal access tokens: SHA-256 with a pepper" line; the hash is HMAC-SHA256 keyed by the `API_TOKEN_PEPPER` environment variable. The rest stands.
- Date: 2026-11-06
- Deciders: Zairan (lead), Toyib (developer), Anka (tester)

## Context and problem statement

A database backup is copied to a shared drive by mistake. If it holds Jira OAuth refresh tokens in plain text, an attacker can read every Jira issue of the company. Separately, an error log contains the full text of a security ticket, because an exception message included the ticket body. The log is shipped to a third-party log tool.

pinround stores sensitive company data: bug reports about security issues, screenshots of internal systems, and credentials for integrations.

## Decision drivers

- Credentials stay safe even if the DB or a backup leaks.
- All traffic is encrypted, and so is all storage.
- Ticket text stays searchable (ADR-0022), so it cannot be app-encrypted without losing search.
- Logs and traces are safe to ship to observability tools.
- Keys can be rotated without downtime.

## Considered options

1. **Disk and transport encryption only.**
2. **Option 1 + app-level field encryption for credentials + log redaction (chosen).**
3. **App-level encryption of all content** (ticket text, comments).

## Decision outcome

Chosen option: **2**.

- **In transit:**
  - TLS 1.2+ (1.3 preferred) on all public endpoints, with HSTS (`max-age=31536000; includeSubDomains`).
  - TLS between the app and Postgres, Valkey and object storage when they are not on the same private host.
  - Jira and AI provider calls over HTTPS only.
- **At rest:**
  - Encrypted volumes for Postgres and Valkey persistence.
  - Object storage with server-side encryption (SSE-S3 or SSE-KMS; MinIO with KES for self-host, check the setup at implementation time).
  - Backups encrypted.
- **Field encryption** for secrets in the DB: Jira OAuth tokens and API tokens, OIDC client secrets, webhook secrets, and MFA TOTP seeds.
  - Uses libsodium `crypto_secretbox` (XSalsa20-Poly1305) through PHP's sodium extension.
  - Stored as `key_id || nonce || ciphertext`.
  - Keys come from the secret store (Symfony secrets vault in production, or the orchestrator's secret manager), never from the DB.
  - **Rotation:** add a new key id as "current". Reads accept old ids, and a background job re-encrypts rows. The old key is removed after the job reports zero rows.
- **Hashing, not encryption:**
  - Passwords: argon2id (ADR-0012).
  - Personal access tokens: SHA-256 with a pepper (ADR-0021).
  - IPs in the audit log: HMAC (ADR-0026).
- **Application secrets:** Symfony secrets or environment variables from the orchestrator. Never in the repo. CI runs secret scanning (gitleaks or GitHub secret scanning, plus our `pr_` token pattern).
- **Logs and traces:**
  - A Monolog processor redacts known keys (`password`, `token`, `authorization`, `secret`, `description`, `body`, `prompt`, `answer`, `note`) and any string matching token patterns.
  - Exception messages from domain code must not include user content. Use ids.
  - OpenTelemetry spans carry ids and sizes only (ADR-0029).
  - The AI gateway logs metadata only (ADR-0018).
- **Browser:**
  - Strict Content-Security-Policy with per-request nonces: no inline scripts, `object-src 'none'`, `base-uri 'self'`, `frame-ancestors 'none'`.
  - `img-src` lists our storage host for signed URLs.
  - `connect-src` adds localhost:11434 only for laptop AI orgs (ADR-0019).
  - Also: `Referrer-Policy: strict-origin-when-cross-origin`, a `Permissions-Policy` that turns off camera, mic and geolocation, `X-Content-Type-Options: nosniff`, and cookies `Secure; HttpOnly; SameSite=Lax`.
- **Uploads:** see ADR-0011 (type allow-list, re-encode, size limit).

### Consequences

- Good: a DB or backup leak does not expose integration credentials or MFA seeds.
- Good: logs can go to external tools.
- Bad: ticket text is protected only by access control, TLS and disk encryption. If the DB leaks, ticket text leaks. This is accepted for search. The mitigations are network isolation, least-privilege DB roles and encrypted backups.
- Bad: redaction can hide useful debug info. We use ids and correlation instead.
- Neutral: key rotation is a documented runbook (operations.md).

## Confirmation

- Unit tests: encrypt/decrypt round trip, wrong key id → error, rotation re-encrypt job.
- Test: a log capture during a failing request that contains a ticket body shows `[redacted]`.
- CI: secret scanning on every push. A grep check forbids `dump(`/`var_dump(` in `src/`.
- Response header test for CSP, HSTS and the other headers on HTML, API and MCP routes.
- Phase 12: SSL Labs-style check (A or better) on the staging host. Restore a backup and confirm credentials are still unreadable without the key.

## Pros and cons of the options

### 1. Disk + transport only

- Good, because it is simple.
- Bad, because a DB dump or backup exposes all credentials.

### 2. Plus field encryption for credentials and redaction

- Good, because it protects the highest-value secrets and keeps search working.
- Bad, because there is key management work.

### 3. Encrypt all content

- Good, because a DB leak shows nothing.
- Bad, because there is no FTS, no duplicate detection, slow lists, and complex key handling per org. Too costly for this stage.

## More information

- Related: [ADR-0011](0011-screenshot-storage-and-processing.md), [ADR-0012](0012-authentication.md), [ADR-0019](0019-browser-local-ai.md), [ADR-0021](0021-mcp-server.md), [ADR-0025](0025-jira-integration.md), [ADR-0026](0026-audit-log.md), [ADR-0029](0029-observability.md).
- Architecture: [security.md](../02-architecture/security.md), [operations.md](../02-architecture/operations.md).
- Revisit when a customer requires customer-managed keys (BYOK) or per-org encryption of content. That needs a new ADR with a search strategy.

## Clarifications (2026-11-08)

The decision is unchanged; these lines record how P01.T6 built field encryption.

- **Stored format.** `key_id || nonce || ciphertext` is one byte with the key id's length (1 to 32), the key id (letters, digits, `-`, `_`), the 24-byte random nonce, then the secretbox output (the plaintext plus a 16-byte tag). The code is SharedKernel's `FieldEncryption` interface and `SodiumFieldEncryption`.
- **Keys.** The key ring comes from two environment variables, `FIELD_ENCRYPTION_KEYS` (a comma list of `<id>:<base64 32-byte key>`) and `FIELD_ENCRYPTION_CURRENT_KEY_ID` ([config.md](../operations/config.md#field-encryption)). Of the two sources the decision allows, prod uses the orchestrator's secret manager: both must be plain environment variables injected from the platform's secret store, not Symfony secrets, because the prod entrypoint guard reads only the process environment (the same rule as `APP_SECRET`). They are read on the first encrypt or decrypt, so the prod cache warm-up needs no key. Dev and test use throwaway keys with `change-me` ids in `.env.dev` and `.env.test`; prod never loads those files, and the prod entrypoint refuses to start when either variable is empty, holds a `change-me` id, or holds one of those committed keys under any id (their base64 starts with `Y2hhbmdlLW1l`, the base64 of "change-me").
- **Errors.** A value cut short, a key id not in the ring, or a failed tag check throws `UndecryptableField`; nothing returns partial or wrong plaintext. Its message names at most a valid key id.
- **Not built yet:** the background job that re-encrypts old rows. The "wrong key id → error" and round-trip unit tests exist now; the "rotation re-encrypt job" test comes with that job.
