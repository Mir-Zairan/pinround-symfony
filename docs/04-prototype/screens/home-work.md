# Screen: Home for team members

The first page for leads, testers and developers. It answers one question: what do I do next?

## Index

- [Who sees it](#who-sees-it): team members, and how extra roles add parts.
- [Wireframe](#wireframe): layout from top to bottom.
- [Header block and focus chips](#header-block-and-focus-chips): headline, subline, chips, and the red counts.
- [Needs you now](#needs-you-now): the top 5 and the "Why" line.
- [Needs-you score](#needs-you-score): exact formula and the kinds of work.
- [Also waiting on you](#also-waiting-on-you): grouped rest and the "Start …" queues.
- [Queue bar](#queue-bar): walking a group one ticket at a time.
- [Right column](#right-column): admin card, pings, release, your team.
- [All teams mini view](#all-teams-mini-view): shown when the person is also a manager.
- [States](#states): empty, many items, loading.
- [Product notes](#product-notes): where the prototype simplifies.

## Who sees it

- Anyone with a team role (lead, member) whose job is lead, tester or developer.
- Extra company roles **add** parts to the same page. There is no mode switch:
  - Admin role → an "Admin" card in the right column and an `Admin` focus chip.
  - Manager role → an "All teams" section at the bottom and an `All teams` focus chip.
- Manager-only and admin-only users get their own Home ([home-manager.md](home-manager.md), [home-admin.md](home-admin.md)).

## Wireframe

```
Thursday, 5 November
30 things wait on you, Lena                                   (h1, 30 px)
Do the top 5 first. The rest is grouped below, so you can do one kind at a time.
You lead the team, so reviews, decisions and unclear new tickets come to you.
[All] [My work] [My team] [All teams] [Admin ·1]               (focus chips)

┌ left column (wide) ─────────────────────────┐ ┌ right column ───────────────┐
│ Needs you now  top 5 of 30   [AI ORDER] who │ │ Admin          Open settings│
│ is blocked, priority, release, how long…    │ │  Company login is not set up│
│ ┌──────────────────────────────────────────┐│ │                             │
│ │1  SHOP-118 · Totals counted twice        ││ │ Pings for you 11   made up  │
│ │   Answer Mia’s question       [Open ticket]│ │ ┌ Mia  3 pings   30 min ago┐│
│ │   Why Mia is blocked until you answer · …││ │ │ “Any news? …”            ││
│ │                                  waiting 2d││ │ │ SHOP-118   Done [Open t.]││
│ └──────────────────────────────────────────┘│ │ └──────────────────────────┘│
│ 2 … 5                                       │ │ [Show 2 more]               │
│                                             │ │                             │
│ Also waiting on you 25                      │ │ Release v2026.45.1          │
│ Grouped by what you do. Open a group, or go │ │ your team · 8 tickets       │
│ through it one ticket after another.        │ │ [██ 3 doing|██ 2 checking|…]│
│ ┌──────────────────────────────────────────┐│ │ SHOP-120 Title…  Testing·Mia│
│ │> Code reviews 4  Approve or ask… [Start reviews]│                          │
│ │> Your building work 21 …    [Go through them]  │ Your team                │
│ └──────────────────────────────────────────┘│ │ (LM)(MB)(OK)+13  16 people ·│
└─────────────────────────────────────────────┘ │ see who has what            │
                                                └─────────────────────────────┘
All teams · you are also a manager            (only with Manager role)
Lena’s team   real · 100 tickets  [▇▇▇▇▇▇▇▇▇▇]  Flowing
```

Two columns wrap into one on narrow screens (left min ~560 px, right min ~320 px).

## Header block and focus chips

- Date line, muted 14 px: `Thursday, 5 November` (the product uses today's date in the user's locale).
- Headline:
  - With work: `N thing waits on you, <first name>` or `N things wait on you, <first name>`.
  - Without work: `All clear, <first name>`.
- Subline:
  - When there are more than 5 items: `Do the top 5 first. The rest is grouped below, so you can do one kind at a time. ` followed by the role line.
  - Otherwise just the role line.
- Role lines:
  - Lead: `You lead the team, so reviews, decisions and unclear new tickets come to you.`
  - Tester: `Tests and questions for you come first.`
  - Developer: `Answer questions first, then reviews, then your own building work.`
- **Focus chips** (group label "Show on Home"), shown under the subline:
  - Always: `All`, `My work`, `My team`.
  - With the Manager role: `All teams`.
  - With the Admin role: `Admin`.
  - Exactly one chip is pressed. The default is `All`.
- A chip narrows the page. Visibility per chip:

| Part | Shown when focus is |
|---|---|
| Needs you now, Also waiting on you, Pings for you | All, My work |
| Release (lead only), Your team | All, My team |
| All teams mini view | All, All teams (needs Manager role) |
| Admin card | All, Admin (needs Admin role) |

- **Red count on a chip**: when a part is hidden by the current focus and has something waiting, its chip shows a red tag with the count.
  - `My work` count = number of needs-you items.
  - `Admin` count = number of admin alerts.
  - Other chips have no count in the prototype.

## Needs you now

- Title `Needs you now`, then muted `top 5 of N` when there are more than 5 items.
- Right of the title: an `AI ORDER` chip and muted text `who is blocked, priority, release, how long it waits`.
- An ordered list of at most 5 cards. Each card has:
  - **Rank number** (mono, 20 px). Rank 1 uses `--primary` with a `--primary` border. The others use `--outline` with an `--outline-var` border.
  - **Key and title** (muted): `SHOP-118 · Totals counted twice`.
  - **Task**, a verb sentence (17 px, semibold). See the task texts below.
  - **Why**: an `Why` label (12 px bold, `--ai` colour), then reasons joined with ` · `.
  - **Button**: `Open ticket`, or `Check it` for new tickets to check. Rank 1 uses a filled button, the others tonal.
  - **Since** (muted): `today` or `waiting Nd`.
- "Why" reasons, in this order, only when true:
  1. `<asker> is blocked until you answer` (open question to you).
  2. The priority, if Blocker, Critical or Major.
  3. `in release v2026.45.1` (current release), or `release <first fix version>` (another release).
  4. `no change for N days` (if more than 7), or `changed today` (0 days), or `updated 2 Nov`.
- For "check" items the why line is the check reasons, for example `maybe the same as an older ticket · it sounds risky`.
- Task text by kind:
  - answer: `Answer <asker>’s question`.
  - check: `Check the new ticket from <reporter first name>`.
  - backlog: `Plan it`.
  - todo: `Start building`, or `Pick a developer` when nobody is assigned.
  - in progress: `Build it`.
  - code review: `Review <author>’s code`, or `Review the code`.
  - testing: `Test it`.
  - needs input: `Give input`.
  - on hold: `Decide: go on or stop`.
- Clicking the button opens the ticket. "From" is remembered, so the ticket's back button says `Home`.

## Needs-you score

Only tickets where it is **your turn** are counted (see turn rules in [ticket-page.md](ticket-page.md#turn-rules)).

These tickets are skipped:
- Done tickets.
- Backlog tickets, unless they are new tickets for you to check.

**Kind of item**, decided in this order:

| Kind | When | Base score |
|---|---|---|
| `answer` | An open question is addressed to you | 100 |
| `check` | A new ticket has check reasons, is not checked yet, and you are its checker (+40 if one reason is "risk") | 70 |
| `review` | Step is Code review | 30 |
| `test` | Step is Testing | 30 |
| `assign` | Step is To do and nobody is assigned | 25 |
| `input` | Step is Needs input | 20 |
| `decide` | Step is On hold | 15 |
| `build` | Anything else that is your turn | 10 |

**Score** = base + priority weight + release weight + min(days waiting, 60) × 0.5.
- Priority weight: Blocker 50, Critical 40, Major 20, Normal 8, Minor 3.
- Release weight: 30 if the ticket is in the current release, 10 if it is in any other release, else 0.
- Days waiting = days since the last update. A ticket moved in this session counts as 0.

Sort by score, highest first. The top 5 go to "Needs you now". The rest go to "Also waiting on you".

A "check" item is never counted twice. If you are the checker, the ticket appears only as "check".

## Also waiting on you

- Title `Also waiting on you N`. Muted line: `Grouped by what you do. Open a group, or go through it one ticket after another.`
- One bordered list. One row per kind that has items, in this order:

| Kind | Group label | Start button | Hint |
|---|---|---|---|
| answer | Questions for you | Answer them one by one | Someone is blocked until you answer |
| check | New tickets to check | Check them | Only the ones AI or the rules were unsure about |
| review | Code reviews | Start reviews | Approve or ask for changes |
| test | Tests | Start testing | Pass or send back |
| assign | Need a developer | Go through them | Give each one to someone |
| input | Your input | Go through them | Someone waits on your answer |
| decide | On hold | Go through them | Go on, or close |
| build | Your building work | Go through them | Tickets assigned to you |

- Each row has a chevron header button (`label count`, `aria-expanded`), the hint (muted), and a tonal small start button.
- Opening a group shows its tickets as rows: key (mono, 84 px), title, since.
- The start button starts a **queue** with the group's ticket keys and opens the first ticket.

## Queue bar

On the ticket page, while in a queue, a full-width bar in `--primary` shows:

`<group label> · 2 of 4` …… `[Previous]` `[Next ticket]` `[Stop]`

- `Previous` is disabled on the first ticket.
- On the last ticket the next button says `Finish`. Clicking it goes Home with the toast `All done in <group label>.`
- `Stop` leaves the queue and stays on the ticket.
- While in a queue, the normal "N of M · Previous · Next" controls in the ticket header are hidden.

## Right column

**Admin card** (Admin role, focus All or Admin):
- Title `Admin` and a text button `Open settings`.
- A list of alert links. Each one opens the matching admin tab:
  - `N invite is not accepted yet` / `N invites are not accepted yet` → People.
  - `Company login is not set up` → Connections (shown while SSO is off).
  - `AI runs on laptops: people must set up Ollama` → AI (shown while "Where the AI runs" is not cloud).
- With no alerts: `Nothing needs you as admin.`

**Pings for you** (focus All or My work):
- Title `Pings for you N` (N = all single pings). Right side, muted: `made up for the demo` (prototype only).
- Shows the top 3 ping groups. A group = one sender + one ticket. Groups are sorted by number of pings (most first), then by newest.
- Each group is a `--ping-container` card:
  - Sender first name (bold), then `pinged you` or `N pings`, then the time of the newest ping (`30 min ago`, `5 h ago`, `1 day ago`, `3 days ago`).
  - The newest ping text in quotes.
  - Ticket key (mono), a text button `Done`, and a button `Open ticket` (`--ping` background).
- `Done` hides the group with the toast `Marked done.`
- With more than 3 groups: an outline button `Show N more` opens the bell popover.
- Empty: `No open pings. Nice.`

**Release** (lead only, focus All or My team):
- Title `Release v2026.45.1`, muted `your team · N tickets`.
- A 26 px bar split by the 5 groups. Each part shows `N doing` and similar labels, in the group pill colours.
- A list of the release's tickets, sorted by priority. Each row: key, title (link), state. The state is the step name plus ` · <first name of whose turn>`. The state is red when the priority is Critical.

**Your team** (focus All or My team):
- An avatar stack of the first 4 members, plus `+N` when there are more.
- A text button `N people · see who has what` → Team page.

## All teams mini view

Only with the Manager role, focus All or All teams. It sits below the two columns.

- Title `All teams · you are also a manager`.
- One row per team:
  - Name and a muted note (`real · 100 tickets`, or `example · made up` in the prototype).
  - A 14 px bar coloured by the 5 groups.
  - A flag: `Flowing` (green) or `NN% of open work is waiting` (amber). The flag shows when more than 25% of open work is in the Waiting group.

## States

- **Loading**: the whole page shows `Loading tickets…`.
- **Nothing waits on you**: the headline says `All clear, <name>`, and a dashed box says `Nothing waits on you right now.`
- **Many items**: always top 5 plus groups. Rows inside an open group are not limited.
- **No pings**: `No open pings. Nice.`

## Product notes

- Pings and the "made up for the demo" label are demo data. The product shows real pings.
- The date is fixed in the prototype. Use the real date and the user's time zone.
- The score is computed in the browser here. In the product, store kind, base score and "waiting since" in the turn read model, and add the time part in SQL (see ADR-0015).
- Group counts must update live when other people act (Mercure), without a full reload.
- Team stack and release bar use real data per team. A person in several teams sees their main team (product decision: show one stack per team, max 3, if needed).
