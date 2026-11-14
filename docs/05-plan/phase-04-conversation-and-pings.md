# Phase 04 — Conversation and pings

Goal: people talk on a ticket without losing the thread: comments, questions that pause the ticket, one-level replies, resolve, rate-limited pings, a calm bell, live updates and a safety-net email digest.

## Index
- [Before you start](#before-you-start) — prototype screens, requirements, ADRs, docs
- [Outcome](#outcome) — what works after this phase
- [Scope](#scope) — what this phase builds
- [Out of scope](#out-of-scope) — what waits
- [Requirements covered](#requirements-covered) — FR and NFR ids
- [Design notes](#design-notes) — tables, events, topics, limits
- [Tasks](#tasks) — P04.T1 to P04.T8
- [Definition of done](#definition-of-done) — phase checklist
- [Hand-off notes](#hand-off-notes) — notes for P05–P07

## Before you start
1. **Prototype.** Open https://claude.ai/artifact/FmbJosB3s5mDi75cLqMFQ2 (Artifact tool, `action: "read"`, `path: "project/Main.dc.html"`). On a ticket: write a comment, switch to Question, pick a person, answer as that person (use "view as"), reply, resolve. Open the bell, use "Done". Ping someone from the People list. Fallback docs: [Questions and comments](../04-prototype/screens/ticket-page.md#questions-and-comments), [People, What happened, Details](../04-prototype/screens/ticket-page.md#people-what-happened-details), [Pings popover (bell)](../04-prototype/screens/dialogs-and-popovers.md#pings-popover-bell), [Ping sheet](../04-prototype/screens/dialogs-and-popovers.md#ping-sheet), [Home: right column](../04-prototype/screens/home-work.md#right-column) (pings for you), [Admin: pings](../04-prototype/screens/admin.md#pings).
2. **Requirements:** [FR-CNV-01, FR-CNV-02, FR-CNV-03, FR-CNV-04, FR-CNV-05, FR-CNV-06, FR-CNV-07, FR-CNV-08](../01-requirements/functional-requirements.md#cnv--conversation), [FR-PNG-01, FR-PNG-02, FR-PNG-03, FR-PNG-04, FR-PNG-05, FR-PNG-06, FR-PNG-07, FR-PNG-08, FR-PNG-09, FR-PNG-10, FR-PNG-11](../01-requirements/functional-requirements.md#png--pings-and-notifications), [FR-WF-12, FR-WF-17](../01-requirements/functional-requirements.md#wf--workflow-steps-and-turns), [NFR-SEC](../01-requirements/non-functional-requirements.md#sec--security) NFR-SEC-09, NFR-SEC-13, NFR-SEC-14, [NFR-PRIV-02](../01-requirements/non-functional-requirements.md#priv--privacy).
3. **ADRs:** [0023 Mercure](../03-adr/0023-realtime-mercure.md), [0024 notifications and pings](../03-adr/0024-notifications-and-pings.md), [0009 outbox](../03-adr/0009-async-messaging-and-outbox.md), [0010 rate limits in Valkey](../03-adr/0010-cache-sessions-locks.md), [0015 turn read model](../03-adr/0015-turn-read-model.md).
4. **Architecture docs:** [realtime and notifications](../02-architecture/realtime-and-notifications.md) — all sections, especially [Mercure topics](../02-architecture/realtime-and-notifications.md#mercure-topics), [notification kinds](../02-architecture/realtime-and-notifications.md#notification-kinds), [ping lifecycle](../02-architecture/realtime-and-notifications.md#ping-lifecycle), [the bell](../02-architecture/realtime-and-notifications.md#the-bell), [email digest](../02-architecture/realtime-and-notifications.md#email-digest), [failure handling](../02-architecture/realtime-and-notifications.md#failure-handling); [questions: pause and resume](../02-architecture/workflow-engine.md#questions-pause-and-resume); [Conversation module](../02-architecture/modules.md#conversation-cnv_), [Notification module](../02-architecture/modules.md#notification-ntf_); tables [Conversation](../02-architecture/data-model.md#conversation) and [Notification](../02-architecture/data-model.md#notification-assist-integration-audit-agentapi).
5. **Previous phase:** check the [P03 Definition of done](phase-03-tickets-core.md#definition-of-done).

## Outcome
Omar asks Lena "Should cancelled orders count?". The ticket turns amber: "Waiting · question to Lena"; no move buttons show, the box says "Paused until Lena answers the question below." Lena answers; the ticket goes back to In progress and Omar gets one notification. Mia pings Omar once; the button then says "Pinged · again in 4 h". When Omar marks his work, Mia's ping clears by itself and the toast says "2 pings on this ticket cleared." Open pages update live. People away for a while get one short email with keys and titles only.

## Scope
- Comments, questions (one open per ticket, only to people on the ticket), answers, one-level replies, resolve, edit (15 min) and delete.
- Pings with rate limits, grouping, bell, Done, auto-clear, quiet hours, mute.
- Notification records for the kinds in the architecture doc.
- Mercure private topics and Turbo Streams; content-free ticket topics.
- Email digest.

## Out of scope
- "Pings for you" on Home and the nav badge for My reports (P07 uses the queries built here).
- Ping from My reports cards (P07), Slack/Teams (later).

## Requirements covered
FR-CNV-01, FR-CNV-02, FR-CNV-03, FR-CNV-04, FR-CNV-05, FR-CNV-06, FR-CNV-07, FR-CNV-08, FR-PNG-01, FR-PNG-02, FR-PNG-03, FR-PNG-04, FR-PNG-05, FR-PNG-06, FR-PNG-07, FR-PNG-08, FR-PNG-09, FR-PNG-10, FR-PNG-11, FR-WF-12, FR-WF-17 (ping clearing part), NFR-SEC-09, NFR-SEC-13, NFR-SEC-14, NFR-PRIV-02, NFR-AVL-05 (pings fail closed when Valkey is down).

## Design notes
- **Tables:** `cnv_message` (kind comment/question, `to_user_id`, `resolved_at`), `cnv_reply` (`is_answer`), `ntf_ping`, `ntf_notification` (monthly partitions, 90 days), `ntf_mute`, `ntf_delivery`. All with RLS.
- **Question = pause, not a move.** The ticket keeps its step. Conversation calls `Insight\Api\TurnProjector::refresh(TicketId)` in the same transaction so the turn becomes the asked person; answering refreshes it back ([questions: pause and resume](../02-architecture/workflow-engine.md#questions-pause-and-resume)).
- **Events:** `CommentPosted`, `QuestionAsked`, `QuestionAnswered`, `ReplyPosted`, `CommentResolved`, `PingSent`, `PingCleared`.
- **Rate limit key:** `ping:{org}:{from}:{to}:{ticket}`, fixed window of N hours from `org_settings.ping_limit_hours`. If Valkey is down, pinging is refused with a plain message (fail closed).
- **Mercure:** user topic `…/u/{userId}` carries Turbo Streams with content for that user only; ticket topic `…/t/{ticketId}` carries only "refresh" signals, so a user who lost access never gets content ([Mercure topics](../02-architecture/realtime-and-notifications.md#mercure-topics)). Publishing happens in outbox handlers, never in the web request.
- **Safe text:** plain text, links made clickable server-side, everything HTML-escaped (FR-CNV-07).
- **Email:** no descriptions, comments or AI text, ever; restricted tickets have no title in email.

## Tasks

### P04.T1 — Comments and safe text
**Why:** talk must stay next to the work ([FR-CNV-01, FR-CNV-06, FR-CNV-07](../01-requirements/functional-requirements.md#cnv--conversation)).
**Steps:**
1. Conversation domain `Message` (comment), repository, migration `cnv_message` with RLS and indexes.
2. Composer on the ticket page with the Comment / Question switch (Question disabled until P04.T2), note "A comment does not change the status and sends no notification."
3. Thread rendering: author, time, text with linkified URLs (server-side, `rel="noopener noreferrer"`), newest last.
4. Edit within 15 minutes ("edited" mark); delete leaves "Message removed".
5. Voter `COMMENT` (anyone who can view; admins without team role too, per the permission matrix).
**Files:** `src/Conversation/**`, `src/Conversation/UI/Component/{Thread,Composer,Message}.php`.
**Acceptance criteria:**
- [ ] `<script>` in a comment is shown as text.
- [ ] Editing after 15 minutes is refused.
- [ ] A user who cannot see the ticket cannot post (404).
- [ ] An admin without a team role can post a comment on any ticket they can see (voter row).
**Tests:** unit (linkify, escape), functional (post, edit, delete), voter, isolation.
**Prototype check:** composer layout and the note text in [Questions and comments](../04-prototype/screens/ticket-page.md#questions-and-comments).
**Size:** M

### P04.T2 — Questions: pause and resume
**Why:** a question blocks work and must have an owner ([FR-CNV-02, FR-CNV-03, FR-CNV-08](../01-requirements/functional-requirements.md#cnv--conversation), [FR-WF-12](../01-requirements/functional-requirements.md#wf--workflow-steps-and-turns)).
**Steps:**
1. `AskQuestion`: only to people listed on the ticket (assignee, reporter, reviewer, tester, lead); one open question per ticket; note "A question pauses the ticket until Lena answers. They get one notification."
2. Turn reprojection to the asked person; status pill "Waiting · question to Lena" in waiting colours; no verbs; rule note "Paused until Lena answers the question below." (from `TransitionPolicy`).
3. `Answer`: the asked person's reply (`is_answer`), resolves the question, reprojects the turn to the step's normal owner; toast "Answered. The ticket goes back to In progress."
4. "Answer" button on the question for the asked person; the reply box hint "Your answer" and the note "Your answer closes the question. The ticket goes back to In progress."
5. Notification kinds `question` (to asked) and `answer` (to asker).
**Files:** `src/Conversation/Application/{AskQuestion,Answer}/*`.
**Acceptance criteria:**
- [ ] A second open question on the same ticket is refused with "This ticket already waits for an answer."
- [ ] The step does not change while paused; Now says "Lena answers Omar's question".
- [ ] Answering returns the turn and clears the paused state in one transaction.
- [ ] An admin without a team role can ask a question (voter row) but still gets no move verbs.
**Tests:** unit (pause rules), integration (turn projection), functional journey ask → answer, Playwright with two users.
**Prototype check:** [Questions and comments](../04-prototype/screens/ticket-page.md#questions-and-comments), [Now and Next texts](../04-prototype/screens/ticket-page.md#now-and-next-texts) for the paused state.
**Size:** M

### P04.T3 — Replies and resolve
**Why:** threads stay readable: one level of replies, resolved threads fold away ([FR-CNV-04, FR-CNV-05](../01-requirements/functional-requirements.md#cnv--conversation)).
**Steps:**
1. `cnv_reply` table; replies only under a top message (no reply to a reply).
2. "Reply" opens an inline box; the message author gets one notification per reply batch (debounced 2 minutes).
3. "Resolve" for comments only, by the author or a lead; folds to "Resolved: … · 2 replies", openable by anyone.
**Files:** `src/Conversation/Application/{Reply,Resolve}/*`.
**Acceptance criteria:**
- [ ] A reply to a reply is not possible (no button, API refuses).
- [ ] Questions cannot be resolved by "Resolve" (only by an answer).
**Tests:** unit, functional, voter rows for resolve.
**Prototype check:** reply and resolve states in [Questions and comments](../04-prototype/screens/ticket-page.md#questions-and-comments).
**Size:** S

### P04.T4 — Pings with rate limits
**Why:** a nudge that cannot become spam ([FR-PNG-01, FR-PNG-02, FR-PNG-11](../01-requirements/functional-requirements.md#png--pings-and-notifications)).
**Steps:**
1. `ntf_ping` table; `PingCommands::ping` with the checks from the [ping lifecycle](../02-architecture/realtime-and-notifications.md#ping-lifecycle) (both can view, not self, body 1–500 chars).
2. RateLimiter in `valkey-state`; limit from org settings (1/4/8/24 h, default 4).
3. Ping sheet dialog: prefilled "Hi Omar, could you look at SHOP-88? Now: Omar builds it." and the note "Omar gets one notification. You can ping again after 4 hours (set by your admin)."
4. People list: Ping button; after a ping "Pinged · again in 4 h"; no Ping for integration users and label waiters (Customer, Product owner, Technical owner).
5. Valkey down → "Pings are paused for a moment. Try again soon." (fail closed).
**Files:** `src/Notification/**`, `src/Notification/UI/Component/PingSheet.php`.
**Acceptance criteria:**
- [ ] A second ping within the window is refused with the time it becomes possible.
- [ ] Pinging someone who cannot see the ticket is refused.
- [ ] An admin without a team role can ping (voter row).
- [ ] With `valkey-state` stopped, a ping is refused with "Pings are paused for a moment. Try again soon." and nothing is stored (fail closed, NFR-AVL-05).
**Tests:** unit (limit key, body rules), integration with Valkey (window), functional, a test with Valkey stopped.
**Prototype check:** [Ping sheet](../04-prototype/screens/dialogs-and-popovers.md#ping-sheet) texts; People list button states.
**Size:** M

### P04.T5 — Bell, grouping and Done
**Why:** one calm place for what was sent to you ([FR-PNG-03, FR-PNG-04, FR-PNG-07](../01-requirements/functional-requirements.md#png--pings-and-notifications)).
**Steps:**
1. `ntf_notification` (partitioned) and handlers that create notifications for the kinds in [notification kinds](../02-architecture/realtime-and-notifications.md#notification-kinds) (actor never notifies themselves; muted tickets skipped; restricted tickets re-checked at delivery).
2. `NotificationQueries::bell(UserId)`: groups by sender + ticket ("Omar · 2 pings · SHOP-412", newest text, time of latest), sorted by size then age; counts cached 60 s per user and invalidated on change.
3. Bell button with badge; popover with groups, "Done" and "Open" (opens the ticket and marks the group read).
**Files:** `src/Notification/Application/*`, `src/Notification/UI/Component/{Bell,BellPopover,PingCard}.php`.
**Acceptance criteria:**
- [ ] Three pings from Mia on one ticket show as one group "3 pings".
- [ ] "Done" clears the group and the badge count drops.
- [ ] The bell query uses one indexed query (EXPLAIN check).
- [ ] A notification about a team-only ticket is dropped at delivery for a user who lost access (case added to the visibility leak suite from P03.T2).
**Tests:** integration (grouping, sorting), functional, Playwright bell open/Done.
**Prototype check:** [Pings popover (bell)](../04-prototype/screens/dialogs-and-popovers.md#pings-popover-bell): header text, group card layout, buttons.
**Size:** M

### P04.T6 — Auto-clear, quiet hours and mute
**Why:** pings vanish when you act; nobody gets pinged at night ([FR-PNG-05, FR-PNG-06, FR-PNG-10](../01-requirements/functional-requirements.md#png--pings-and-notifications), [FR-WF-17](../01-requirements/functional-requirements.md#wf--workflow-steps-and-turns)).
**Steps:**
1. Auto-clear handler: on move, comment, reply, answer, verdict, check action or assignment by the recipient, clear their open pings on that ticket (`cleared_reason = acted`) when the org switch is on.
2. The one action path: the action's toast gets the suffix "2 pings on this ticket cleared." Do this inside the request (count before commit) so the toast is right.
3. Quiet hours (org switch, 18:00–09:00 in the recipient's time zone): `deliver_after` set; a scheduler job every 5 minutes releases held items.
4. Mute per ticket (`ntf_mute`): no notifications, still in lists.
**Files:** `src/Notification/Application/{AutoClear,ReleaseHeld,Mute}/*`, scheduler entry.
**Acceptance criteria:**
- [ ] Omar approving code clears his pings on that ticket and the toast says how many.
- [ ] A ping at 22:00 recipient time appears at 09:00.
- [ ] Muted tickets create no notification.
**Tests:** integration with a frozen `Clock`; functional toast test.
**Prototype check:** toast suffix in [Toast](../04-prototype/screens/dialogs-and-popovers.md#toast); admin switch labels in [Admin: pings](../04-prototype/screens/admin.md#pings).
**Size:** M

### P04.T7 — Live updates with Mercure
**Why:** open pages stay current without reloads ([FR-PNG-08](../01-requirements/functional-requirements.md#png--pings-and-notifications), [ADR-0023](../03-adr/0023-realtime-mercure.md)).
**Steps:**
1. Mercure hub: the FrankenPHP built-in hub for local and single-node self-host; for SaaS with 2+ app containers, one shared hub service, because built-in hubs in several containers split the subscribers. Publisher JWT secret from env.
2. Subscriber JWT: scoped to `/o/{orgId}/u/{userId}` and the ticket topics the voter allowed at render time; valid **10 minutes**; renewed by `POST /realtime/token`, which checks the session and the voter again, so a logged-out or removed user loses the stream within 10 minutes. Cookie `Secure`, `HttpOnly`, `SameSite=Strict`, path-limited.
3. Outbox handlers publish: per-user Turbo Streams (bell count, bell items, toasts for the user) and content-free `refresh` signals on ticket topics.
4. Coalescing: at most one ticket-topic signal per ticket per ~2 s; the browser re-fetches frames through the voter with 0–1 s random jitter, and only when the page is visible (`document.visibilityState`).
5. Graceful fallback: without Mercure the app works; a "refresh" link appears after 60 s of missed heartbeats.
**Files:** `src/Notification/Infrastructure/Mercure/*`, `src/Notification/UI/Controller/RealtimeTokenController.php`, `@Ticketing/show.html.twig` (`src/Ticketing/UI/templates/show.html.twig`, stream source), FrankenPHP config.
**Acceptance criteria:**
- [ ] Two browsers on one ticket: a move in one updates the other within 3 s.
- [ ] A user whose access was removed gets only a refresh and then a 404 — no content; after logout, the token renewal fails and the stream stops within 10 minutes.
- [ ] Ten moves in one second on one ticket produce at most one refresh per ~2 s per subscriber.
- [ ] No Mercure publish happens inside the web request (test via a spy).
**Tests:** integration tests for topic authorization, token renewal and payloads; Playwright two-context test.
**Security notes:** never put ticket text on a ticket topic.
**Prototype check:** none (the prototype has no live updates).
**Size:** M

### P04.T8 — Email digest
**Why:** a safety net for people away from the screen ([FR-PNG-09](../01-requirements/functional-requirements.md#png--pings-and-notifications)).
**Steps:**
1. Scheduler job every 15 minutes: unread bell items older than 1 hour; at most one digest per user per 3 hours; never in quiet hours.
2. Email "3 things wait on you in pinround": person, kind, ticket key, title (restricted tickets: "a restricted ticket in team Shop"), link. No descriptions, comments or AI text.
3. Sent through the `mail` transport, idempotent per (user, window); unsubscribe link to profile settings.
**Files:** `src/Notification/Application/SendDigest/*`, email templates.
**Acceptance criteria:**
- [ ] A restricted ticket's title never appears in any email (test with a marker title).
- [ ] Running the job twice in one window sends one email.
**Tests:** integration with Mailpit/mailer test transport; marker test (added to the visibility leak suite).
**Prototype check:** none (the prototype has no email).
**Size:** S

## Definition of done
- [ ] All tasks ticked; ask → answer and ping → auto-clear journeys pass in Playwright.
- [ ] Isolation, voter and visibility tests for every new table and action; marker tests for logs and email.
- [ ] Mercure topics carry no content on ticket topics.
- [ ] Texts match the prototype (composer notes, ping sheet, bell, toasts).
- [ ] Global [Definition of done](README.md#definition-of-done-for-every-task) met for every task.

## Hand-off notes
- P07 builds "Pings for you" (top 3 + "Show N more") and the My reports inline answer on top of `NotificationQueries::bell` and `Answer`.
- P05 and P06 call the auto-clear path for verdicts and check actions.
- (add dated notes here)
- 2026-11-14 (P04.T5, partitions): `AddNotificationPartitions` (current UTC month + 3) and `DropExpiredNotificationPartitions` (drops each `ntf_notification_YYYYMM` whose whole month ended ≥ 90 days ago) run daily on the `default` schedule; readiness check `next_notification_partition`. Both are `#[WhenNot('prod')]` on the migrator connection, like `AddTicketEventPartitions`: prod has the migration's partitions through 2027-12 and keeps every notification until it gets a `pinround_migrator` runner. **Deadline: before 2027-12**, or `/health/ready` fails and from 2028-01 every notification insert is refused. The drop uses plain `DROP TABLE` in the locked transaction (`DETACH … CONCURRENTLY` cannot run inside one), so it holds `ACCESS EXCLUSIVE` on `ntf_notification` for the drop, bounded by a 5 s `lock_timeout`. `data-model.md` lines on "the one partition job so far" still name only the ticket job.
- 2026-11-14 (P04.T7a-1, hub audience): Mercure hub v1.1.6 checks every token's `aud` against its resource identifier, which it derives per request unless pinned; both the workers and the proxied browsers reach it as `http://app`, so it refused the app's `aud=https://localhost`. The Caddyfile now pins `resource_identifier {$MERCURE_PUBLIC_URL}` (no default: Caddy does not start without it) and `mercure.yaml` signs `aud` = `MERCURE_PUBLIC_URL`; compose passes it to the app container. The dev image copies the Caddyfile, so `docker compose up -d --build app` is needed for a restart to keep it. Protocol 1.0 subscribes with `?match=`, not `?topic=`.
- 2026-11-14 (P04.T4-B, SendPing): `PingCommands::ping` (`Application\Ping\SendPing`) refuses, before taking the window, with `PingToYourself`, `PingMessageRefused`, Ticketing's `TicketNotFound` (sender cannot see the ticket) and `PingRecipientCannotView` (not a member, or cannot see it); then `PingWindow::take`, `Pings::add`, `PingEvents::publish(PingSent)` in the caller's transaction. A failed add or publish releases the slot and rethrows; a commit that fails after `ping` returns does not, so the slot then holds until the limit ends. Quiet hours do not hold pings yet: `deliverAfter` is null and `PingSent.occurredAt` is `$at`, because the recipient's time zone is in Identity, which Notification may not use. Integration users and label waiters never appear as People rows, so no code refuses them.
- 2026-11-14 (P04.T7a-1, topics and token): `RealtimeTopics` (`Notification\Infrastructure\Mercure`) builds `https://pinround.app/o/{orgId}/t/{ticketId}` and `…/u/{userId}` from ids only. `RealtimeSubscriptions` (Application port, `MercureRealtimeSubscriptions`) sets the subscriber cookie with `iat`/`exp` from the app clock (10 min) and builds the subscribe URL (`?match=`). `POST /realtime/token` (`RealtimeTokenController`, form field `ticketIds[]`, at most 10, else 400 "Ask for live updates of at most 10 tickets at a time.") answers 204 with the cookie for the user's topic plus each id `TicketPermission::VIEW` still allows; malformed, unknown, other-org and hidden ids are left out. It sets no cookie at page render: the page gets one from its first renewal. **For the conductor:** add `{{ component('LiveUpdates', {ticketId: …}) }}` to `@Ticketing/show.html.twig` (ticket id, not key) and `{{ component('LiveUpdates') }}` to the shell layout; the `live-updates` Stimulus controller (renew every 8 min, EventSource on the subscribe URL, unhide the paused banner) is not built yet. The banner reads "Updates paused. Reload to see the latest." as ux-principles.md says, not "Live updates paused. Refresh".
- 2026-11-14 (P04.T5, notification handler): `NotifyRecipients` writes one `ntf_notification` per `PingSent`, `QuestionAsked`, `QuestionAnswered`, `ReplyPosted` and `TicketAssigned`. `data` holds the snapshot `actorName` and `ticketKey` plus the source ids: `pingId` (ping), `messageId` (question, answer, reply) and `replyId` (reply). `deliver_after` is set for pings only (to `PingSent.occurredAt`, the delivery time); every other kind leaves it null. A reply within 120 s (`NotifyRecipients::REPLY_FOLD_SECONDS`) of the unread `reply` notification for the same message folds into that row instead of adding one. `isMuted` is proven by `DbalNotificationQueriesTest`; delivery visibility by `NotificationDeliveryLeakTest`.
- 2026-11-14 (P04.T7a-2, refresh signals): worker-notify only: `QueueTicketPagesRefreshHandler` (event.bus, `TicketEvent`, `ConversationEvent`, `PingEvent`) queues `RefreshTicketPages` (async_notify); `RefreshTicketPagesHandler` publishes a private `{}` update, SSE type `refresh`, on the ticket topic when it takes `realtime-refresh:{ticketId}` (2 s, never released), else queues one delayed `Trailing` try (marker `realtime-refresh-trailing:{ticketId}`), which waits one more window (`LastTrailing`) if it meets a newer one, so the last change of a burst is never dropped. `assets/controllers/live-updates_controller.js` (identifier `live-updates`) POSTs the token on connect and every 8 min, refetches the `frame` value turbo-frame or the page via Turbo after 0–1 s jitter only while visible, and unhides the paused banner after 60 s without a connection. **T7b** adds the per-user streams and the page include lines (and a `frame` value if only a frame should reload).
- 2026-11-14 (P04.T1-B1, thread read path): `ThreadQueries::threadOf` (`DbalThreadQueries`) gives the newest `ThreadView::MESSAGE_LIMIT` (200) messages oldest first, ties on `created_at` by id, with `hasEarlierMessages` when more exist; one query on `cnv_message_ticket_id_created_at_id_idx`, read backwards with no sort. A removed message has a null `body` and `isRemoved`: the SQL selects `CASE WHEN removed_at IS NULL THEN body END`, so its text never leaves the database. `editableUntil` = `created_at` + `Message::EDIT_WINDOW_MINUTES`. It checks no visibility: callers pass a ticket the viewer may see. Twig filter `linkify` (`Conversation\UI\Linkify`) escapes the text, links http/https only (`rel="noopener noreferrer" target="_blank"`), keeps trailing punctuation outside the link and turns CRLF, CR and LF into `<br>`; it returns safe HTML, so use `{{ message.body|linkify }}` with no `|raw`. Proven by `DbalThreadQueriesTest`, `LinkifyTest` and `LinkifyFilterTest`.
