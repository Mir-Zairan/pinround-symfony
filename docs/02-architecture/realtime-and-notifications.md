# Real-time updates and notifications

How open pages stay fresh (Mercure), and how pings and notifications are created, limited, grouped, held at night, cleared and emailed.

## Index

- [The story](#the-story) — a ping, a quiet evening, and a ping that clears itself.
- [Principles](#principles) — the rules that keep notifications useful and safe.
- [Mercure topics](#mercure-topics) — topic names, what is pushed, and how access is checked.
- [What is pushed when](#what-is-pushed-when) — the event → push table.
- [Notification kinds](#notification-kinds) — which events notify whom.
- [Ping lifecycle](#ping-lifecycle) — create, rate limit, deliver or hold, group, clear.
- [The bell](#the-bell) — grouping and counts.
- [Email digest](#email-digest) — when email is sent, and what it contains.
- [Sequence diagrams](#sequence-diagrams) — ping with a rate limit and quiet hours, auto-clear, live ticket update.
- [Settings and storage](#settings-and-storage) — admin settings, tables, retention.
- [Failure handling](#failure-handling) — what happens when Mercure or email is down.

## The story

Omar pings Lena at 17:50: "Code review is ready when you are." Lena gets one notification, and her bell shows "Omar · pinged you". At 17:55 Omar tries again. pinround says: "You can ping Lena again at 21:50." At 18:30 Mia pings Lena about another ticket. Quiet hours have started, so the ping waits and arrives at 09:00. The next morning Lena approves Omar's code. Omar's ping disappears from her bell by itself, because she acted on that ticket.

No spam, no stale pings, and nobody has to clear the bell by hand.

## Principles

1. **The Home page is the to-do list; the bell is for people.** The bell shows pings and the few events where a person asked something of you. Home shows all your work.
2. **One notification per real event.** A question sends one notification. Moves notify only the person whose turn it becomes.
3. **Limits are kept on the server.**
   - One ping per sender, per recipient, per ticket, per N hours (the admin chooses 1, 4, 8 or 24).
   - Quiet hours hold pings until 09:00 in the recipient's time zone.
4. **Pings clear themselves** when the recipient acts on that ticket (admin switch "auto-clear", on by default).
5. **Never push what the viewer may not see.** Pushes carry either per-user content or a content-free "refresh" signal ([ADR-0023](../03-adr/0023-realtime-mercure.md)).
6. **Mutes win.** A user who mutes a ticket in My reports gets no notifications or emails about it. It still shows in their lists.

## Mercure topics

The Mercure hub is built into FrankenPHP ([ADR-0008](../03-adr/0008-frankenphp-runtime.md)). All topics are **private**: a subscriber needs a JWT that lists the topics it may read. That JWT is a short-lived (**10 minutes**) HttpOnly cookie, scoped to the hub path. Rendering a page sets no cookie: the `live-updates` Stimulus controller asks `POST /realtime/token` for it before every connect (the first one and each reopen after a dropped or refused stream, 5 s doubling to at most 60 s), and again every 8 minutes. All tabs of a browser share that one cookie and the hub reads it only as a stream connects, so a tab holds the Web Lock `pinround-live-updates-cookie` from its ask until its stream has connected (the ask and the connect each give up after 5 s, so at most 10 s); no other tab's ask, for other topics, lands in between. Each call checks the session, the current memberships and the asked tickets again, so after logout or removal from the org, renewal fails and the subscription stops within 10 minutes.

| Topic (URI template) | Who may subscribe | Payload |
|---|---|---|
| `https://pinround.app/o/{orgId}/u/{userId}` | only that user | Turbo Streams with content for that user: bell count and items, Home section refresh, toasts, "AI suggestion ready" |
| `https://pinround.app/o/{orgId}/t/{ticketId}` | users the TicketVoter allowed to VIEW the ticket when the page was rendered | **No content.** Only `<turbo-stream action="refresh">`, or "reload frame X". The browser then fetches the frame through normal HTTP, so the voter runs again |
| `https://pinround.app/o/{orgId}/admin` | org admins | Admin-only signals (for example "export ready") |
| `https://pinround.app/o/{orgId}/team/{teamId}/lists` | members of that team (and managers/admins) | **No content.** "Something changed in this team's lists" signal; never used for team-only tickets ([ADR-0023](../03-adr/0023-realtime-mercure.md)) |

Why the ticket topic carries no content:

- If Lena loses access (the visibility changes to "team"), her subscription token may still be valid for up to 10 minutes. But she only ever receives "refresh", and the refresh request is checked again and denied.
- Without this rule, a content push could leak data in that window.

Implementation notes:

- Publishing happens in outbox handlers (workers), never inside the web request.
- Use Symfony's Mercure component and UX Turbo's Mercure stream support. Verify the exact Twig helper and JWT cookie options at implementation.
- **One hub for all app containers.** The hub built into FrankenPHP works for a single app container (self-hosted Compose). With 2 or more app containers (SaaS), each built-in hub would only know its own subscribers, so a publish from one container would miss users connected to another. SaaS therefore runs **one shared hub service** (the standalone Mercure hub, with a transport that supports several instances if it is scaled; verify the options at implementation), and the app containers only publish to it. That one hub serves every organization: each topic starts with its organization (`/o/{organizationId}/…`), so a subscriber reads only the topics its cookie lists.
- **Coalescing.** Ticket and team-list signals are coalesced per topic for about 2 seconds, so 20 quick changes send 1 signal as they start and 1 after them: worker-notify publishes when it takes the lock `realtime-refresh:{ticketId}` (2 s) on the state Valkey, and otherwise queues one delayed try (`RefreshTicketPagesHandler`) that waits until that lock's window ends (its PTTL plus 50 ms, a full 2 s when the store cannot say); the lock `realtime-refresh-trailing:{ticketId}` that keeps a burst to one such try lives only until that try is due, so a change after it published queues the next one, and the last change of a burst shows at most about 2 s after the signal before it. The browser refetches with a random 0–1 s delay (jitter), and only when the page is visible (`document.visibilityState`); a hidden tab refetches once when it becomes visible.
- **What a signal refetches.** The page marks its parts with `data-live-refresh`: a `turbo-frame` with a `src` (the thread) reloads, and any other marked element with an id (the ticket's state, next step, people and activity) is swapped for the element of the same id in a fresh GET of the page. A page with no marks reloads through a Turbo visit. A part with a field being typed in, an open dialog or an open edit form is held, so no text is lost, and is fetched once the hold ends (focus moves, the dialog or the edit form closes); a focused button holds nothing, and its copy in the fresh part takes the focus back (found by the nearest id around it and its place among the controls there with the same tag and label); the comment composer is `data-turbo-permanent`, so a draft survives the thread's reload. The `LiveUpdates` element is `data-turbo-permanent` with an id that follows its subscribe URL: a Turbo visit back to the same ticket keeps the stream and asks for no cookie, and a visit to another ticket opens a new one (`tests/e2e/live-updates.spec.ts`).
- The per-user topic follows the [ticket visibility predicate](data-model.md#ticket-visibility-predicate): content about a team-only ticket is published only to users who pass it at publish time.

## What is pushed when

| Domain event | Pushed to | Push |
|---|---|---|
| TicketMoved, TicketAssigned, TicketChecked, TicketVisibilityChanged | ticket topic; user topics of the old and new turn owners | refresh ticket frames; refresh the Home "needs you" frame for the users whose list changed |
| QuestionAsked / QuestionAnswered | ticket topic; user topic of the asked person (and of the asker on answer) | refresh; bell item; toast "Mia answered your question" |
| CommentPosted, ReplyPosted, CommentResolved, MessageEdited, MessageRemoved | ticket topic | refresh the thread frame |
| RoundShared, PinVerdictSet, ChecklistItemChanged | ticket topic; user topic of the next turn owner | refresh the rounds frame |
| PingSent (delivered) / PingCleared | user topic of the recipient | bell count + items |
| SuggestionReady, SummaryGenerated | user topic of the requester; ticket topic for summaries | refresh the report form or summary frame |
| MediaReady | user topic of the uploader | replace the "processing" placeholder with the thumbnail |

Pushes are hints. If a push is lost, the next page load or frame refresh is still correct, because pages always read from the database.

## Notification kinds

| Kind | Trigger | Recipient | In bell | In email digest |
|---|---|---|---|---|
| `ping` | someone pings you | recipient | yes | yes |
| `question` | QuestionAsked to you | asked person | yes | yes |
| `answer` | QuestionAnswered | asker | yes | no |
| `reply` | ReplyPosted on a message; a reply within 120 s of the last one folds into the unread `reply` notification for the same message | the message's author | yes | no |
| `assigned` | TicketAssigned to you | assignee | yes | yes |
| `your_turn` | a move makes it your turn to review or test | reviewer / tester | no (Home shows it) | yes, in the "waiting on you" list |
| `check` | a new ticket needs your check | checker | no (Home shows it) | yes |
| `round_shared` | RoundShared on a ticket where you are the tester or the reporter | them | yes | no |
| `closed_duplicate` | your report was closed as a duplicate | reporter | yes | yes |

The actor never notifies themselves. Muted tickets are skipped. For restricted tickets (`visibility = team`), recipients are checked again at delivery time.

## Ping lifecycle

1. **Create.** The user opens "Ping {name}" on a ticket (person list, or My reports) and edits the suggested text. The Notification module then:
   - checks with the TicketVoter that the sender and the recipient can both VIEW the ticket;
   - checks that the recipient is not the sender;
   - checks that the body is 1–500 characters long.
2. **Rate limit.** A Symfony RateLimiter (storage: Valkey, policy: fixed window of N hours) uses the key `ping:{org}:{from}:{to}:{ticket}`.
   - If the limit is hit, nothing is stored. The sender sees "You can ping Lena again at 21:50."
   - The dialog always says the rule: "Lena gets one notification. You can ping again after 4 hours (set by your admin)."
3. **Deliver or hold.** If quiet hours are on and it is between 18:00 and 09:00 in the recipient's time zone, `deliver_after` is set to the next 09:00. A scheduler job releases held pings every 5 minutes. Otherwise the ping is delivered at once: a `ntf_ping` row, then `PingSent` → push to the recipient's topic.
4. **Group.** The bell groups pings by (sender, ticket): "Omar · 2 pings · SHOP-412", with the newest text shown. Groups are sorted by size, then by age.
5. **Clear.** A ping is cleared when either:
   - the recipient clicks "Done" (`cleared_reason = done`); or
   - auto-clear is on and the recipient acts on that ticket (`cleared_reason = acted`). "Acts" means: any move, comment, reply or answer, verdict, check action or assignment made by the recipient.
   The Notification module listens to those events, where `actorId` = the recipient, and clears all their open pings on that ticket. The toast after the action says so: "Approved. 2 pings on this ticket cleared."
6. **Expire.** Uncleared pings older than 30 days are archived (hidden from the bell, kept for the audit trail).

## The bell

- The badge counts the person's open bell rows in `ntf_notification`: of a bell kind, delivered (`deliver_after` empty or past), not done, with a sender and a ticket the person may still see. A read row stays counted until it is done. The badge shows "99+" above 99; a screen reader hears "Pings, N open".
- The popover is a lazy Turbo frame, `GET /bell`, loaded again on every open. It shows groups (sender + ticket), largest first, each with "Done" and "Open".
- `POST /bell/{senderId}/{ticketId}/done` marks the group's open rows done. A Turbo form gets one Turbo Stream answer that replaces the badge (`#bell-badge`), the popover body and shows the toast "Marked done."; a plain form is sent Home with that toast.
- `POST /bell/{senderId}/{ticketId}/open` marks the group read and goes to the ticket, whose page checks again that the person may see it.
- All three routes need ORG_VIEW (OrganizationVoter) and take the person from the session, never from the URL. A malformed id, a group with nothing open, and another person's group are not found (404) and change nothing; a missing or wrong CSRF token is refused (422). Proven by `BellTest`.
- Home shows the top 3 groups in "Pings for you" and "Show N more", which opens the bell.
- The count is read from `ntf_notification` with indexes that start with `(organization_id, user_id)`. It is cached per user for 60 s (`cache.bell_count`) and invalidated after the commit of every change for that user.

## Email digest

Email is a safety net, not a second inbox.

- **When:** a scheduled job (every 15 minutes) collects bell items that are unread for more than 1 hour. It sends one digest per user, at most every 3 hours, and never during their quiet hours. Users will be able to choose "Daily at 09:00" or "Off" in their settings; that digest setting is not built yet, so every user gets the 3-hour digest, and the email's footer links to the profile without offering to turn it off.
- **What:** "3 things wait on you in pinround". For each item: the person, the kind, the ticket key, the title, and a link.
  - For restricted tickets the title is replaced with "a restricted ticket in team Shop".
  - Ticket descriptions, comments and AI text are never in emails.
- **How:** the Symfony Mailer, sent on the `mail` Messenger transport, with retries and idempotency on (user, digest window).

## Sequence diagrams

**A ping, with a rate limit and quiet hours**

```mermaid
sequenceDiagram
  autonumber
  actor O as Omar
  participant W as Web (Notification UI)
  participant N as Notification module
  participant RL as RateLimiter (Valkey)
  participant DB as PostgreSQL
  participant S as Scheduler
  participant H as Mercure hub
  actor L as Lena
  O->>W: Send ping to Lena on SHOP-412
  W->>N: ping(ticket, from=Omar, to=Lena, body)
  N->>N: voter: both can VIEW the ticket
  N->>RL: consume ping:org:omar:lena:412
  alt limit reached
    RL-->>N: refused, retry after 21:50
    N-->>W: "You can ping Lena again at 21:50"
  else allowed
    N->>N: Lena's quiet hours now?
    alt quiet hours
      N->>DB: INSERT ntf_ping (deliver_after = 09:00)
      S->>DB: every 5 min: release due pings
      S->>H: publish to /u/lena (at 09:00)
    else normal
      N->>DB: INSERT ntf_ping + outbox PingSent
      DB-->>H: (outbox worker) publish to /u/lena
    end
    H-->>L: bell +1
  end
```

**Auto-clear when the recipient acts**

```mermaid
sequenceDiagram
  autonumber
  actor L as Lena
  participant T as Ticketing
  participant DB as PostgreSQL
  participant OB as Outbox worker
  participant N as Notification module
  participant H as Mercure hub
  L->>T: Approve code (SHOP-412)
  T->>DB: move + turn row + outbox TicketMoved (actor Lena)
  T-->>L: Turbo Stream + toast "Approved. 2 pings on this ticket cleared."
  OB->>N: TicketMoved(actor=Lena, ticket=412)
  N->>DB: UPDATE ntf_ping SET cleared_at, reason='acted' WHERE to=Lena AND ticket=412
  N->>H: publish bell update to /u/lena
```

The toast already knows the count because the web request reads the open pings (one indexed count) before it answers. The actual clearing happens in the handler, so a failure there never breaks the move.

**Live update on an open ticket page**

```mermaid
sequenceDiagram
  autonumber
  actor M as Mia (has SHOP-412 open)
  participant H as Mercure hub
  participant OB as Outbox worker
  participant APP as App
  OB->>H: publish to /t/412: turbo-stream refresh "rounds"
  H-->>M: SSE message (no content)
  M->>APP: GET /t/SHOP-412 frame "rounds" (cookie session)
  APP->>APP: TicketVoter VIEW, then render
  APP-->>M: fresh frame HTML
```

## Settings and storage

| Setting (org, Admin → Pings) | Values | Default |
|---|---|---|
| Ping limit | 1 h, 4 h, 8 h, 24 h | 4 h |
| Auto-clear | on / off | on |
| Quiet hours (18:00–09:00, recipient's time zone) | on / off | off |

| Setting (user) | Values | Default |
|---|---|---|
| Email digest (not built yet) | Every 3 hours / Daily at 09:00 / Off | Every 3 hours |
| Time zone | IANA name | from the browser at sign-up |

Tables (details in [data-model.md](data-model.md)):

- `ntf_ping`;
- `ntf_notification` (partitioned by month, kept 90 days);
- `ntf_mute`;
- `ntf_delivery` (digest windows sent, for idempotency).

Settings live in `org_settings`.

## Failure handling

| Failure | Effect | Handling |
|---|---|---|
| Mercure hub down | Pages stop getting live updates | Pages still work. Turbo refreshes on navigation. Publishing failures are retried twice, then dropped (pushes are hints). An alert fires on a publish error rate > 5 % for 5 minutes ([operations.md](operations.md)) |
| Valkey down | Rate limits cannot be checked | Pings **fail closed**: "Pings are paused for a moment. Try again soon." Everything else that does not need Valkey keeps working |
| Email provider down | Digests are delayed | The `mail` transport retries with back-off (1 min, 5 min, 30 min, 2 h), then the failure transport and an alert. Idempotency stops double sends |
| Outbox backlog | Bell and pushes arrive late | An alert fires when the oldest outbox message is > 60 s old. The workers on `outbox` scale out |
