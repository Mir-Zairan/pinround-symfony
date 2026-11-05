# Screen: My reports

Every ticket you reported, shown like parcel tracking: where it is, who has it, and whether it needs you.

## Index

- [Who sees it](#who-sees-it): who can open it, and the nav badge.
- [Wireframe](#wireframe): header, groups and cards.
- [Groups](#groups): Needs you, Stuck, Moving, Done, and their order.
- [Card](#card): title, progress dots, now line, buttons, and the "⋯" menu.
- [Interactions](#interactions): answer inline, ping, mute, reopen.
- [States](#states): empty and many reports.
- [Product notes](#product-notes): where the prototype simplifies.

## Who sees it

- Team members. Nav item `My reports`.
- The nav item gets a red count tag when questions on your own reports wait for your answer.
- It lists tickets whose reporter is you, newest first. A ticket made in pinround today comes before a synced one with the same date.

## Wireframe

```
My reports                                              [Report a problem]
20 tickets you reported. 1 needs your answer.

● Needs you 1   Someone waits for your answer
┌──────────────────────────────────────────────────────────────────────────┐
│ SHOP-140                                                 changed recently │
│ Order total looks doubled                                                 │
│ ●──────○──────○──────○        (12 px dots joined by 2 px lines)           │
│ Waiting Building Checking Done                                            │
│ (LM) Lena asks: “Which order was it?”   2 of 2 pins OK  [Answer]  [⋯]     │
│ [ textarea: Your answer. It closes the question, so the ticket can go on. ]│
│                                            [Cancel] [Send answer]          │
└──────────────────────────────────────────────────────────────────────────┘
● Stuck 2   No change for more than 7 days. A ping can help.
│ SHOP-121 …                                       no change for 27 days   │
│ (LM) Lena plans it                                [Ping Lena] [⋯]         │
● Moving 6   Nothing to do. Just follow along.
│ … 4 cards …                                                              │
Show 2 more
Show 12 done
```

## Groups

Each group shows only when it has tickets, in this order:

| Group | Dot colour | Which tickets | Hint |
|---|---|---|---|
| Needs you | `--error` | Open tickets with an open question addressed to you | `Someone waits for your answer` |
| Stuck | `--waiting` | Other open tickets with no change for more than 7 days | `No change for more than 7 days. A ping can help.` |
| Moving | `--primary` | Other open tickets (changed in the last 7 days) | `Nothing to do. Just follow along.` |

- Group header: dot, label, count, and the hint (muted).
- "Needs you" shows all its cards.
- "Stuck" and "Moving" show 4 cards, then a text button `Show N more` / `Show fewer`.
- **Done** is folded below the groups, as a text button `Show N done` / `Hide N done` (`aria-expanded`).
- The intro line under the title:
  - `N tickets you reported. ` + `K need(s) your answer.` or `None needs you right now.`
  - With no reports: `You have not reported anything yet.`

## Card

A bordered card (16 px radius, `--s-lowest`):

1. **Top line**:
   - Key (mono, 12 px muted) above the title (16 px semibold). Both are one link that opens the ticket.
   - On the right, the "side" text:
     - Stuck: `no change for N days` (bold, `--waiting`).
     - Changed in the last 2 days, or moved now: `changed recently`.
     - Otherwise: `updated <date>`.
2. **Progress dots** (`aria-label="Progress"`, max 520 px): four stages, `Reported` → `Building` → `Checking` → `Done`.
   - Stage from the step's group:
     - To do = Reported.
     - Doing = Building.
     - Checking = Checking.
     - Done = Done.
     - Waiting = Building (shown as waiting).
   - Stages before the current one, and all stages on Done: filled `--primary`.
   - Current stage: filled `--primary`, or `--waiting` when the ticket waits (Waiting group or an open question). It gets a 3 px `--primary-container` ring and a bold label. Its label becomes `Waiting` when the ticket waits.
   - Later stages: `--s-highest`, muted label.
   - The connecting line after each dot is `--primary` up to the current stage, `--s-highest` after it, and none after Done.
3. **Bottom line**:
   - The avatar of whose turn it is (26 px; hidden on Done).
   - The now text:
     - A question to you: `<asker> asks: “<question>”`.
     - Done: `Done` plus ` · ships in <release>` when there is one.
     - Otherwise the ticket's Now text, for example `Omar builds it`.
   - Optional markers:
     - `k of n pins OK` (checklist).
     - Tags `EXAMPLE` and `MUTED`.
     - Muted `pinged`.
   - At most **one** main button, then the `⋯` icon button:

| Group | Main button | Style |
|---|---|---|
| Needs you | `Answer` | filled |
| Stuck | `Ping <first>` (if that person can be pinged and was not pinged yet) | tonal |
| Moving | `Ping <first>` (same condition) | text (quiet) |
| Done | none | — |

   - A person "can be pinged" when they are not you and not "Customer", "Product owner", "Technical owner" or a help-desk integration.
4. **"⋯" menu** (`role="menu"`, aligned right):
   - `Open ticket`.
   - `Add more info`: opens the ticket with the toast `Write your info in the comment box.`
   - `Mute updates` / `Turn updates on`. Toasts `Muted. You still see it here.` / `You get updates again.`
   - `Copy link`: toast `Link copied (simulated).`
   - Done cards, for a tester or lead only: `Not fixed? Reopen`. It moves the ticket to In progress with the toast `Reopened. Back in progress.`
   - Only one menu is open at a time. Clicking `⋯` again closes it.

## Interactions

- **Answer**: opens an inline textarea (placeholder `Your answer. It closes the question, so the ticket can go on.`) with `Cancel` and `Send answer`.
  - Sending adds your reply as the answer and marks the question answered. That un-pauses the ticket and clears your pings on it.
  - The card moves out of "Needs you".
  - Toast `Answered. <asker> can go on.`
- **Ping <first>**: opens the ping sheet with the draft `Hi <first>, any news on <key>? I reported it. Now: <now text>.`
  - After sending, the card shows `pinged` and the button hides for that ticket and person.
- Clicking the title opens the ticket. Its back button says `My reports`.

## States

- **Empty**: a dashed box `When you report a problem, you can follow it here until it is fixed.`
- **Many reports**: Stuck and Moving show 4 each. Done is folded.
- **Loading**: `Loading tickets…`.

## Product notes

- "Mute updates" must stop notifications for that ticket for this user only.
- "Copy link" copies the real ticket URL.
- "changed recently" should come from the ticket's last change time. Optionally also show "new since your last visit" per user.
- The stage mapping uses the five groups, so it works for every team's custom steps.
- The nav badge counts must update live (Mercure) when someone asks you a question.
