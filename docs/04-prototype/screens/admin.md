# Screen: Admin

Company-wide settings, one tab at a time. Only people with the Admin role see it.

## Index

- [Who sees it](#who-sees-it): the Admin role, and the tab rail.
- [Layout](#layout): rail on the left, tab content on the right.
- [Defaults](#defaults): the starting value of every setting.
- [People and roles](#people-and-roles): role help, invite, company roles, team role.
- [Teams](#teams): the team list and creating a team.
- [Statuses](#statuses): who may change steps, company steps, rules, manager preview.
- [AI](#ai): on/off, who, what is sent, summaries on sync, where AI runs, laptop model.
- [Pings](#pings): limit, auto-clear, quiet hours.
- [New tickets](#new-tickets): lead check switch, who checks tickets with no team, reasons.
- [Connections](#connections): Jira status mapping and company login.
- [Activity log](#activity-log): who changed what.
- [Data](#data): keep period and export.
- [Product notes](#product-notes): where the prototype simplifies.

## Who sees it

- Anyone with the Admin company role. The nav item is `Admin`.
- An admin who is also a team member keeps the work Home and gets the Admin card there.
- Every change here writes an activity-log line. Changes apply at once (no save button).

## Layout

```
┌ rail ────────────────┐ ┌ tab content (max ~760 px) ─────────────────────────┐
│ ACME FOODS · ADMIN   │ │ People and roles                                   │
│ [People and roles]   │ │ A role says what someone may do. The job (tester,  │
│  Teams               │ │ developer) is set by the team lead.                │
│  Statuses            │ │ ┌Admin┐┌Manager┐┌Lead┐┌Member┐┌Viewer┐ (help cards)│
│  AI                  │ │ [name@company.com            ] [Invite]            │
│  Pings               │ │ (LM) Lena  Team lead · developer · Lena’s team     │
│  New tickets         │ │        [Admin][Manager]  [Team lead ▾]             │
│  Connections         │ │ …                                                  │
│  Activity log        │ └────────────────────────────────────────────────────┘
│  Data                │
└──────────────────────┘
```

The rail uses `.rail` buttons. The pressed tab is filled.

## Defaults

| Setting | Default |
|---|---|
| Who may change steps | Company steps plus team steps |
| Rules (3) | all on |
| AI help | on |
| Who may use AI | Everyone |
| What goes to the AI | Title and ticket text |
| Summary on Jira sync | on |
| Where the AI runs | Company cloud AI |
| Ping limit | 4 h |
| Clear a ping when the person acts | on |
| Quiet hours | off |
| Every new ticket needs a lead check | off |
| Who checks tickets with no team | the team lead (Lena) |
| Company login (SSO) | not set up |
| Keep done tickets and screenshots | 2 years |

## People and roles

- Title `People and roles`. Sub: `A role says what someone may do. The job (tester, developer) is set by the team lead.`
- **Role help cards** (soft cards, name + text):
  - `Admin`: For the whole company. Sets up people, teams, statuses, AI and connections.
  - `Manager`: For the whole company. Sees every team in the five groups.
  - `Lead`: Per team. Runs it: projects, steps (if allowed), checks unclear new tickets.
  - `Member`: Per team. Works on tickets, reports, reviews.
  - `Viewer`: Per team. Can read and comment. Cannot move tickets.
- **Invite**:
  - An email field (placeholder `name@company.com`) and the button `Invite`. The button is disabled until the text looks like an email (`x@y.z`).
  - Toast `Invite sent to <email> (simulated).` Log `invited <email>`.
  - The invited person appears in the list as `Invited · not joined yet`.
- **People list** (bordered). One row per person:
  - Avatar and name.
  - Sub line: `<job> · <team>`, `Invited · not joined yet`, or (prototype) `From the Jira tickets`.
  - **Company role chips** `Admin` and `Manager` (toggle, multi-select; a person may have both or none). Logs `gave <name> the <role> role` / `removed the <role> role from <name>`.
  - **Team role** select: `Team lead`, `Team member`, `Team viewer`. Log `made <name> a <role> in <team>`.
- Footer note: Admin and Manager are for the whole company, and one person can have both. The team role is per team. (The rest of the note is prototype-only.)

## Teams

- Title `Teams`. Sub: `Each team has one lead. The lead links projects and people to the team.`
- A list of teams:
  - Name and the sub line `Lead: <name> · N people · SHOP, APP, UX, QA`, or `Lead: <name> · no projects yet` for a new team.
  - A tag `REAL` / `NEW` (prototype-only markers).
- **Create** (soft panel):
  - Field `New team name`, a lead select (people who have joined), and `Create team` (disabled while the name is empty).
  - Toast `Team created. <lead> can now link projects.` Log `created the team “<name>” with lead <lead>`.

## Statuses

- Title `Statuses`. Sub: `Every status is a step inside one of five fixed groups. Managers see the groups, so all teams read the same way.`
- **Who may change steps**: three option cards (`.opt`, pressed = primary border). Log `set “Who may change steps” to <title>`.
  - `Admin only`: One set of steps for everyone. Leads cannot change anything.
  - `Company steps plus team steps`: Leads can add steps inside the five groups. They cannot remove company steps.
  - `Team decides`: Leads add steps and set their own rules. Steps still sit in the five groups.
- **Company steps**:
  - Muted line: `Every team gets these. Teams map to them when they sync with Jira.`
  - Five group rows with step chips. Core steps are fixed. Company steps added here are removable (×). Log `removed the company step “<name>”`.
  - Add panel: field `New company step`, `in` + group chips (To do / Doing / Checking / Waiting), and `Add step`. Toast `Every team now has “<name>”.` Log `added the company step “<name>” to <group>`.
- **Rules**: three switches (same labels as the Team page). Note:
  - Under "Team decides": `These are the defaults. With “Team decides”, a lead can change them for the team.`
  - Otherwise: `These apply to every team.`
- **What a manager sees**: one bar per team (name + five-group bar with counts). Muted: `Three teams with different steps, one picture.` (The example teams are prototype-only.)

## AI

- Title `AI`. Sub: `These settings change what the ticket page offers right now.`
- Switch `AI help (summaries, “Explain it to me”, checking new tickets)`. Log `turned on|off AI help`.
- `Who may use it`: chips `Everyone` / `Leads only`. Log `allowed AI help for everyone|leads only`.
- `What goes to the AI`: chips `Title and ticket text` / `Title only`. Log `set what goes to the AI: …`.
- Switch `Write a summary for every ticket when it syncs from Jira`. Log `started|stopped summaries on sync`.
- `Where the AI runs`: chips. Log `set where AI runs: …`.

| Option | Note shown under it |
|---|---|
| `Company cloud AI` | `Every AI request goes to the company’s cloud AI. It costs money per use.` |
| `Each person’s laptop (Ollama)` | `Free. Ticket text never leaves the laptop. People without Ollama get no AI features.` |
| `Laptop first, cloud when it is off` | `Free when Ollama runs. Otherwise pinround uses the cloud AI, so nobody is left without AI.` |

- Card `Model for laptops`:
  - `qwen3:4b · about 2.5 GB · runs on the processor, no graphics card needed · good at returning the JSON pinround asks for.`
  - `Lighter: phi4-mini. Test both on your slowest laptop before you choose.`
- Footnote: `The real app uses Symfony AI: the Ollama bridge for laptops, and a cloud bridge (Claude or Hugging Face) for the rest.` (The prototype adds that "cloud" means the viewer's Claude.)

## Pings

- Title `Pings`. Sub: `Limits keep pings useful and stop spam.`
- `One ping per person per ticket every`: chips `1 h`, `4 h`, `8 h`, `24 h`. Log `set the ping limit to N h`.
- Switch `Clear a ping when the person acts on that ticket`. Log `turned on|off auto-clear for pings`.
- Switch `Quiet hours: hold pings between 18:00 and 09:00`. Log `turned on|off quiet hours`.

## New tickets

- Title `New tickets`. Sub: `Anyone can report a problem, and it becomes a ticket right away. A lead takes a quick look only when something is unclear.`
- Switch `Every new ticket needs a lead check before it is given to anyone`. Log `turned on|off: every new ticket needs a lead check`.
- Muted: `Off is the default. Turn it on only if your teams get many reports from outside, for example from support.`
- Select `Who checks tickets with no team` (people who have joined). Log `chose <name> to check tickets with no team`.
- Card `A lead only gets a new ticket to check when`:
  - **Maybe the same as an older ticket**: AI or simple word matching found a similar open ticket, and the reporter made a new one anyway.
  - **Missing info**: Very short text and no pins on a screenshot.
  - **No team**: The reporter picked “Not sure”. The person below checks it.
  - **Risky**: Words like security, leak, password, data loss, crash. Security words also hide the ticket from people outside the team.
  - **Company rule**: Only when the switch above is on.
  - Muted: `The lead of the ticket’s team checks it. If it was given to someone, that person can start at the same time.`

## Connections

- Title `Connections`.
- **Jira card**:
  - `Jira`, a green tag `CONNECTED`, and muted `<acme Jira Cloud site> · last sync 5 Nov 2026 · 41 projects`.
  - `How Jira statuses land in pinround steps:` then a 3-column list: Jira status → step → group (muted).

| Jira status | Step | Group |
|---|---|---|
| Backlog, Ready for planning, Specification | Backlog | To do |
| To Do, Awaiting implementation | To do | To do |
| In Progress | In progress | Doing |
| Code Review Phase, Code Merge, Review | Code review | Checking |
| Testing, Test phase, Untested | Testing | Checking |
| Input (internal), Input Customer, Approval product owner, Approval technical owner | Needs input | Waiting |
| On Hold | On hold | Waiting |
| Closed | Done | Done |

  - Unknown statuses fall back by Jira category: done → Done, in progress → In progress, else To do.
- **Company login card**:
  - Not set up: `Not set up. People sign in with email.` Button `Set up`.
  - Set up: `On · people sign in with the company Microsoft account (simulated).` Button `Turn off`.
  - Log `set up|turned off company login`.

## Activity log

- Title `Activity log`. Sub (prototype): `Who changed what. It fills as you use the prototype.`
- Empty: `Nothing yet. Move a ticket or change a setting.`
- Rows, newest first: `#N · <who> <text>`, plus ` (<KEY>)` when the text does not already name the ticket.

## Data

- Title `Data`.
- `Keep done tickets and screenshots for`: chips `1 year`, `2 years`, `Always`. Log `set data keeping to <choice>`.
- Outline button `Export all data`. Toast `Export started (simulated). You get an email with the file.`

## Product notes

- Admin changes must be audited with actor, time, old value and new value. Admins need MFA.
- Removing your own last Admin role must be blocked (at least one admin must remain).
- Team role changes need a team picker when a person is in several teams. The prototype has one team.
- The mapping table must be editable per Jira connection (Phase: integrations). The prototype shows it read-only.
- Retention must purge done tickets, screenshots (object storage) and audit data on schedule, and show what will be deleted before a change that shortens the period.
- "Export all data" runs in the background, produces a signed, expiring download, and is logged.
