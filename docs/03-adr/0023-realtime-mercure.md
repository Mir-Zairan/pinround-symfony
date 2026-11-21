# ADR-0023: Live updates use Mercure with private topics, and restricted content is never published to shared topics

Pages update by themselves when something changes. A live update never shows anything the viewer could not see by reloading the page.

## Index

- [Status and people](#status-and-people): status, date, deciders.
- [Context and problem statement](#context-and-problem-statement): the scene and the leak risk.
- [Decision drivers](#decision-drivers): freshness, safety, simplicity.
- [Considered options](#considered-options): Mercure, WebSockets server, polling.
- [Decision outcome](#decision-outcome): topics, authorization, payload rules, fallbacks.
  - [Consequences](#consequences): good, bad and neutral effects.
- [Confirmation](#confirmation): tests.
- [Pros and cons of the options](#pros-and-cons-of-the-options): details per option.
- [Clarifications (2026-11-06)](#clarifications-2026-11-06) — same decision, details made explicit.
- [More information](#more-information): links and when to revisit.

## Status and people

- Status: Accepted
- Date: 2026-11-06
- Deciders: Zairan Mir (lead)

## Context and problem statement

Mia has SHOP-42 open. Omar sends it to testing. Mia's "Your next step" box should change to "Test it" without a reload, and her bell should show the new ping. At the same time Raj, a manager outside the team, has the Tickets list open. SHOP-51 is a restricted security ticket (`visibility = team`). If its title were pushed to a topic everyone in the org listens to, Raj's browser would receive it even if the UI hides it.

## Decision drivers

- Updates within 2 seconds for ticket pages, the bell and Home counts.
- Never deliver restricted content to an unauthorized browser, not even hidden in the DOM or network traffic.
- Works with Turbo Streams and server-rendered HTML (ADR-0007).
- No extra service for self-hosting if possible.

## Considered options

1. **Mercure hub (built into FrankenPHP/Caddy) with private topics (chosen).**
2. **Own WebSocket server** (for example a separate Node or Go service).
3. **Polling** every N seconds.

## Decision outcome

Chosen option: **1**.

- **Hub:** the Mercure module of FrankenPHP/Caddy (ADR-0008). For SaaS scale, a standalone or managed hub can be swapped in by configuration. Check the hub high-availability options at implementation time.
- **Topics** (all private, all prefixed with the org id):
  - `/o/{org}/u/{user}`: the user's bell, Home counts and personal items. Only this user.
  - `/o/{org}/t/{ticket}`: ticket page changes. Subscribe allowed only if voter VIEW passes when the cookie is issued.
  - `/o/{org}/team/{team}/lists`: "something changed in this team's list" signals.
- **Authorization:** the subscriber JWT (in an HttpOnly cookie, scoped path) lists exactly the topics the user may hear. It is issued per page view, lives 10 minutes, and is renewed every 8 minutes through a session-checked endpoint (see Clarifications).
- **Payload rules:**
  - Ticket topics carry **signals only** too (ticket id + what changed, no titles, text or names). The browser re-fetches the changed Turbo Frame over HTTP, which runs the voters.
  - List and team topics carry **signals only** (ticket id + "changed"). The browser then re-fetches the fragment through a normal HTTP request, which runs the voters.
  - Content (for example a bell entry text or a rendered fragment) is sent **only** on the per-user topic `/o/{org}/u/{user}`, rendered for that user after a voter check.
  - Restricted tickets never publish to team topics. Their changes are sent per user to each authorized user's private topic.
  - Draft rounds (ADR-0017) only publish to the owner's user topic.
- **Visibility changes:** when a ticket becomes restricted, a "revoke" signal is sent and the server stops including that topic in new JWTs. Already open subscriptions may still receive updates until their JWT expires (max 1 hour). To prevent that, restricted tickets switch to per-user publishing at once.
- **Publishing:** from the outbox relay after commit (ADR-0009), so we never publish data that was rolled back.
- **Fallback:** if the hub is unreachable, pages still work. Counts refresh on navigation.

### Consequences

- Good: live updates without a separate service, and they fit Turbo Streams.
- Good: the leak risk is handled by design (signals + re-fetch through voters).
- Bad: signals cause extra HTTP requests. They are small, cacheable fragments.
- Bad: per-user publishing for restricted tickets costs more publishes. This is acceptable, because restricted tickets are rare.
- Neutral: we must size the hub (connections ≈ online users) for SaaS.

## Confirmation

- Test: the publish call for a restricted ticket targets only user topics of authorized users (spy on the hub publisher).
- Test: the JWT for a user outside the team does not include `/o/{org}/t/{restricted}`.
- Test: a team list topic payload contains no title or body (schema check: id + type only).
- Playwright: two browsers. A move in one updates the other's next-step box within 2 s.

## Pros and cons of the options

### 1. Mercure

- Good, because it is native to the Symfony ecosystem, has JWT-based topic authorization, and is built into our runtime.
- Bad, because topic design must be careful (this ADR).

### 2. Own WebSocket server

- Good, because it is flexible in both directions.
- Bad, because it is a new service, new auth code and new scaling work.

### 3. Polling

- Good, because it is simplest and leak-safe through normal voters.
- Bad, because it adds load (users × pages × interval) and updates are slow.

## Clarifications (2026-11-06)

- No topic except the per-user topic ever carries ticket content. Ticket and team topics carry refresh signals only. This keeps a stale subscription (JWT up to 10 minutes) from receiving anything a user may no longer see.

- **Short JWT.** The subscriber JWT lives 10 minutes. `POST /realtime/token` renews it after checking the session and current memberships; after logout or removal it fails, so access ends within 10 minutes.
- **One shared hub for SaaS.** The hub built into FrankenPHP is used when there is one app container (self-host). With several app containers, each built-in hub would see only its own subscribers, so SaaS runs one standalone hub service that all containers publish to.
- **Coalescing.** Signals are coalesced per topic for about 2 seconds; browsers refetch with 0–1.5 s jitter and only while the page is visible.
- **Visibility.** Content on per-user topics follows the canonical [ticket visibility predicate](../02-architecture/data-model.md#ticket-visibility-predicate) at publish time.

## More information

- Related: [ADR-0007](0007-server-rendered-ui.md), [ADR-0008](0008-frankenphp-runtime.md), [ADR-0009](0009-async-messaging-and-outbox.md), [ADR-0013](0013-authorization.md), [ADR-0024](0024-notifications-and-pings.md).
- Architecture: [realtime-and-notifications.md](../02-architecture/realtime-and-notifications.md).
- Revisit when concurrent subscribers exceed 10,000 on one hub, or when the publish p95 delay exceeds 2 s.
