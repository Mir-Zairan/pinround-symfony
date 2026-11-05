# Screen: Tickets (List and "Whose turn?")

All tickets of the team's projects in two views. **List** groups tickets by step. **Whose turn?** groups them by the person who must act next.

## Index

- [Who sees it](#who-sees-it): roles that see this screen.
- [Wireframe](#wireframe): the rail, the toolbar, and both views.
- [Project rail](#project-rail): the projects with their counts.
- [Toolbar and filters](#toolbar-and-filters): search, people chips or picker, Show done, To check.
- [List view](#list-view): step groups, row content, and sorting.
- [Whose turn view](#whose-turn-view): person groups, the wait meter, and sentences.
- [States](#states): empty and loading.
- [Product notes](#product-notes): where the prototype simplifies.

## Who sees it

- All roles except admin-only users.
- Managers see the list but cannot move tickets.
- Tickets with "team only" visibility are hidden from people outside that team. That includes managers who are not team members.

## Wireframe

```
┌ rail ──────────────────┐ ┌ main ────────────────────────────────────────────────┐
│ LENA’S TEAM            │ │ All tickets                        [List|Whose turn?] │
│ [All projects     98]  │ │ 61 tickets · grouped by step                          │
│  SHOP  Shop        95  │ │ (🔍 Search by key or words in the title) [Everyone]   │
│  APP   Mobile app   3  │ │ [Me] [Mia] [Omar]  [Show done] [New · to check · 1]   │
│  UX    UX design    1  │ │                                                       │
│  QA    Test autom.  1  │ │ > ● Needs input 4 [WAITING]      Someone must answer… │
│ Manage projects        │ │   ┌ SHOP-131  Title…                  Major (OK) 2 Nov│
└────────────────────────┘ │   │           Bug · Release v2026.45.1 · Turn: Mia ·  │
                           │   │           Jira: Input (internal)                  │
                           │   └───────────────────────────────────────────────────│
                           │ > ● Code review 4 [CHECKING]           Being checked  │
                           │ > ● Testing 8 [CHECKING] …                            │
                           └───────────────────────────────────────────────────────┘
```

"Whose turn?" view, same rail and toolbar (no people chips):

```
Each ticket sits with the one person who must act next. Longest waits first. Done tickets are left out.
┌──────────────────────────────────────────────────────────────────────┐
│ (LM) You                                                  [▇▇▇▇▇──]  │
│      30 tickets wait on you · the oldest for 41 days                 │
│  Answer Mia’s question · SHOP-118 Totals counted twice     2 days    │
│  Review the code · SHOP-120 …                              41 days   │
│  Show 25 more                                                        │
└──────────────────────────────────────────────────────────────────────┘
┌ (MB) Mia Berg   14 tickets wait on Mia · the oldest for 12 days …     ┐
┌ (C)  Customer   3 tickets wait on Customer · … · outside the team     ┐
```

## Project rail

- Header (13 px bold, muted): the team name in capitals, for example `LENA’S TEAM`.
- `All projects`, then one rail button per linked project: key (mono), name, and count.
  - A count is the number of open tickets in that project.
  - Done tickets are counted only when "Show done" is on.
  - The pressed project filters both views.
- Text button `Manage projects` → Team page.

## Toolbar and filters

- **Title**: `All tickets`, or `SHOP · Shop` when a project is picked.
- **Count line**:
  - List view: `N tickets · grouped by step`.
  - Whose turn view: `N tickets · grouped by who acts next`. Here N counts open tickets after the project and search filters.
- **View switch** (segmented, label "View"): `List` / `Whose turn?`.
- **Search** (pill field, placeholder `Search by key or words in the title`). It matches the key or the title, ignoring case. It filters both views.
- **People filter** (List view only, group label "Assigned to"):
  - Chips: `Everyone`, `Me` (team members only).
  - Then each other member's first name, but only when the team has at most 4 people.
  - With more than 4 people: a chip `Person ▾` opens the person picker in filter mode (see [dialogs-and-popovers.md](dialogs-and-popovers.md#person-picker)). After a pick, the chip shows that person's first name and is pressed.
  - The filter matches the **assignee**.
- **`Show done`** chip: shows Done tickets too. It also changes the rail counts.
- **`New · to check · N`** chip: shown when at least one ticket in the current project and search needs a check, or while the filter is on. It keeps only tickets that need a check.

## List view

- One collapsible section per **step** that has tickets.
- Step order follows the groups: Waiting, Checking, Doing, To do, Done. Inside a group, steps keep their defined order: core steps first, then company steps, then team steps.
- **Section header** (`.ghead`, `aria-expanded`): chevron, group-colour dot, step name, count, a `.tag` with the group label, and a right-side hint:
  - Waiting: `Someone must answer first`.
  - Checking: `Being checked`.
  - Doing: `Being built`.
  - To do: `Not started`.
  - Done: no hint.
- Sections start open. Clicking the header collapses the section, and it stays collapsed for the session.
- **Rows** are inside a bordered box, sorted by priority (Blocker, Critical, Major, Normal, Minor, Trivial), then by most recently updated:
  - Key (mono, 92 px wide).
  - Title (15 px semibold).
  - Meta line (muted), parts joined with ` · `:
    - Type.
    - `Release <versions>` if any.
    - `Turn: you` or `Turn: <first name>` (not on Done).
    - `To check by you` or `To check by <first name>` if the ticket needs a check.
    - The source: `Jira: <Jira status>`, or `made in this demo` / `example · made up` for pinround-made tickets.
  - Priority (Critical in bold `--error`).
  - Assignee avatar (30 px initials, tooltip `Assigned to <name>`). With nobody assigned, it shows `–` on `--s-high`.
  - Updated date short (`2 Nov`), or `now` when moved in this session.
- Clicking a row opens the ticket. The ticket's Previous/Next buttons walk the list in this exact order.

## Whose turn view

- Intro (muted): `Each ticket sits with the one person who must act next. Longest waits first. Done tickets are left out.`
- One card per person who has open tickets, using the turn rules in [ticket-page.md](ticket-page.md#turn-rules). Tickets with no one to act go under `Nobody`.
- Card order: **you** first, then by the oldest wait, longest first.
- Card header:
  - Avatar (40 px).
    - You: `--primary` background.
    - Team member: `--secondary-container`.
    - Outside the team (for example `Customer`, `Product owner`, a reporter): `--s-highest`.
  - Name. Your own card says `You`.
  - Sentence: `N tickets wait on <first name or "you"> · the oldest for D days`. People outside the team get ` · outside the team` added.
  - Wait meter, 80–140 px wide:
    - Width = min(100%, oldest days ÷ 60).
    - Colour `--error` when over 30 days, `--waiting` when over 10 days, else `--primary`.
    - Tooltip `Oldest wait: D days`.
  - Card background: `--s-low` for you, `--s-lowest` for others.
- Rows, sorted by wait (longest first):
  - The verb for that person in bold (same verb texts as Home), then ` · `, the key (mono), and the title.
  - Wait on the right: `today` or `N days`. Over 30 days, it is bold `--error`.
- Each card shows 5 rows. A text button `Show N more` / `Show fewer` toggles the rest.
- The people filter does not apply here. Project and search filters do.

## States

- **Loading**: `Loading tickets…` (whole page).
- **Empty** (no match): a dashed box `No tickets match. Clear the search or pick another person.`
- **Many people** (more than 4): people chips are replaced by the `Person ▾` picker. The Whose turn view lists everyone, with 5 rows each.

## Product notes

- The prototype filters in the browser over about 100 tickets. The product must page on the server:
  - Keyset pagination per step group, about 50 rows per group, then "Show more".
  - Search must use full-text search.
- "Whose turn" and wait times come from the turn read model, so the person grouping is one indexed query.
- The prototype computes rail counts from the loaded snapshot. In the product, take them from cached counters per project.
- Collapsed groups and the chosen view may be remembered per user (local preference).
