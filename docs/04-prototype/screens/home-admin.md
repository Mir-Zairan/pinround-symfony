# Screen: Home for admin-only users

A short overview for people who set up the company but do not work on tickets.

## Who sees it

- Users with the Admin company role and no team role.
- A team member who is also an admin keeps the work Home with an "Admin" card ([home-work.md](home-work.md#right-column)).

## Wireframe

```
Your organization                                           (h1, 30 px)
┌ People ─────┐ ┌ Teams ──────┐ ┌ AI help ────┐ ┌ Jira ───────┐
│ 16          │ │ 1           │ │ On          │ │ Connected   │
└─────────────┘ └─────────────┘ └─────────────┘ └─────────────┘
Admins do not work on tickets. Everything you set is under Admin.
[Open admin settings]
```

## Elements and rules

- Four stat cards (class `.card`, each a button):
  - Label (muted) and a value (20 px bold).
  - `People` = number of people in the org, including invited. Opens Admin → People and roles.
  - `Teams` = number of teams. Opens Admin → Teams.
  - `AI help` = `On` or `Off`. Opens Admin → AI.
  - `Jira` = `Connected`. Opens Admin → Connections.
- Text: `Admins do not work on tickets. Everything you set is under Admin.`
- Filled button `Open admin settings` → Admin, People tab.
- Navigation for this user: `Home`, `Admin` only. No Tickets, no New report, no bell.

## Product notes

- The Jira card should show the real connection state: `Connected`, `Not connected`, or `Sync failing`.
- Consider adding the same alert list as the admin card (invites pending, SSO not set up, AI on laptops), so admin-only users see what needs them. The prototype shows alerts only in the work Home's admin card.
