# Screen: Home for managers

"Teams at a glance": every team on one page, compared through the same five groups.

## Who sees it

- Users whose only work role is **Manager** (company role) and who are not in a team as lead or member.
- A team member who also has the Manager role keeps the work Home and gets the "All teams" mini view instead ([home-work.md](home-work.md#all-teams-mini-view)).

## Wireframe

```
Thursday, 5 November
Teams at a glance                                                  (h1)
Every team uses the same five groups, even when its own steps differ.
Open a team to see its steps.
■ To do  ■ Doing  ■ Checking  ■ Waiting  ■ Done                    (legend)

┌ card ───────────────────────────────────────────────────────────────┐
│ Lena’s team   real · 100 tickets                         Flowing    │
│ [ 23 | 9 | 12 | 6 | 50 ]   (22 px bar, numbers inside)              │
│ Show this team’s own steps                                          │
│   To do · 23      Doing · 9      Checking · 12   Waiting · 6  Done · 50│
│   Backlog · 15    In progress · 9 Code review · 4 Needs input · 4 …  │
│   To do · 8                       Testing · 8     On hold · 2         │
└─────────────────────────────────────────────────────────────────────┘
┌ Payments team   example · made up            48% of open work is waiting │
└──────────────────────────────────────────────────────────────────────┘
┌ Mobile team     example · made up                              Flowing   │
└──────────────────────────────────────────────────────────────────────┘
Lena’s team uses real counts from the Jira snapshot. The other two teams are
made up, to show how different steps still add up the same way.
```

## Elements and rules

- **Legend**: five swatches with the group labels and colours from the [design tokens](../prototype-guide.md#design-tokens).
- **One card per team**:
  - Name (17 px bold) and a muted note.
  - A flag on the right:
    - Open work = all tickets minus Done.
    - Waiting share = Waiting count ÷ max(1, open work).
    - When the share is above 25%, the flag says `NN% of open work is waiting` in `--waiting` colour.
    - Otherwise it says `Flowing` in `--passed` colour.
  - **Bar**: one part per group that has tickets, width = count ÷ total (Done included). Each part has the group colour, white 12 px bold count text, and a tooltip `<Group>: N`.
  - **Toggle** text button: `Show this team’s own steps` / `Hide steps` (`aria-expanded`).
  - **Expanded**: five columns, one per group. Each column shows `<Group> · N`, then that team's own steps with counts (for example `Spec review · 3`). Team-specific step names stay visible here, but the bar always uses the five groups.
- **Example teams in the prototype** (made up, to show different step names):
  - Payments team: Ready 9 (To do), Building 6 (Doing), Spec review 3 + QA 5 (Checking), Blocked 12 (Waiting), Shipped 14 (Done). Its flag says waiting.
  - Mobile team: Inbox 12 (To do), Doing 4, Design check 2 (Checking), Waiting on store 1 (Waiting), Live 20 (Done).
- Footer note (muted): prototype-only text about real vs example data.

## Interactions

- Expand/collapse per team. Nothing else is clickable in the prototype.
- Managers can open **Tickets** from the nav. They can read and comment but not move tickets (see [ticket-page.md](ticket-page.md#role-differences)).

## States

- Loading: `Loading tickets…`.
- No teams yet (product): a short empty text, for example "No teams yet. An admin creates teams under Admin → Teams."

## Product notes

- Counts come from the turn/step read model, per team, live.
- Tickets with "team only" visibility are not counted for managers who are not in that team, and they are never listed for them.
- Product idea kept from the prototype: clicking a team or bar part could open Tickets filtered to that team and group. It is not in the prototype; decide in the phase plan.
