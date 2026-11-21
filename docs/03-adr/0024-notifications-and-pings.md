# ADR-0024: Pings are rate-limited, grouped and cleared automatically when the person acts

A ping is a gentle "please look". It cannot become spam, and it goes away by itself once the person has acted.

## Index

- [Status and people](#status-and-people): status, date, deciders.
- [Context and problem statement](#context-and-problem-statement): the scene: ping fatigue.
- [Decision drivers](#decision-drivers): signal over noise, fairness, control.
- [Considered options](#considered-options): free messages, rate-limited pings, no pings.
- [Decision outcome](#decision-outcome): the ping model, limits, grouping, auto-clear, quiet hours, channels.
  - [Consequences](#consequences): good, bad and neutral effects.
- [Confirmation](#confirmation): tests and metrics.
- [Pros and cons of the options](#pros-and-cons-of-the-options): details per option.
- [More information](#more-information): links and when to revisit.

## Status and people

- Status: Accepted
- Date: 2026-11-06
- Deciders: Zairan Mir (lead)

## Context and problem statement

Mia is blocked on SHOP-42 until Lena answers a question. In one hour she pings Lena three times: "Any news?", "Round 2 is blocked", "?". Lena has eleven pings from four people and cannot see what matters. After Lena answers, the three pings still sit in her bell. Lena starts ignoring the bell.

## Decision drivers

- A ping must stay a strong signal.
- One place to see pings, grouped so 11 pings read as 4 things.
- Pings disappear when the reason is gone.
- Admins set limits for the whole org.
- No pings in the night unless the org wants them.

## Considered options

1. **Free messages** (chat-like, no limits).
2. **Rate-limited pings with grouping and auto-clear (chosen).**
3. **No pings.** Only the "Needs you" list.

## Decision outcome

Chosen option: **2**.

- **Model:** `ntf_ping` (org, ticket, from_user, to_user, body up to 500 characters, created_at, cleared_at, cleared_reason). A ping is always about one ticket and one person on it.
- **Rate limit:** one ping per sender → recipient → ticket every N hours. N is an org setting: 1, 4 (default), 8 or 24. It uses Symfony RateLimiter (fixed window) in Valkey, keyed `ping:{org}:{from}:{to}:{ticket}` (ADR-0010). The UI shows "You can ping again after N hours" and disables the button. The server enforces the limit. There is also an org-wide cap per sender (for example 30 pings per day) against abuse.
- **Who can be pinged:** people listed on the ticket (assignee, reporter, reviewer, tester, asked person), and only if they can view the ticket. No pings to external systems (for example a Zendesk user).
- **Grouping:** the bell and Home show pings grouped by (sender, ticket), with a count ("3 pings"), the latest text and the time. Sort order: groups with more pings first, then newest.
- **Auto-clear:** when the recipient acts on that ticket, all their open pings on that ticket are cleared with reason `acted`. Acting means: a move, an answer, a comment, a verdict or a check. An org setting can turn auto-clear off. A manual "Done" clears with reason `dismissed`.
- **Quiet hours:** an org setting (for example 18:00–09:00 in the recipient's time zone). Pings made in that time are stored but delivered (bell badge, email, push) at the end of quiet hours. In-app lists still show them when the recipient opens pinround.
- **Channels:** in-app (bell, Home tray, Mercure push to the user's private topic, ADR-0023) and an email digest (at most one every 3 hours, only for unread items older than 1 hour, never during quiet hours). Teams and Slack come later through the same notification interface.
- **Mute:** a user can mute updates for a ticket they reported (My reports). Muting stops notifications, not pings addressed directly to them.

### Consequences

- Good: the bell stays meaningful, and the "pinged" state is visible to the sender.
- Good: cleanup happens without effort.
- Bad: an urgent second ping inside the window is blocked. The sender can write a question or comment instead.
- Neutral: rate-limit state lives in Valkey. Losing it only resets windows, which is acceptable.

## Confirmation

- Unit tests: limiter key, window, org cap. Auto-clear on each acting event type. Quiet-hours delivery time across time zones and daylight-saving changes.
- Integration test: a ping to a user who cannot view the ticket → 403. A ping to an external reporter → not offered.
- Metric: share of pings cleared by `acted` within 24 h (target above 60%). Average pings per user per day (alert above 20).

## Pros and cons of the options

### 1. Free messages

- Good, because it is flexible.
- Bad, because it creates noise, and it is a chat tool, which is not our job.

### 2. Rate-limited pings

- Good, because they stay strong signals and need little upkeep.
- Bad, because people must learn the limit. The UI says it plainly.

### 3. No pings

- Good, because there is zero noise.
- Bad, because blocked people have no polite way to nudge.

## More information

- Related: [ADR-0010](0010-cache-sessions-locks.md), [ADR-0023](0023-realtime-mercure.md), [ADR-0015](0015-turn-read-model.md).
- Architecture: [realtime-and-notifications.md](../02-architecture/realtime-and-notifications.md).
- Revisit when more than 10% of pings are blocked by the limit (the default is too strict), or users ask for Teams or Slack delivery.
