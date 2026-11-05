# Screen: Team

One team's page: its projects, how work moves through its steps, and who has how much waiting on them.

## Index

- [Who sees it](#who-sees-it): team members, and what the lead can do.
- [Wireframe](#wireframe): the two columns.
- [Projects](#projects): linked projects, adding and removing them.
- [How work moves here](#how-work-moves-here): the policy text, steps per group, adding team steps, rules.
- [People and workload](#people-and-workload): load bars, search, and show all.
- [Product notes](#product-notes): where the prototype simplifies.

## Who sees it

- Team members (nav `Team`).
- Only the **team lead** can add or remove projects, add or remove team steps (if the admin policy allows), and change rules (only under the "Team decides" policy).

## Wireframe

```
Lena’s team                                                          (h1 30 px)
You lead this team. You link its projects and add its own steps.

┌ left ───────────────────────────────────────────┐ ┌ right ───────────────────┐
│ Projects 4                          [Add project]│ │ People 3   waiting on them│
│ SHOP  Shop                        See tickets Remove│ [Find a person]  (big team)│
│       95 tickets in the snapshot                │ │ (LM) Lena (you)       30  │
│ APP   Mobile app …                              │ │      [██████████░] Team lead · developer│
│                                                 │ │ (MB) Mia Berg         14  │
│ How work moves here                             │ │ (OK) Omar Khan         9  │
│ Your admin set the company steps. As lead, Lena │ │ Show all 16               │
│ can add team steps inside the five groups.      │ │ Counts are open tickets … │
│ ● To do     [Backlog] [To do]                   │ └───────────────────────────┘
│ ● Doing     [In progress] [Ready to merge ×]    │
│ ● Checking  [Code review] [Testing]             │
│ ● Waiting   [Needs input] [On hold]             │
│ ● Done      [Done]                              │
│ ┌ [New step, for example: Ready to merge] in [To do][Doing][Checking][Waiting] [Add step]┐
│ Rules                                           │
│  On  Only the tester can move a ticket to Done · set by the admin               │
│  On  Nobody approves their own code review · set by the admin                   │
│  On  Code review must happen before testing · set by the admin                  │
└─────────────────────────────────────────────────┘
```

## Projects

- Title `Projects N`. The lead also sees a filled small button `Add project`.
- One row per linked project:
  - Key (mono) and name.
  - A muted note: `N tickets in the snapshot` (in the product: open tickets count), or `Just added · tickets load on the next Jira sync`.
  - `See tickets` opens Tickets filtered to that project.
  - `Remove` (lead) unlinks the project. Toast `<KEY> removed from the team`.
- **Add project** opens a dialog ([dialogs-and-popovers.md](dialogs-and-popovers.md#add-a-project)) listing the Jira site's projects that are not linked yet.

## How work moves here

- Title `How work moves here`.
- **Policy text** (set by the admin under Statuses):
  - Admin only: `Your admin set these steps for the whole company. Teams cannot change them.`
  - Company steps plus team steps: `Your admin set the company steps. As lead, Lena can add team steps inside the five groups.`
  - Team decides: `Your admin lets each lead shape the team’s steps and rules. Every step still sits in one of the five groups.`
- **Steps per group**: five rows (group dot + label), each with step chips (30 px, 8 px radius).
  - Core and company steps: `--s-high` background, not removable.
  - Team steps: `--secondary-container` background. The lead gets a `×` (aria "Remove step <name>") when the policy is not "Admin only".
  - Removing writes the log line `removed the team step “<name>”`.
- Step order inside a group: core steps, then company steps, then team steps.
- **Add a team step** (lead, policy not "Admin only"), in a soft panel:
  - Field placeholder `New step, for example: Ready to merge`.
  - `in` and group chips `To do`, `Doing`, `Checking`, `Waiting`. Done cannot get extra steps. The default group is Checking.
  - `Add step`, disabled while the name is empty.
  - Toast `Added “<name>” to <group>. Managers still see it as <group>.` Log `added the team step “<name>” to <group>`.
- **Rules** (h3 `Rules`), one line each:
  - `Only the tester can move a ticket to Done`.
  - `Nobody approves their own code review`.
  - `Code review must happen before testing`.
  - Under the "Team decides" policy the lead gets a switch for each rule. The log records `turned on: …` / `turned off: …`.
  - Under any other policy the line shows a bold `On`/`Off` mark (green when on) and ` · set by the admin`.
- How rules change the ticket page is described in [ticket-page.md](ticket-page.md#your-next-step-box).
- A custom step behaves like its group:
  - Turn rules use the group.
  - Its verb is `Move on to <next core step>`.

## People and workload

- Title `People N` and muted `waiting on them`.
- With a big team: a search field `Find a person`. It filters by name and shows all matches.
- Rows are sorted by load, highest first:
  - Avatar, name (with `(you)`), and the load number.
  - A meter: width = load ÷ highest load in the team.
    - Red when the load is 20 or more.
    - `--waiting` when it is 8 or more.
    - Otherwise `--primary`.
  - Job line, for example `Team lead · developer`, `Tester`, `Developer`.
- **Load** = open tickets where it is this person's turn (same rules as "Whose turn?").
- Shows 6 people, then `Show all N` / `Show fewer`.
- Note (muted): `Counts are open tickets where it is this person’s turn. Red means too much.` With the simulated big team, the note says the extra people are made up.

## Product notes

- The intro line for non-leads: `<Lead> leads this team. Only the team lead can change projects and steps.`
- Projects come from the connected Jira site. Unlinking a project must not delete tickets. It hides them from this team's views (decide archive behaviour in the plan).
- Removing a team step that still has tickets must ask where to move those tickets (not in the prototype).
- A person in several teams sees a team switcher or one section per team (not in the prototype; the product needs it).
