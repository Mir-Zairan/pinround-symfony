# ADR-0049 — A dev-only switch skips two-step login on a local demo

`PINROUND_DEMO_SKIP_2FA` lets a person trying pinround on their own machine open admin pages and make a token without
setting up two-step login. It is off by default, read only in `dev`, and the kernel refuses to boot with it anywhere
else.

## Index

- [Status](#status) — accepted, date, what it supersedes in ADR-0012.
- [Context and problem statement](#context-and-problem-statement) — Sara tries a local demo and hits two-step login first.
- [Decision drivers](#decision-drivers) — a quick demo, no weaker production, people still see two-step login.
- [Considered options](#considered-options) — a dev-only switch, a known demo secret, no exception.
- [Decision outcome](#decision-outcome) — the switch, where it is read, the boot check, the banner.
  - [Consequences](#consequences) — what gets easier and what it risks.
- [Confirmation](#confirmation) — the tests of P14.T18.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — the trade-offs.
- [Decided at acceptance](#decided-at-acceptance) — the product owner's answer on the audit entry.
- [More information](#more-information) — related ADRs, requirements, revisit triggers.

## Status

Accepted · 2026-11-21 · Deciders: Zairan Mir (lead, product owner).

It supersedes in part [ADR-0012](0012-authentication.md): the "no exception" rule, that admin pages and
token creation always need two-step login. It gets one exception, a local `dev` demo with this switch on. Every real
deployment keeps the rule, as ADR-0012's "not an exception to NFR-SEC-05 for any real deployment" says. Everything
else in ADR-0012 stands.
[P14.T18](../05-plan/phase-14-tester-feedback.md#p14t18--demo-switch-for-two-step-login) builds it.

## Context and problem statement

Sara, the admin at Acme Foods, wants to try pinround before she rolls it out. She runs it on her laptop with the demo
shop. To open Admin › People she must first turn on two-step login with an authenticator app. Omar, trying the
Claude Code connection on the same demo, must do the same before he can make a token. Both stop at a step that has
nothing to do with what they came to see.

The product owner wants two-step login to stay visible in the demo, so people know it exists, and wants a local demo
to be quick.

How does a local demo skip two-step login without any real deployment being able to?

## Decision drivers

- Admins and token creation need two-step login in every real deployment (NFR-SEC-05,
  [FR-ID-03](../01-requirements/functional-requirements.md#id--identity-and-access), [ADR-0012](0012-authentication.md)).
- Fail closed: a missing or wrong setting never turns the check off.
- A person trying a local demo reaches admin pages and tokens in minutes.
- People still see that two-step login exists (product owner, 2026-11-20).
- The check stays in one place, so skipping it cannot drift between pages.

## Considered options

1. **A dev-only switch.** `PINROUND_DEMO_SKIP_2FA`, off by default, read only in `dev`; the kernel refuses to boot
   with it set in any other environment.
2. **A known demo secret.** The demo seed gives Sara a fixed TOTP secret and prints the setup code, so she adds it to
   her authenticator app once.
3. **No exception.** Keep ADR-0012 as it is; the setup guide explains how to turn on two-step login.

## Decision outcome

Chosen option: **1, a dev-only switch**, because it makes a local demo quick, keeps two-step login on by default so
people see it, and cannot be turned on in a real deployment.

- **The switch.** `PINROUND_DEMO_SKIP_2FA`, a bool environment variable, default false. "Set" means a value Symfony's
  `bool` env processor reads as true; empty or unset is off.
- **Only in `dev`.** The kernel refuses to boot when the switch is true and `APP_ENV` is not `dev`, with a plain
  message. This covers `prod` and `test`.
- **Where it is read.** Only in Identity's `RecentAuth::hasConfirmedTwoStepLoginWithin`, the one place that decides
  whether a two-step proof counts. Every check of the proof goes through it, so none needs its own copy of the switch:
  - Organization's `AdminAreaVoter` asks Identity's `Api\LoginAssurance::hasConfirmedTwoStepLoginWithin`, which
    `SymfonyLoginAssurance` answers with `RecentAuth`.
  - `AskToConfirmItsYou` enforces `#[RequiresRecentAuth]` with `RecentAuth::isRecentFor`, which calls the same method.
    Token creation (`TokenController::create`) and the AI provider key change (`AiSettingsController::changeProviderKey`)
    carry that attribute.
  - `SendAdminToTwoStepProof` and `AskToConfirmItsYou`'s choice between setup and "Confirm it's you" run only after a
    refusal, so they never run while the switch lets the proof count.

  It skips the two-step proof only. It never grants a role, never skips the password session or `isRecentFor`'s
  recent password, and `ADMIN_*` voters still deny every request made with a token ([ADR-0013](0013-authorization.md)).
- **Visible while on.** Every page shows a banner: "Demo mode: two-step login is skipped."
- **Setup.** `.env` explains the switch, and the `pinround-setup` skill asks whether to turn it on.
- **No audit field.** An action taken with the switch on writes the same audit entry as any other; nothing records
  that two-step login was skipped, because the switch runs only on a local `dev` demo.

### Consequences

- Good: a local demo reaches admin pages and token creation without an authenticator app.
- Good: the default stays safe, and people still see two-step login unless they choose to skip it.
- Bad: a second code path around two-step login exists, and every new check of the two-step proof must go through
  `RecentAuth` (directly or through `LoginAssurance`), or it ignores the switch.
- Bad: a person who copies a `dev` setup to a server could run it in `dev` mode with the switch on; the boot check
  cannot catch a server that runs `APP_ENV=dev`. The banner and `.env` text say it is for a local demo only.
- Neutral: the same shape as the hackathon flag `APP_HACKATHON_SKIP_ADMIN_MFA` of ADR-0012, which was deleted on
  2026-11-08, but narrower: `dev` only, where that flag also ran in `test`.

## Confirmation

- A kernel boot test: with the switch on and `APP_ENV=prod` (and `test`), boot fails with the message.
- With the switch on in `dev`, Sara opens admin pages and makes a token without two-step login (P14.T18).
- With the switch off, every existing two-step login test still passes.
- Unit tests of `RecentAuth` with the switch on and off; functional tests through `AdminAreaVoter` and token creation
  with it on, where a person without the Admin role is still refused.

## Pros and cons of the options

### Option 1, a dev-only switch

- Good, because a demo needs no authenticator app, and the default still shows two-step login.
- Good, because the boot check keeps it out of `prod` and `test`.
- Bad, because it adds a path around a security check, which tests must keep narrow.

### Option 2, a known demo secret

- Good, because two-step login runs for real, and nothing is skipped.
- Bad, because the person still needs an authenticator app, which is the step that stops them.
- Bad, because a fixed secret in the repo is a known login for any demo that ends up reachable from outside.

### Option 3, no exception

- Good, because nothing changes and there is no new path around the check.
- Bad, because every demo starts with a setup step unrelated to what the person came to see, which the hands-on test
  of 2026-11-20 found.

## Decided at acceptance

- **Audit entry.** The product owner decided on 2026-11-21 that no audit entry records a skipped two-step login (the
  hackathon flag's planned `mfa_skipped: true` is not built): the switch runs only on a local `dev` demo, whose audit
  log no one reviews.

## More information

- Related: [ADR-0012](0012-authentication.md), [ADR-0013](0013-authorization.md),
  [ADR-0043](0043-api-token-hash-is-hmac-sha256.md).
- Requirements: FR-ID-03, FR-ID-05, NFR-SEC-05.
- Plan: [phase 14](../05-plan/phase-14-tester-feedback.md), P14.T18.
- Revisit when: the switch is found set on any server reachable from outside, or a hosted demo (not a laptop) is
  needed. Either needs a different answer than an environment switch.
