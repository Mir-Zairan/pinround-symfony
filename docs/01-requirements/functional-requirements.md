# Functional requirements

The contract of what pinround shall do. Phase plans cite these IDs; tests prove them.

## Index

- [How to read this document](#how-to-read-this-document): ID scheme, priority, 🏁 flag, prototype references.
- [ID — Identity and access](#id--identity-and-access): login, invites, MFA, SSO, tokens, sessions (FR-ID-01…08).
- [ORG — Organization, teams and roles](#org--organization-teams-and-roles): orgs, teams, memberships, roles, projects, team page (FR-ORG-01…09).
- [WF — Workflow, steps and turns](#wf--workflow-steps-and-turns): five groups, steps, policy, rules, verbs, turn rules, Now/Next (FR-WF-01…17).
- [TKT — Tickets](#tkt--tickets): fields, list, Whose turn?, filters, ticket page, queue (FR-TKT-01…18).
- [INT — Intake and checks](#int--intake-and-checks): report a problem, duplicates, check reasons, checker, visibility (FR-INT-01…15).
- [CNV — Conversation](#cnv--conversation): comments, questions, answers, replies, resolve (FR-CNV-01…08).
- [PNG — Pings and notifications](#png--pings-and-notifications): ping, limits, grouping, bell, auto-clear, quiet hours, live updates (FR-PNG-01…11).
- [RND — Screenshots, pins and rounds](#rnd--screenshots-pins-and-rounds): uploads, pins, Round 0/1, review rounds, draft rounds, findings, live pins, tries, one open round per ticket, one-click connect (FR-RND-01…21).
- [HOME — Home page](#home--home-page): headline, needs-you score, groups, pings, focus chips, admin card (FR-HOME-01…11).
- [MINE — My reports](#mine--my-reports): parcel tracking, groups, inline answer, menu (FR-MINE-01…08).
- [MGR — Manager overview](#mgr--manager-overview): teams at a glance (FR-MGR-01…04).
- [AI — AI assist](#ai--ai-assist): policy, providers, summaries, explanations, suggestions, laptop AI (FR-AI-01…18).
- [MCP — MCP server and agent API](#mcp--mcp-server-and-agent-api): tools and prompts for Claude Code, REST API (FR-MCP-01…14).
- [EXP — Explore: notebooks and scenarios](#exp--explore-notebooks-and-scenarios): private pins for questions, scenarios played live (FR-EXP-01…06).
- [JIRA — Jira integration](#jira--jira-integration): connect, import, sync, mapping (FR-JIRA-01…09).
- [ADM — Admin](#adm--admin): admin pages (FR-ADM-01…11).
- [AUD — Audit and data](#aud--audit-and-data): audit log, retention, export (FR-AUD-01…05).
- [Traceability notes](#traceability-notes): how requirements map to phases and tests.

## How to read this document

- **ID:** `FR-<AREA>-NN`. IDs never change meaning. A removed requirement is marked *Withdrawn*, not deleted.
- **Priority:** **Must** (v1 cannot ship without it), **Should** (v1 target, can slip), **Could** (later).
- **🏁** marks requirements in the hackathon slice (deadline 22 Nov 2026, 18:00 CET). For 🏁 items the hackathon needs only the parts named in the 🏁 note.
- **Prototype:** where to see it. `Main › Home` means the Main board, Home view. Read the [prototype guide](../04-prototype/prototype-guide.md).
- **Accept:** acceptance criteria. Each must become at least one automated test.
- Words follow the [glossary](../00-product/glossary.md). Who may do what is in the [permission matrix](../00-product/personas-and-roles.md#permission-matrix). Non-functional targets are in [non-functional-requirements.md](non-functional-requirements.md).

---

## ID — Identity and access

*Story:* Sara invites Omar by email. Omar sets a password and lands on his Home. Sara herself must use a code from her phone, because admins hold the keys to everything. Later the company switches on Microsoft login and nobody needs a pinround password any more. Omar creates a token so Claude Code can read his draft rounds.

**FR-ID-01 — Email and password login** · Must · 🏁 (login only) · Prototype: not shown
The system shall let a user log in with email and password. Passwords are hashed with Argon2id ([ADR-0012](../03-adr/0012-authentication.md)).
- Accept: wrong password shows one neutral message ("Email or password is wrong") for unknown email and wrong password alike.
- Accept: after 5 failed tries in 15 minutes for one account or IP, further tries are slowed/blocked for 15 minutes.
- Accept: a logged-in user who opens `/login` goes to Home.

**FR-ID-02 — Invitations** · Must · Prototype: `Main › Admin › People and roles` (invite)
An admin shall invite a person by email. The link is single-use and expires after 72 hours.
- Accept: accepting creates the user (or links an existing user) and the org membership with the roles chosen at invite.
- Accept: an expired or used link shows "This invite is no longer valid. Ask your admin for a new one."
- Accept: pending invites are listed as "Invited · not joined yet" and count in the admin alerts.

**FR-ID-03 — Two-step login (TOTP)** · Must · Prototype: not shown
Users can turn on TOTP codes. Users with the Admin role must have it on before they can open admin pages. The one exception is dev tooling, not a product feature: on a local `dev` demo, `PINROUND_DEMO_SKIP_2FA` skips two-step login, and the app refuses to boot with it in any other environment ([ADR-0049](../03-adr/0049-demo-switch-for-two-step-login.md), [config.md](../operations/config.md)).
- Accept: an admin without TOTP is sent to set it up when opening any admin page.
- Accept: 10 one-time recovery codes are shown once at setup.

**FR-ID-04 — Company login (OIDC SSO)** · Should · Prototype: `Main › Admin › Connections` (company login)
An admin shall connect an OIDC provider (Microsoft Entra ID, Google). Users then log in through it.
- Accept: Authorization Code flow with PKCE. SSO identities are keyed by (provider config, `iss`, `sub`), never matched by email ([ADR-0012](../03-adr/0012-authentication.md)).
- Accept: Given Sara has a password account, When an SSO login asserts her email for the first time, Then no session is opened for her account; linking needs her password + MFA.
- Accept: a session opened through org X's provider opens only org X; allowed email domains work only after DNS TXT proof.
- Accept: admin can require SSO for everyone except a break-glass admin account.

**FR-ID-05 — Personal access tokens** · Must · 🏁 · Prototype: not shown
A user shall create named tokens with scopes (`read`, `rounds:write`, `comments:write`, `tickets:move`) and an expiry (default 90 days, max 1 year) for the MCP server and API. Tokens start with `pr_`; only a SHA-256 hash is stored. A token made for Claude Code defaults to scopes `read` + `rounds:write`.
- Accept: the token is shown once; only a hash is stored.
- Accept: the user can list (name, scopes, last used) and revoke tokens; revoked tokens fail at once.
- Accept: creating a token asks for password + MFA again if the last check is older than 10 minutes.
- Accept: a request authenticated by a token gets 403 on every admin page and admin action.
- Accept: a token can do only what its user can do, limited further by its scopes.

**FR-ID-06 — Sessions** · Must · Prototype: not shown
- Accept: session ID rotates at login and privilege change; logout ends the session server-side.
- Accept: idle sessions expire after 8 hours, every session ends after 30 days at most; admin pages ask for MFA again after 1 hour (step-up).
- Accept: "Log out of all devices" ends all sessions of the user.

**FR-ID-07 — Profile settings** · Should · Prototype: header avatar
A user sets display name, time zone (used for quiet hours), and theme (system / light / dark).

**FR-ID-08 — Password reset** · Must · Prototype: not shown
- Accept: reset link by email, single-use, valid 1 hour; the response does not reveal whether the email exists.

---

## ORG — Organization, teams and roles

*Story:* Acme Foods signs up. Sara creates the shop team with Lena as lead, links project SHOP, and adds Omar (developer) and Mia (tester). Raj gets the Manager role. Lena is also made admin. Lena's Home now shows her work, her team, and a small admin card.

**FR-ORG-01 — Organization** · Must · 🏁 · Prototype: `Main › Admin home`
The system shall hold all data inside one organization. A user can belong to several organizations and picks one after login.
- Accept: no screen, API or MCP tool can show data from another organization (tested per [NFR-SEC-03](non-functional-requirements.md)).

**FR-ORG-02 — Teams** · Must · 🏁 · Prototype: `Main › Admin › Teams`
An admin shall create a team with a name and exactly one lead.
- Accept: a team cannot exist without a lead; changing the lead is logged.

**FR-ORG-03 — Team membership, team role and job** · Must · 🏁 · Prototype: `Main › Admin › People and roles`
Each membership has a team role (Lead, Member, Viewer) and a job (tester, developer, other). A person can be in many teams with different roles.

**FR-ORG-04 — Organization roles** · Must · Prototype: `Main › Admin › People and roles` (Admin / Manager chips)
An admin shall give or remove the org roles Admin and Manager for any person. Both can be held at once, together with any team roles.
- Accept: the last admin cannot remove their own Admin role.
- Accept: role changes take effect on the next request and are in the audit log.
- Accept: Given Sara is Admin with no team role, When she opens a ticket, Then she can see it (also team-only), comment, ask a question and ping; Move, Give it to, Plan it and field edits return 403.
- Accept: Given Raj is Manager, Then he can comment, ask and ping on tickets he may see, but not move them.

**FR-ORG-05 — Navigation by roles** · Must · 🏁 (Home, Tickets) · Prototype: header nav
The nav shows Home, Tickets (not for pure admins), My reports and Team (people with a team role), Admin (admins). A "Report a problem" button is shown to everyone with a team role.

**FR-ORG-06 — Projects** · Must · 🏁 · Prototype: `Main › Team` (projects)
A project has a unique key per organization (2–10 capital letters/digits, starts with a letter), a name and at most one owning team; the owning team's lead can unlink it while it has no open tickets, and a team's lead (for their own team) or an admin can link an unlinked project. Ticket keys are `KEY-n` with `n` counting up per project without gaps from concurrency.
- Accept: two tickets created at the same moment never get the same number.

**FR-ORG-07 — Team page** · Should · Prototype: `Main › Team`
Shows: linked projects (with ticket counts, link to list, remove), "How work moves here" (policy text, steps per group, add/remove team step when allowed, rules), and People with workload bars, search and "Show all".
- Accept: only the lead sees add/remove controls; others see "Only the team lead can change projects and steps."

**FR-ORG-08 — Big teams** · Must · Prototype: `Main` header "Big team" toggle (prototype device)
- Accept: with more than 4 people, people chips are replaced by "Person ▾" opening a picker with search, sorted by open tickets.
- Accept: avatar stacks show at most 4 avatars plus "+N".
- Accept: the People list shows 6 by default, "Show all N", and a search box.

**FR-ORG-09 — Triager** · Must · Prototype: `Main › Admin › New tickets`
An admin shall choose one person who checks new tickets with no team. Default: the first admin.

---

## WF — Workflow, steps and turns

*Story:* Lena's team wants a "Design check" step before testing. The admin policy allows team steps, so Lena adds it inside the Checking group. Raj still sees the team in the same five groups as every other team. Omar presses "Send to code review"; the ticket says "Now: Lena reviews the code. Next: Mia tests it."

**FR-WF-01 — Five fixed groups** · Must · 🏁 · Prototype: `Main › Tickets`, `Main › Manager home`
Groups are To do, Doing, Checking, Waiting, Done, with the fixed colors in [ux-principles.md](../00-product/ux-principles.md#the-five-group-colors). They cannot be renamed, added or removed.

**FR-WF-02 — Core steps** · Must · 🏁 · Prototype: `Main › Team` (How work moves here)
Every organization has: Backlog and To do (To do); In progress (Doing); Code review and Testing (Checking); Needs input and On hold (Waiting); Done (Done). Core steps cannot be removed.

**FR-WF-03 — Company steps** · Should · Prototype: `Main › Admin › Statuses`
An admin can add steps for all teams, each inside one group (not Done). They can be removed only when no ticket is in them.

**FR-WF-04 — Team steps** · Should · Prototype: `Main › Team`
A lead can add/remove steps for their team inside one group, when the policy is *extend* or *team*.
- Accept: a team step in Doing/Checking/Waiting has a "Move on to <next core step>" action (Doing → Code review, Checking → Testing, Waiting → previous step or In progress, To do → In progress).

**FR-WF-05 — Step policy** · Must · Prototype: `Main › Admin › Statuses` (policy cards)
Admin chooses: **Admin only** (locked; teams see company steps only), **Company steps plus team steps** (extend; default), **Team decides** (team; leads also set the team's rules).
- Accept: switching to locked hides team steps; tickets in them show their group and the lead is asked to move them (no data loss).

**FR-WF-06 — Rules** · Must · Prototype: `Main › Admin › Statuses`, `Main › Team`
Three switchable rules, all on by default: (1) only the tester moves a ticket to Done; (2) nobody approves their own code review; (3) code review must happen before testing. Under policy *team* the lead may change them per team; otherwise they are company-wide.
- Accept: blocked actions are hidden and a rule note explains why ("Team rule: nobody approves their own code. Lena reviews it.").

**FR-WF-07 — Actions as verbs** · Must · 🏁 (Start work, Send to code review, Approve code, Pass it) · Prototype: `Main › Ticket` (YOUR NEXT STEP)
The system shall offer these actions, shown only to people allowed by the [permission matrix](../00-product/personas-and-roles.md#permission-matrix) and rules:

| Action | From → to | Who |
|---|---|---|
| Plan it | Backlog → To do | Lead |
| Give it to… / Give it to someone else… | (assignee change; Backlog → To do) | Lead |
| Start work | To do → In progress | Assignee |
| Send to code review | In progress → Code review | Assignee (or lead if no assignee) |
| Send to testing | In progress → Testing | Assignee, only if rule 3 is off |
| Approve code | Code review → Testing | Reviewer (not the author if rule 2) |
| Ask for changes | Code review → In progress | Reviewer |
| Pass it | Testing → Done | Tester (or lead if rule 1 off) |
| Needs changes | Testing → In progress | Tester |
| Input given · go on | Needs input → previous step (or To do) | Person whose turn it is, or lead |
| Put on hold | any (not Done/On hold) → On hold | Lead |
| Go on | On hold → previous step (or In progress) | Lead |
| Reopen | Done → In progress | Lead, tester |
| Move on to <step> | team/company step → next core step | Assignee or lead |

- Accept: each action runs in one transaction, records an event, updates the turn, and shows a toast that names who acts next ("Sent to code review. Lena's turn now.").

**FR-WF-08 — Turn rules (whose turn)** · Must · 🏁 · Prototype: `Main › Ticket` (Now), `Main › Tickets › Whose turn?`
The person whose turn it is, in this order:
1. An open question → the person asked.
2. A new ticket that needs a check and has no assignee → the checker ([FR-INT-10](#int--intake-and-checks)).
3. Done → nobody.
4. Backlog or On hold → the team lead.
5. Code review → the reviewer (`reviewer_id`, [FR-WF-14](#wf--workflow-steps-and-turns)).
6. Testing (or any company/team step in the Checking group) → the tester (`tester_id`, [FR-WF-14](#wf--workflow-steps-and-turns)).
7. Needs input → the person or label it waits on ([FR-WF-15](#wf--workflow-steps-and-turns)); default the reporter.
8. Otherwise → the assignee, or the lead when nobody is assigned.
- Accept: the turn is stored per ticket and updated in the same transaction as the change ([ADR-0015](../03-adr/0015-turn-read-model.md)).

**FR-WF-09 — Now and Next lines** · Must · 🏁 · Prototype: `Main › Ticket` header
- Accept: "Now:" is generated from the turn: "Omar builds it", "Lena reviews the code", "Mia tests it", "Waiting for input from Ben", "Lena decides if it goes on", "Lena plans it", "Lena picks a developer", "Lena takes a quick look at the new ticket", "Mia answers Lena's question", "Done. Nobody needs to act."
- Accept: "Next:" for open work: e.g. from To do "Omar builds it, Lena reviews, Mia tests"; from In progress "Lena reviews the code, then Mia tests it"; from Code review "Mia tests it"; from Testing "Done"; from a question "it goes back to <step>".

**FR-WF-10 — Move to another step** · Must · Prototype: `Main › Ticket` (Move to another step…)
A lead can move a ticket to any step, shown grouped by the five groups, current step marked.
- Accept: rule 3 still blocks In progress → Testing with the message "Team rule: code review comes before testing."

**FR-WF-11 — Every change is an event** · Must · 🏁 · Prototype: `Main › Ticket` (What happened)
Moves, assignments, checks, questions, answers and verdicts are stored as ticket events with actor and time.

**FR-WF-12 — Questions pause** · Must · Prototype: `Main › Ticket`
While a question is open the status pill shows "Waiting · question to <name>" in waiting colors and no move actions are offered (rule note "Paused until Mia answers the question below.").

**FR-WF-13 — Steps in the five groups for reports** · Must · Prototype: `Main › Manager home`
Every count shown to managers is per group, whatever the team's steps are.

**FR-WF-14 — Reviewer and tester choice** · Must · Prototype: `Main › Ticket` (People) · [ticket-page.md](../04-prototype/screens/ticket-page.md)
When a ticket enters Code review the system shall set `reviewer_id`: the team lead, or — when the author (assignee) is the lead — the other developer of the team with the fewest open reviews. When it enters Testing the system shall set `tester_id`: the team tester (job tester) with the fewest tickets in Testing. Both are stored on the ticket and can be changed by the lead.
- Accept: Given Lena (lead) is the assignee, When she sends to code review, Then the reviewer is the developer with the fewest open reviews, not Lena.
- Accept: Given the team has no tester, When a ticket enters Testing, Then `tester_id` stays empty and the turn goes to the lead.
- Accept: Given the lead is the author and the team has no other developer, Then the lead reviews; this override is written to the ticket history.
- Accept: Given two testers with 3 and 5 tickets in Testing, When a ticket enters Testing, Then the tester with 3 is set.
- Accept: Given the lead changes the reviewer, Then the turn and Now line update in the same transaction.

**FR-WF-15 — Needs input waits on a person or a label** · Must · Prototype: `Main › Ticket` (Now: "Waiting for input from …") · [ticket-page.md](../04-prototype/screens/ticket-page.md)
When a ticket moves to Needs input the mover chooses who it waits on: a person (stored as `waiting_on_user_id`) or a label (`waiting_on_label`: Customer, Product owner, Technical owner). Default: the reporter. For Jira-synced tickets the default comes from the status mapping; an integration (help-desk) reporter counts as "Customer".
- Accept: Given it waits on a label, Then Now says "Waiting for input from Customer" and no Ping button is offered for the label ([FR-PNG-11](#png--pings-and-notifications)).
- Accept: Given it waits on a person, Then it is that person's turn and it shows on their Home as kind `input`.

**FR-WF-16 — Move side effects** · Must · Prototype: `Main › Ticket` (YOUR NEXT STEP, Move to another step…) · [ticket-page.md](../04-prototype/screens/ticket-page.md)
The system shall apply these fixed effects:
- Giving a Backlog ticket to someone also moves it to To do.
- "Input given · go on" returns to the previous step, else To do.
- "Go on" (from On hold) returns to the previous step, else In progress.
- "Move on to <next core step>" from a company/team step maps by group: To do → In progress, Doing → Code review, Checking → Testing, Waiting → the previous step (else In progress).
- With rule 3 (review first) on, In progress → Testing is refused, also in "Move to another step…" ("Team rule: code review comes before testing.").
- Accept: one test per bullet; `previous_step_id` is stored on every move.

**FR-WF-17 — One action path** · Must · Prototype: toasts after every action · [ticket-page.md](../04-prototype/screens/ticket-page.md)
Every ticket action (move, assign, answer, verdict, checklist change, check, comment by the recipient of pings) shall run through one application path that: (1) clears the actor's own pings on that ticket when auto-clear is on, (2) writes one activity line, (3) returns a toast that ends with "N pings on this ticket cleared." when N > 0.
- Accept: Given Omar has 2 pings from Mia on SHOP-88, When he marks a checklist pin fixed, Then both clear and the toast says "… 2 pings on this ticket cleared."
- Accept: Given auto-clear is off, Then no ping clears.

---

## TKT — Tickets

*Story:* Omar opens Tickets. He sees open tickets grouped by step, each with "Turn: you" or "Turn: Mia". He switches to "Whose turn?" and sees Mia has 6 tickets waiting, the oldest for 12 days. He searches "invoice" and opens SHOP-41.

**FR-TKT-01 — Ticket data** · Must · 🏁 · Prototype: `Main › Ticket` (facts)
A ticket has: key, title (1–200 chars), description (text, max 50,000 chars), type (Bug/Story/Task), priority (Blocker/Critical/Major/Normal/Minor), project, team, step, assignee (optional), reporter, release (optional), parent (optional), components (optional), visibility (all/team), created and updated time.

**FR-TKT-02 — List grouped by step** · Must · 🏁 · Prototype: `Main › Tickets › List`
- Accept: open tickets grouped by step in this group order: Waiting, Checking, Doing, To do, Done (Done only with "Show done"); inside a step by priority, then last update.
- Accept: each group header shows step name, group tag, count, hint ("Someone must answer first", "Being checked", "Being built", "Not started"), and can collapse.
- Accept: each row shows key, title, meta (type · release · "Turn: you/<first name>" · "To check by <name>"), priority, assignee avatar, last update ("now" if moved in pinround).

**FR-TKT-03 — Whose turn? view** · Must · Prototype: `Main › Tickets › Whose turn?`
- Accept: open tickets grouped by turn person; "You" first, then by oldest wait.
- Accept: each group: avatar, sentence "6 tickets wait on Mia · the oldest for 12 days" (+ "· outside the team" if not a team member), a meter of the oldest wait (full at 60 days; amber > 10, red > 30 days), the 5 oldest tickets with the verb for that person ("Test it") and wait time, and "Show N more".

**FR-TKT-04 — Project rail** · Should · Prototype: `Main › Tickets`
Left rail: "All projects" plus each linked project with open counts; "Manage projects" link.

**FR-TKT-05 — Search** · Must · 🏁 · Prototype: `Main › Tickets` search
Search by key or words in title and description, within the current filters ([ADR-0022](../03-adr/0022-search-and-duplicates.md)).

**FR-TKT-06 — People filter** · Must · Prototype: `Main › Tickets`
Chips: Everyone, Me, and up to 4 team members; for bigger teams a "Person ▾" picker ([FR-ORG-08](#org--organization-teams-and-roles)).

**FR-TKT-07 — Show done and To-check filters** · Must · Prototype: `Main › Tickets`
"Show done" toggle; "New · to check · N" chip shown when N > 0, filters to tickets that still need a check.

**FR-TKT-08 — Ticket page header** · Must · 🏁 · Prototype: `Main › Ticket`
Back link (to where you came from), key, type, project, "ONLY THE TEAM AND ADMINS SEE THIS" tag for team-only tickets, title, status pill, Now/Next, a note line ("Moved in pinround just now. It syncs back to Jira." / "Jira status: … · updated 5 Nov" / "Reported in pinround" · "Group: Checking"), and position "3 of 41" with Previous/Next.

**FR-TKT-09 — The ask** · Must · 🏁 (description shown) · Prototype: `Main › Ticket` (The ask)
Shows the summary ("In short") when it exists, else the description (first 700 chars with "Show the full ask"). With a summary: "Show original text" toggles the full description. See [FR-AI-03](#ai--ai-assist)…[FR-AI-06](#ai--ai-assist).

**FR-TKT-10 — Your next step box** · Must · 🏁 · Prototype: `Main › Ticket` (YOUR NEXT STEP)
- Accept: shows one sentence for the viewer ("Test it." / "Nothing for you now. Lena's turn." / "Answer Mia's question below." / "Take a quick look at this new ticket.") and the allowed action buttons (first one filled).
- Accept: shows a rule note when a rule hides an action.
- Accept: managers see "Managers can read and comment. Only the team moves tickets."

**FR-TKT-11 — People on a ticket** · Must · Prototype: `Main › Ticket` (People)
Lists assignee, reporter, reviewer (during In progress/Code review), team tester (open tickets), each with role, "· their turn" marker and a Ping button ([FR-PNG-01](#png--pings-and-notifications)).

**FR-TKT-12 — What happened** · Must · Prototype: `Main › Ticket` (activity)
Latest events (6) plus created, newest first, in sentences ("Lena gave it to Omar").

**FR-TKT-13 — Facts** · Must · Prototype: `Main › Ticket`
Assignee, priority, release, parent, components, created; empty facts are hidden.

**FR-TKT-14 — Previous/Next** · Should · Prototype: `Main › Ticket`
Moves through the list the user came from (same filters and order).

**FR-TKT-15 — Queue walk-through** · Must · Prototype: `Main › Home` "Start reviews" → ticket queue bar
Starting a group from Home opens its first ticket with a bar "Code reviews · 2 of 4" and Previous / Next ticket / Finish / Stop. Finish returns Home with a toast "All done in Code reviews."

**FR-TKT-16 — Give it to (person picker)** · Must · Prototype: `Main › Ticket` → picker
Picker lists team members who can be given work (not viewers), sorted by how much already waits on them (fewest first), with search.
- Accept: giving a Backlog ticket also moves it to To do.

**FR-TKT-17 — Link to Jira** · Should · Prototype: `Main › Ticket` facts
Tickets synced from Jira show a link to the Jira issue.

**FR-TKT-18 — List ordering and thresholds** · Must · Prototype: `Main › Tickets`, `Main › Team` · [tickets-list-and-whose-turn.md](../04-prototype/screens/tickets-list-and-whose-turn.md), [team.md](../04-prototype/screens/team.md)
The system shall use these fixed orders and thresholds:
- List: groups in the order Waiting, Checking, Doing, To do, Done, with the fixed hints of [FR-TKT-02](#tkt--tickets); rows by priority, then last update (newest first).
- Whose turn?: "You" first, then by oldest wait; meter full at 60 days, amber above 10 days, red above 30 days; 5 rows per person, then "Show N more".
- Workload bars (Team page, person picker): amber at 8 or more, red at 20 or more open turns; the Team page shows 6 people, then "Show all N".
- Accept: Given a person with 25 open turns, Then their workload bar is red.
- Accept: Given 7 team members, Then the Team page shows 6 and "Show all 7".

---

## INT — Intake and checks

*Story:* Ben from support hears that customers see a total that is too high. He clicks "Report a problem", captures the page and drops one pin. He does not know the team, so he picks "Not sure". The preview says "Sara takes a quick look, because no team was picked." He presses "Create ticket". It is a real ticket at once.

**FR-INT-01 — Anyone can report** · Must · 🏁 (simple create form) · Prototype: `Main › Report a problem`
Every user with any team role, and every user of the org, can open "Report a problem".
Send on a report draft ([FR-MCP-12](#mcp--mcp-server-and-agent-api)) offers the same "Give it to" for the draft's project team as FR-INT-05 does: "The team (<lead> decides)" (the default) or one of the people Report a problem offers.
- Accept: Given Mia is in Shop, When she sends her report draft with "Give it to" Omar, Then the ticket starts in To do with Omar as assignee; with "The team (Lena decides)" it has no assignee and Lena plans it.
- Accept: a person "Give it to" does not offer (someone outside the project's team, a tester, or anyone when the sender is in no role of that team) is refused with "Pick someone from the list, or the team."; no ticket is made and the draft stays as it was.

**FR-INT-02 — Created immediately** · Must · 🏁 · Prototype: `Main › Report a problem` → ticket
"Create ticket" creates the ticket at once (no approval). The user lands on the new ticket; toast "Ticket SHOP-88 made. Lena takes a quick look." or "… Nobody needs to check it."
- Accept: with an assignee and no lead-check rule the ticket starts in To do; else in Backlog.

**FR-INT-03 — Duplicate suggestions while typing** · Must · Prototype: `Main › Report a problem` (Maybe already reported)
After the title has 8+ characters, show up to 3 open tickets of the org that look like the same problem, with key, title and Now line. Source label: "found by matching words" or "The AI thinks this may already be reported".
- Accept: suggestions update within 500 ms after typing stops; only tickets the reporter may see are shown ([ticket visibility predicate](../02-architecture/data-model.md#ticket-visibility-predicate)); a team-only ticket is never suggested to someone outside it.

**FR-INT-04 — Add my report there** · Must · Prototype: same
On a suggested ticket, "Add my report there" adds a comment "I see this too: <title> – <detail> (N pins on a screenshot)" with the screenshot/pins attached, opens that ticket, and creates no new ticket. Toast "Added to SHOP-41. No new ticket was made."

**FR-INT-05 — Where it goes** · Must · Prototype: same (Where it goes)
Fields: Team (teams + "Not sure"), Give it to (only for a chosen team: "The team (<lead> decides)" + up to 4 members sorted by load), Project, Type (default Bug), Priority (default Normal). AI suggestions are marked ✦ and are never applied without the user's choice except as the shown default.

**FR-INT-06 — Screenshot and pins on the report** · Must · Prototype: same (Screenshot)
"Capture with pinround" (browser plugin, [FR-RND-15](#rnd--screenshots-pins-and-rounds)) or "Upload a file"; click on the image to drop numbered pins and write a note per pin; remove pins. The screenshot and pins become Round 0 ([FR-RND-03](#rnd--screenshots-pins-and-rounds)).

**FR-INT-07 — "When you send" preview** · Must · Prototype: same (bottom line)
One sentence that says exactly what will happen, e.g. "It becomes ticket SHOP-88 right away. Lena takes a quick look, because maybe the same as an older ticket. Omar can start already." or "… and goes straight to Omar. Nobody needs to check it." plus "It mentions security, so only the team and admins can see it." when relevant.

**FR-INT-08 — Check reasons** · Must · Prototype: same, and ticket check box
At creation the system shall store zero or more reasons:

| Reason | When |
|---|---|
| `dupe` — maybe the same as an older ticket | duplicate suggestions were shown and the user still created a new ticket |
| `info` — little detail and no pins | description shorter than 40 characters and no pins, or AI says the report is missing what a developer needs |
| `team` — no team was picked | Team = "Not sure" |
| `risk` — it sounds risky | text matches the risk word list (security, leak, password, unauthorized, hack, privacy, GDPR, token, permission, tenant, data loss, deleted, crash, "down", can't log in, all customers, everyone) or AI says risky |
| `rule` — company rule | the admin switch "every new ticket needs a lead check" is on |

- Accept: no reasons → nobody checks it.

**FR-INT-09 — Team-only visibility for security reports** · Must · Prototype: ticket tag
If the text matches the security word list (security, leak, password, unauth…, hack, privacy, GDPR, token, permission, other/another tenant), the ticket is created with visibility "team": only the people allowed by the [ticket visibility predicate](../02-architecture/data-model.md#ticket-visibility-predicate) can see it (team members, admins, the people working on it — including the reporter — and the triager while it has no team), also in lists, search, counts, Home, My reports of others, emails, AI, MCP, API and exports.
- Accept: a lead can change visibility; the change is audited.

**FR-INT-10 — Checker routing** · Must · Prototype: ticket check box
The checker is the lead of the ticket's team, or the triager ([FR-ORG-09](#org--organization-teams-and-roles)) when there is no team. Risky tickets rank higher on the checker's Home (+40).

**FR-INT-11 — Check box on the ticket** · Must · Prototype: `Main › Ticket` (New ticket · you take a quick look)
Shows "New ticket · <checker> takes a quick look", the reasons, a note, possible duplicates (with "Same problem · close this one" for the checker), "Move to team" chips when there is no team, and for the checker: **Looks fine**, **Give it to…** (if no assignee and team known), **Ask <reporter> for more** (if reason `info` and reporter ≠ checker).
- Accept: Looks fine marks it checked, applies a pending assignee, and removes it from to-check lists.
- Accept: Same problem closes the new ticket (Done, closed as duplicate), comments on both tickets, and tells the reporter.
- Accept: Ask for more posts a question to the reporter ("Can you add the steps to see this, and a screenshot with pins?") and pauses the ticket.
- Accept: Move to team sets the team and removes the `team` reason.

**FR-INT-12 — Lead-check rule and pending assignee** · Should · Prototype: `Main › Admin › New tickets`
When the admin turns on "Every new ticket needs a lead check before it is given to anyone", a chosen assignee is stored as pending and only applied by "Looks fine". Note for others: "It is not given to anyone until Lena checks it."

**FR-INT-13 — Checks do not block** · Must · Prototype: ticket check box
Without the rule, an assigned person can start work while the check is open ("Nobody waits for this. The ticket already moves.").

**FR-INT-14 — To-check lists** · Must · Prototype: `Main › Home` group "New tickets to check", `Main › Tickets` chip
Unchecked tickets appear in the checker's Home (kind `check`) and in the "New · to check" filter, with "To check by <name>" in list rows.

**FR-INT-15 — Duplicate thresholds** · Must · Prototype: `Main › Report a problem` (Maybe already reported) · [report-a-problem.md](../04-prototype/screens/report-a-problem.md)
Word-based duplicate candidates shall need: a title of 8 or more characters; 3 or more shared words (words longer than 3 letters, not on the stop-word list) that are also at least 30% of the old title's words; only open tickets the reporter may see; at most 3, best first. In the product, matching uses PostgreSQL full-text search and trigram similarity with the same limits ([ADR-0022](../03-adr/0022-search-and-duplicates.md)); AI candidates replace them when AI is allowed and returns a valid list.
- Accept: Given a 7-character title, Then no suggestions are shown.
- Accept: Given an old title with 10 words of which 3 match, Then it is a candidate; with 2 matches it is not.
- Accept: Given a team-only ticket the reporter cannot see, Then it is never suggested.

---

## CNV — Conversation

*Story:* Omar is not sure whether cancelled orders should count. He asks Lena a question. The ticket turns amber: "Waiting · question to Lena". Lena answers from her Home; the ticket goes back to In progress.

**FR-CNV-01 — Comments** · Must · Prototype: `Main › Ticket` (Talk)
Anyone who can see a ticket can comment. A comment changes no status and sends no notification ("A comment does not change the status and sends no notification.").

**FR-CNV-02 — Questions** · Must · Prototype: `Main › Ticket` (Question toggle + person chips)
A question is sent to one person from the ticket's people; it pauses the ticket ([FR-WF-12](#wf--workflow-steps-and-turns)) and sends that person one notification.
- Accept: the composer says "A question pauses the ticket until Lena answers. They get one notification."

**FR-CNV-03 — Answer resumes** · Must · Prototype: `Main › Ticket` (Answer), `Main › My reports` (Answer)
The asked person's reply is the answer; it resolves the question and the ticket goes back to the step it was in. Toast "Answered. The ticket goes back to In progress."

**FR-CNV-04 — Replies one level deep** · Must · Prototype: `Main › Ticket` (Reply)
Replies hang under a message; replies to replies are not possible. The author of the message gets one notification per reply batch.

**FR-CNV-05 — Resolve** · Must · Prototype: `Main › Ticket` (Resolve)
The author or the lead can resolve a comment thread; it folds to one line "Resolved: … · 2 replies" for everyone and can be opened.

**FR-CNV-06 — Edit and delete own message** · Should · Prototype: not shown
Authors can edit a message for 15 minutes ("edited" mark) and delete it any time (a "Message removed" line stays). Answers to questions cannot be deleted.

**FR-CNV-07 — Safe text** · Must · Prototype: n/a
Messages are plain text with links made clickable; no HTML is rendered from user input.

**FR-CNV-08 — Question rules** · Must · Prototype: `Main › Ticket` (Talk) · [ticket-page.md](../04-prototype/screens/ticket-page.md)
- Only one open question per ticket. While one is open, "Ask" is disabled with the note "Wait for the open question first."
- A question can go only to people listed on the ticket ([FR-TKT-11](#tkt--tickets)).
- A question pauses the ticket without changing its step; the answer resumes the same step.
- Resolve is for comments only (not questions), by the author or a lead.
- Each reply notifies the author of the message it hangs under.
- Accept: one test per bullet.

---

## PNG — Pings and notifications

*Story:* Mia waits for Omar's fix. She pings him once. pinround says she can ping again in 4 hours. Omar opens the ticket and marks a pin fixed; Mia's ping disappears from his bell by itself.

**FR-PNG-01 — Ping a person** · Must · Prototype: `Main › Ticket` (People › Ping) and ping sheet
A ping sheet with a prefilled message ("Hi Omar, could you look at SHOP-88? Now: Omar builds it.") and the note "Omar gets one notification. You can ping again after 4 hours (set by your admin)."

**FR-PNG-02 — Rate limit** · Must · Prototype: same
One ping per sender, recipient and ticket per N hours (admin: 1, 4, 8 or 24; default 4). Afterwards the Ping button shows "pinged".
- Accept: Given the rate-limit store (Valkey) is down, When someone pings, Then the ping is refused with "Pings are paused for a moment. Try again soon." — pings fail closed, never unlimited.

**FR-PNG-03 — Grouping** · Must · Prototype: `Main › Home` (Pings for you), bell
Pings are grouped by sender and ticket: latest text, "3 pings", time of latest.

**FR-PNG-04 — Bell** · Must · Prototype: header bell
The bell shows the count of open pings and opens a popover with all groups (Done, Open).

**FR-PNG-05 — Auto-clear** · Must · Prototype: toast "… 3 pings on this ticket cleared."
When the recipient acts on the ticket (any action, answer, verdict, checklist change), their pings on that ticket clear (admin switch, default on).

**FR-PNG-06 — Quiet hours** · Should · Prototype: `Main › Admin › Pings`
When on, pings and notifications created between 18:00 and 09:00 in the recipient's time zone are held and delivered at 09:00.

**FR-PNG-07 — Mark done** · Must · Prototype: Home / bell "Done"
The recipient can clear a ping group by hand.

**FR-PNG-08 — Live updates** · Should · Prototype: n/a
Open pages update without reload for: new pings (bell count), ticket changes on an open ticket page, Home counts ([ADR-0023](../03-adr/0023-realtime-mercure.md)).

**FR-PNG-09 — Email digest** · Should · Prototype: n/a
At most every 3 hours, a user gets one email with unread questions and pings older than 1 hour; never during quiet hours. Email holds only key, title and a link — never descriptions, comments or AI text, and no title for team-only tickets.

**FR-PNG-10 — Mute updates** · Should · Prototype: `Main › My reports` (⋯ › Mute updates)
A user can mute notifications for one ticket; it still appears in their lists.

**FR-PNG-11 — Who can be pinged** · Must · Prototype: `Main › Ticket` (People), `Main › My reports` · [dialogs-and-popovers.md](../04-prototype/screens/dialogs-and-popovers.md)
Integration users (for example a help-desk sync user) and label waiters (Customer, Product owner, Technical owner) cannot be pinged; nobody can ping themselves. After a ping the button shows "Pinged · again in N h" until the limit ends.
- Accept: Given a ticket waits on "Customer", Then My reports shows no Ping button on its card.
- Accept: Given Mia pinged Omar 1 hour ago with a 4-hour limit, Then the button shows "Pinged · again in 3 h".

---

## RND — Screenshots, pins and rounds

*Story:* Omar fixes SHOP-88. Before sending it on, he starts a private draft round, adds two after-screenshots and marks one "Not right yet". Claude Code reads that note, fixes the spacing and adds a new screenshot. Omar presses "Send to code review" and picks "Share as round 2". Mia reviews only the pinned spots, before and after.

**FR-RND-01 — Screenshot upload** · Must · 🏁 · Prototype: report form, draft round "Add screenshot"
PNG, JPEG or WebP, max 15 MB, max 40 megapixels; at most 50 screenshots per round. Processed before it is shown ([ADR-0011](../03-adr/0011-screenshot-storage-and-processing.md)); until then a "Processing…" placeholder.
- Accept: other types, bad files or images with hidden content are rejected with a plain message.

**FR-RND-02 — Pins** · Must · 🏁 · Prototype: report form, rounds
A pin is a numbered point at x/y percent of the image with a note (max 1,000 chars). Pins are placed by click/tap and by keyboard (focus image, arrow keys, Enter), and can be removed.
A pin is a see-through ring, not a filled dot, so the element under it stays readable: a dark outer and a white inner outline (visible on any background, WCAG 1.4.11), the number in a small tab at the ring's edge, and the note in a bubble beside the spot on hover, focus or select. No width or height is stored (same x/y percent model). AI suggestion pins stay dashed in AI colours; the selected ring stays `--primary`. This differs on purpose from the prototype's filled marker (product owner's decision, 2026-11-14; the requirement wins).
- Accept: the field under a pin stays readable (the ring has no fill); the note shows on hover, focus and select, and is also in the pin list.

**FR-RND-03 — Round 0 (report)** · Must · Prototype: `Main › Ticket` (Rounds › Round 0 · Report)
Shows the reporter's screenshot with pins and "by Mia · 2 pins".

**FR-RND-04 — Checklist from the report** · Must · Prototype: `Main › Ticket` (Round 1 · Checklist from the report)
One item per Round 0 pin. The checklist has no round number: the card reads "Checklist from the report", and the first round shared after the report is Round 1, then 2, 3… (product owner, 2026-11-22; the prototype's "Round 1 · Checklist" is overruled, [ADR-0048](../03-adr/0048-tries-in-a-draft-and-one-open-round-per-ticket.md#more-information)). Assignee: "Mark fixed" (open or not fixed → "Fixed · to check"). Tester: "OK" or "Not fixed", and "Undo" beside either, back to "Fixed · to check" (2026-11-22). Summary "1 of 2 checked OK".

**FR-RND-05 — Review round** · Must · Prototype: `Review` board
A review round has before and after screenshots per section of the screen (e.g. "Per Product"), the pins from the earlier round, view switch Side by side / Before / After, a section list with state (Passed, "3 pins", Not reviewed).
- Accept: per pin the reviewer sets **Fixed** or **Still wrong** (undo possible).
- Accept: per section the reviewer gives **Pass**, **Needs fix** or **Question**, with an optional note, and saves; the page shows who acts next ("Then: Lena, team lead").

**FR-RND-06 — AI check per pin** · Could · Prototype: `Review` board ("Looks fixed: …")
AI compares before/after at each pin and writes a short hint ("Looks fixed: shows 375 now."). It is a hint only; the verdict stays human.

**FR-RND-07 — AI suggestion pins** · Should · Prototype: `Review` board (dashed pins)
AI may propose new pins on the after-screenshot (e.g. "header is cut off"). Dashed, AI colors; "Keep as my pin" turns it into a normal pin of the reviewer; "Dismiss" hides it. Text: "Dashed pins are AI suggestions: they count only after a person keeps them."

**FR-RND-08 — Full-screen review** · Should · Prototype: `Focus` board
Full-screen view of one section with After/Before toggle, pins, a bar with the current pin ("PIN 2 OF 5 · MIA" / "AI SUGGESTION 4 OF 5"), Previous/Next pin, and the two verdict buttons (Fixed / Still wrong, or Keep / Dismiss).
- Accept: AI suggestion pins show only on the After picture; choosing a verdict moves to the next pin.

**FR-RND-09 — Draft round** · Must · 🏁 · Prototype: `Main › Ticket` (Draft round · only you can see this)
The assignee of an open ticket, or the person whose turn it is ([FR-RND-17](#rnd--screenshots-pins-and-rounds), [ADR-0044](../03-adr/0044-whose-turn-it-is-may-keep-a-draft-round.md)), can start one draft round per ticket for themselves: add screenshots, drop pins with notes, set a verdict per screenshot (**Looks right** / **Not right yet**), write a summary per screenshot ([FR-RND-16](#rnd--screenshots-pins-and-rounds)), remove screenshots. Status "1 of 2 look right".

**FR-RND-10 — Draft visibility** · Must · 🏁 · Prototype: same, tag PRIVATE
Only the owner sees an unshared draft (not admins, not the lead). The owner's personal access token with scope `read` can read it through MCP.

**FR-RND-11 — Hand-off dialog** · Must · Prototype: dialog "Share your draft round?"
When the owner does an action that moves the ticket to another step while an unshared draft with screenshots exists, ask: "Share and move to <step>" (becomes "Round N · self-check by Omar", visible to all who see the ticket), "Keep private and move", or Cancel. Asked once per draft. When sharing is refused, it offers only "Keep private and move" and the reason ([FR-RND-20](#rnd--screenshots-pins-and-rounds)).

**FR-RND-12 — Share now and delete** · Must · Prototype: draft buttons
"Share with the team now" shares without moving; "Delete draft" removes it (and its files after retention). A share is refused while another shared round on the ticket is open, or when the draft has no screenshots ([FR-RND-20](#rnd--screenshots-pins-and-rounds)).
- Accept: a shared draft is read-only for everyone, including its owner. N in "Round N" is the next free round number of the ticket.

**FR-RND-13 — For Claude Code box** · Should · 🏁 · Prototype: draft "For Claude Code"
The draft shows a copyable line "Read my pinround notes for SHOP-88 and fix what is marked "Not right yet"." and the MCP tool names.

**FR-RND-14 — Rounds section** · Must · Prototype: `Main › Ticket` (Rounds)
Lists rounds in order (Round 0, 1, review rounds, shared drafts) with "Open round"; when none: "No rounds yet. The developer starts round 1 from Claude Code: it shoots the screens and puts them here."

**FR-RND-15 — Browser capture plugin** · Could · Prototype: "Capture with pinround" button
A browser extension captures the visible page (or a selected area) and sends it to a report or to the user's draft round on a chosen ticket. It connects through the consent page ([FR-RND-21](#rnd--screenshots-pins-and-rounds)) or with a pasted personal access token. It sends the optional title and summary with the screenshot ([FR-RND-16](#rnd--screenshots-pins-and-rounds)).

**FR-RND-16 — Picture title and summary** · Must · Prototype: none (product owner's decision, 2026-11-14)
Every screenshot in every round kind (report, checklist, review, draft) has an optional **title** (max 120 characters, e.g. "Case B: a new delivery date leaves the invoice total alone") and an optional **summary** (one free text box, max 600 characters, plain words, "what this picture shows"). On a before picture the summary says what is wrong; on an after picture it says what changed and what it shows now. Both are shown above the picture as text, never drawn into the image pixels. Pictures keep their order, so several titled pictures read like cases A, B, C. The purpose: a screenshot is understandable without opening the ticket description, for people and for coding agents.
- Who fills it: a person types it. A person's own Claude Code may fill it through MCP ([FR-MCP-05](#mcp--mcp-server-and-agent-api), [FR-MCP-12](#mcp--mcp-server-and-agent-api)), but only into a draft its owner checks. Pinround AI may only suggest summary text, which a person accepts with "Use this" or ignores with "Dismiss"; AI never saves it alone ([ADR-0020](../03-adr/0020-ai-safety-and-prompt-injection.md)).
- Title and summary are untrusted user text: wrapped in delimiters when sent to AI or returned by MCP.
- Carry-forward: a review round's "before" is the earlier round's "after" screenshot row, so its title and summary show there read-only.
- Accept: a title over 120 characters or a summary over 600 characters is refused with a plain message ("Keep the summary to 600 characters or fewer."; likewise "Keep the title to 120 characters or fewer." and "Keep the pin note to 1,000 characters or fewer."), and nothing is saved.
- Accept: a review round's before picture shows the earlier after picture's title and summary, read-only.

**FR-RND-17 — Findings draft for the person whose turn it is** · Should · Prototype: none (product owner's decision, 2026-11-19)
On an open ticket, the person whose turn it is (for example the tester in Testing, the reviewer in Code review) may start a draft round, as the assignee may ([FR-RND-09](#rnd--screenshots-pins-and-rounds)). The owner keeps it after the turn passes on. The hand-off dialog ([FR-RND-11](#rnd--screenshots-pins-and-rounds)) asks on the owner's move with any verb. Shared, it reads "Round N · findings by Mia", and each pin becomes an `open` checklist item ([FR-RND-04](#rnd--screenshots-pins-and-rounds)). "Make it a new ticket" moves the draft's content into a new report draft of the same person ([FR-MCP-12](#mcp--mcp-server-and-agent-api)); nothing becomes a ticket until Send ([ADR-0044](../03-adr/0044-whose-turn-it-is-may-keep-a-draft-round.md)).
- Accept: someone whose turn it is not, and who is not the assignee, cannot start a draft; nothing is created.
- Accept: a shared findings round has one `open` checklist item per pin; a self-check round has none.
- Accept: "Make it a new ticket" on a draft of more than 10 screenshots is refused and nothing changes.

**FR-RND-18 — Pins on the live page** · Should · Prototype: none (product owner's decision, 2026-11-19)
The browser extension ([FR-RND-15](#rnd--screenshots-pins-and-rounds)) has "Pin this page": the person drops numbered pins with notes on the live page, then captures it; each pin is stored as x/y percent of the captured image ([FR-RND-02](#rnd--screenshots-pins-and-rounds)). The person picks one target per session: "New report" (a report draft), their draft round on a ticket, or a notebook ([FR-EXP-01](#exp--explore-notebooks-and-scenarios)). Every capture goes to that target until changed ([ADR-0045](../03-adr/0045-evidence-is-captured-where-the-person-works.md)).
- Accept: a pin lands at the same percent (± 0.5) on the captured image.
- Accept: captures on several pages end in one report draft, in order, up to 10.
- Accept: with no title and no summary nothing extra is shown and nothing is required.

**FR-RND-19 — Tries of a screenshot** · Should · Prototype: none (product owner's decision, 2026-11-20)
In a draft round ([FR-RND-09](#rnd--screenshots-pins-and-rounds)) a retake is the next try of the same screenshot: it neither overwrites it nor adds a screenshot or a round, so a round number counts hand-offs, not tries ([ADR-0048](../03-adr/0048-tries-in-a-draft-and-one-open-round-per-ticket.md)). `add_screenshot` ([FR-MCP-05](#mcp--mcp-server-and-agent-api)) and the REST draft screenshot call take an optional `retake_of`; the extension's draft round target offers "Retake of screenshot N". The draft shows the latest try of each screenshot with "Try 2 of 2" and a switch to earlier tries. Pins stay on the try they were put on; a pin on an earlier try can be marked "Fixed on try N" or "Still wrong". A screenshot keeps at most 5 tries. On share the team sees one screenshot per chain, the latest try; earlier tries stay with the owner and are deleted with the draft. A shared round's "before" is the "after" of the hand-off round shared just before it, paired by screenshot number; with no earlier hand-off round, the report's (Round 0's) screenshot is the "before", and the checklist round never is (product owner, 2026-11-21).
- Accept: a retake with `retake_of` shows as try 2 of the same screenshot; the try-1 pins stay on try 1.
- Accept: a sixth try is refused with "This screenshot already has 5 tries. Remove one before you add another.", and nothing is stored.
- Accept: a `retake_of` that points at another round, another person's draft or another organization answers 404, and nothing is stored.
- Accept: after share the round has one screenshot per chain, the latest try.

**FR-RND-20 — One open shared round per ticket** · Should · Prototype: none (product owner's decisions, 2026-11-20 and 2026-11-21)
A ticket has at most one open shared round ([ADR-0048](../03-adr/0048-tries-in-a-draft-and-one-open-round-per-ticket.md)). A shared round is a hand-off numbered from 1: a review round or a round shared from a draft. Round 0 and the checklist round never block a share.
- **When it closes.** A round shared at a hand-off is ready for the person whose turn it is next. It closes when the person whose turn it is moves the ticket on with a move-on verb, any verb that is not lead-only ("Approve code", "Pass it", "Needs changes", "Input given · go on" and the others); the verb is its verdict. A move by anyone else leaves it open. The lead-only verbs "Put on hold", "Move to another step…" and "Go on" never close it, whoever uses them; after such a move, whoever's turn it is now closes it when they move the ticket on. A findings round ([FR-RND-17](#rnd--screenshots-pins-and-rounds)) closes when the assignee moves the ticket on, also with "Keep private and move", or when the assignee shares the next round.
- **Share rules, on every path.** A share is refused while another shared round on the ticket is open, unless the same move closes that round first; then the share takes the next number, in one transaction. A draft with no screenshots is refused: "Add a screenshot before you share." A refused draft stays unshared and unnumbered. The hand-off dialog ([FR-RND-11](#rnd--screenshots-pins-and-rounds)) then offers only "Keep private and move", with a reason that names who gives the verdict, for example "Round 2 is still open. Mia gives a verdict first." An MCP or REST call that shares answers the refusal as a 4xx with the reason. Anyone may keep a private draft meanwhile; only sharing waits.
- **Old open rounds.** A ticket that already has more than one open shared round keeps them, with no data change; every share on it is refused until they close. The next move-on verb by the person whose turn it is closes every one that is not a findings round; a findings round closes on the assignee's move, as above.
- Accept: a share while another round is open, or of a draft with no screenshots, is refused with the reason, and the draft stays unshared and unnumbered.
- Accept: of two shares at the same moment, exactly one wins.
- Accept: Round 2 is ready for Lena, who is lead and reviewer: her "Put on hold" leaves it open and a share is still refused; back in Code review, her "Approve code" closes it.
- Accept: Mia's "Needs changes" with a draft closes Round 2 and shares "Round 3 · findings by Mia" in the same move.

**FR-RND-21 — One-click connect** · Should · Prototype: none (product owner's decision, 2026-11-21)
The browser extension ([FR-RND-15](#rnd--screenshots-pins-and-rounds)), Claude Code and a Claude custom connector connect to pinround through a consent page, with no pasted token (OAuth 2.1, authorization code with PKCE, [ADR-0050](../03-adr/0050-one-click-connect-with-oauth.md)). The consent page shows the app's name, the host it sends the person back to and what each scope lets it do; the person picks one organization they are a member of and presses **Allow** or **Deny**. It asks for the same recent password and two-step login as making a token ([FR-ID-05](#id--identity-and-access)). The tokens act as the person, with their permissions and the asked scopes, in the picked organization only: an access token works for 1 hour, a refresh token is replaced at each use. Pasted personal access tokens keep working.
- Accept: the extension connects without a pasted token, and its calls use the issued token.
- Accept: a consent posted with the id of an organization the person is not a member of gets 404, and no code is issued; a token of Acme Foods answers not found for another organization's data.
- Accept: the extension's "Sign out" revokes its tokens, and a revoked token is refused at once; a refresh token used twice, or a code used twice, revokes every token issued from that consent.
- Accept: once the person leaves the organization, its access and refresh tokens are refused.

---

## HOME — Home page

*Story:* Lena opens pinround at 9:00. "7 things wait on you, Lena. Do the top 5 first." Number 1: "Answer Mia's question · Mia is blocked until you answer". She answers, then presses "Start reviews" and walks through three code reviews.

**FR-HOME-01 — Headline** · Must · 🏁 (simple list) · Prototype: `Main › Home`
"N things wait on you, <first name>" or "All clear, <first name>"; date line; subline with the role line (lead: "You lead the team, so reviews, decisions and unclear new tickets come to you.").

**FR-HOME-02 — Needs you now** · Must · Prototype: `Main › Home`
The top 5 items for the viewer, ranked by the needs-you score, label "AI ORDER". Each: rank, key, title, task as a verb ("Review Omar's code", "Check the new ticket from Ben"), a "Why" line (e.g. "Mia is blocked until you answer · Critical · in release v2026.47.1 · no change for 12 days"), waiting time, and "Open ticket" / "Check it".

**FR-HOME-03 — Needs-you score** · Must · Prototype: `Main › Home`
Items are tickets where it is the viewer's turn (not Done, not Backlog) plus new tickets the viewer must check, filtered by the [ticket visibility predicate](../02-architecture/data-model.md#ticket-visibility-predicate).

score = base(kind) + priority weight + release bonus + min(days waiting, 60) × 0.5

| Kind | Base |
|---|---|
| answer (open question to you) | 100 |
| check (new ticket to check) | 70 (+40 if reason `risk`) |
| review (Code review) | 30 |
| test (Testing) | 30 |
| assign (To do, no assignee) | 25 |
| input (Needs input) | 20 |
| decide (On hold) | 15 |
| build (your own work) | 10 |

Priority: Blocker 50, Critical 40, Major 20, Normal 8, Minor 3. Release: in the current release +30, in any release +10. Days waiting = days since the turn last changed (`waiting_since`).
- Accept: ties break by older first. The formula is in one class with unit tests for each row.
- Could (deferred after the hackathon): when the org allows AI, an AI step may re-order items **only inside the top 10** of this score; it can never pull an item from below the top 10 or hide one. The label stays "AI ORDER".

**FR-HOME-04 — Also waiting on you** · Must · Prototype: `Main › Home`
Items after the top 5, grouped by kind in this order: Questions for you, New tickets to check, Code reviews, Tests, Need a developer, Your input, On hold, Your building work. Each group: count, hint, expand to see rows, and a start button ("Answer them one by one", "Check them", "Start reviews", "Start testing", "Go through them") that starts the queue ([FR-TKT-15](#tkt--tickets)).

**FR-HOME-05 — Pings for you** · Must · Prototype: `Main › Home` aside
Top 3 ping groups with Done / Open ticket, "Show N more" opens the bell.

**FR-HOME-06 — Release bar** · Should · Prototype: `Main › Home` (lead)
For leads: current release, a bar of its tickets per group, and the list with step and turn.

**FR-HOME-07 — Your team** · Should · Prototype: `Main › Home`
Avatar stack (4 + "+N") and "N people · see who has what" linking to Team.

**FR-HOME-08 — Focus chips** · Must · Prototype: `Main › Home` (All / My work / My team / Admin)
Chips: All, My work, My team, All teams (managers), Admin (admins). A chip narrows Home to its part. A hidden part with waiting items shows a red count on its chip (My work: number of items; Admin: number of alerts).

**FR-HOME-09 — Admin card** · Must · Prototype: `Main › Home` as "Lead + Admin"
For admins with a team role: "Admin" card with alerts only (e.g. "2 invites are not accepted yet", "Company login is not set up", "AI runs on laptops: people must set up Ollama"), each linking to the right admin tab; "Nothing needs you as admin." when empty; "Open settings".

**FR-HOME-10 — All teams mini** · Should · Prototype: `Main › Home` as lead with Manager role
For managers with a team role: each team as a thin five-group bar with its waiting flag.

**FR-HOME-11 — Homes for pure roles** · Must · Prototype: `Main › Home` as Manager / Admin
Pure manager: "Teams at a glance" ([FR-MGR-01](#mgr--manager-overview)). Pure admin: "Your organization" with stats (People, Teams, AI help, Jira) linking to admin tabs, and "Open admin settings".

---

## MINE — My reports

*Story:* Ben reported four problems last month. My reports shows one in "Needs you": "Lena asks: Which page was it?". He answers right in the card. Another is "Stuck · no change for 9 days"; he presses "Ping Lena".

**FR-MINE-01 — My reported tickets** · Must · Prototype: `Main › My reports`
All tickets the viewer reported (and can still see), intro "20 tickets you reported. 1 needs your answer.", and a "Report a problem" button.

**FR-MINE-02 — Progress dots** · Must · Prototype: same
Four dots Reported → Building → Checking → Done; the current dot is ringed; Waiting group shows the current dot amber labelled "Waiting".

**FR-MINE-03 — Groups** · Must · Prototype: same
**Needs you** (open question to me; all shown), **Stuck** (no change for more than 7 days; 4 + "Show more"), **Moving** (4 + "Show more"), **Done** (folded: "Show 12 done").

**FR-MINE-04 — Card** · Must · Prototype: same
Key, title, progress dots, avatar of whose turn, the Now line (or "Lena asks: "…"" when a question waits on me), side text ("no change for 9 days" / "changed recently" (≤ 2 days) / "updated 5 Nov"), pin progress "1 of 2 pins OK", tags EXAMPLE/MUTED where relevant. At most two visible buttons: one main + "⋯".

**FR-MINE-05 — Answer inline** · Must · Prototype: same (Needs you › Answer)
"Answer" opens a text box in the card; "Send answer" resolves the question like [FR-CNV-03](#cnv--conversation).

**FR-MINE-06 — Ping from the card** · Must · Prototype: Stuck / Moving cards
"Ping <name>" (tonal in Stuck, text button in Moving) opens the ping sheet with the text "Hi Omar, any news on SHOP-88? I reported it. Now: <Now line>."; hidden when the turn is a customer/owner role or already pinged within the limit.

**FR-MINE-07 — ⋯ menu** · Must · Prototype: same
Open ticket, Add more info (opens ticket at the composer), Mute updates / Turn updates on, Copy link; on Done cards for tester/lead: "Not fixed? Reopen".

**FR-MINE-08 — Nav badge** · Must · Prototype: header nav "My reports (1)"
The nav item shows the count of questions waiting on the viewer in their reports.

---

## MGR — Manager overview

*Story:* Raj opens Home on Friday. The payments team bar is mostly amber: "38% of open work is waiting". He expands it and sees that most of it sits in "Blocked by bank". He talks to that lead, not to everyone.

**FR-MGR-01 — Teams at a glance** · Must · Prototype: `Main › Home` as Manager
One card per team: name, note, a stacked bar of tickets per group with counts, legend of the five groups.

**FR-MGR-02 — Waiting flag** · Must · Prototype: same
"N% of open work is waiting" in amber when Waiting / (all − Done) > 25%; else "Flowing" in green.

**FR-MGR-03 — Team's own steps** · Should · Prototype: same ("Show this team's own steps")
Expands to the team's steps with counts under each group.

**FR-MGR-04 — Read and comment** · Must · Prototype: ticket as Manager
Managers see Tickets and ticket pages (except team-only tickets of teams they are not in) and may comment and ask; they get no move actions.

---

## AI — AI assist

*Story:* Acme Foods cannot pay for an AI service. Sara sets "Where the AI runs: Each person's laptop". Omar installs Ollama and pulls `qwen3:4b`. The chip says "Local AI: ready". His summaries are made on his laptop; the ticket text never leaves it.

**FR-AI-01 — AI policy per organization** · Must · 🏁 (one provider) · Prototype: `Main › Admin › AI`
Admin settings: AI help on/off; who may use it (everyone / leads only); what is sent (title and text / title only); summarize every ticket on Jira sync (on/off); where the AI runs: **Company cloud AI**, **Each person's laptop (Ollama)**, **Laptop first, cloud when it is off**. The note under the choice explains cost and data flow.
- Accept: when AI is off or not allowed for the viewer, AI buttons are hidden and one line says why.

**FR-AI-02 — Providers** · Must · 🏁 (Hugging Face + Ollama) · Prototype: `Main › Admin › AI` note
Server-side AI runs through Symfony AI Platform. Supported: Hugging Face inference (hackathon default), Anthropic Claude, Ollama on a server the org runs. Provider credentials are per organization and encrypted ([ADR-0018](../03-adr/0018-ai-provider-abstraction.md)).

**FR-AI-03 — Summary ("In short")** · Must · 🏁 · Prototype: `Main › Ticket` (The ask)
A 2–4 sentence plain summary (max 60 words) of what the ticket asks, shown with "AI SUMMARY" and a source note ("written from the Jira text when the ticket was synced" / "written by AI when the ticket was reported" / "written just now at Omar's request"). "Summarize with AI" makes one on demand; it is saved for everyone.
- Accept: the summary uses only facts in the ticket; tests check that the prompt says so and that the result is plain text.

**FR-AI-04 — Summary history and main summary** · Should · Prototype: `Main › Ticket` (Show earlier summaries)
Older summaries are kept; "Show N earlier summaries". A lead or the reporter can make a shared explanation the main summary ("Use as main summary"); the old one moves to history. Toast "Now the main summary. The old one is kept in history."

**FR-AI-05 — Explain it to me (private)** · Must · Prototype: `Main › Ticket` (Explain it to me)
A user asks a question about the ticket (free text or chips: "Explain it in simpler words", "What does "done" look like?", "Explain the product terms", "Why does this matter to users?"). The answer (max 160 words, streamed, Stop button) is shown only to that user, labelled private.

**FR-AI-06 — Share an explanation** · Must · Prototype: same (Share)
"Share" shows it to everyone under the summary ("explanation by Omar"); "Clear" deletes it.

**FR-AI-07 — Intake suggestions** · Must · 🏁 · Prototype: `Main › Report a problem` (Check with AI)
Before "Check with AI" sends anything, the security word list runs on the draft; if it matches, the draft is treated as team-only and is never sent to cloud AI (laptop AI may still be used). Duplicate candidates sent to AI never include team-only tickets unless the admin allows cloud AI for them. "Check with AI" returns JSON: summary (≤ 40 words), type, priority, duplicates (≤ 3 keys from the candidate list), missing (bool), risky (bool). Results fill ✦ markers and the reasons; invalid JSON is ignored with "The AI answer failed. Try again."

**FR-AI-08 — AI duplicates** · Should · Prototype: same
When AI is allowed, duplicate suggestions may use semantic similarity (embeddings) in addition to word matching ([ADR-0022](../03-adr/0022-search-and-duplicates.md)). Embeddings are never made for team-only tickets unless the admin allows cloud AI for them.

**FR-AI-09 — Risk detection** · Should · Prototype: same
AI `risky: true` adds reason `risk`. AI never sets visibility; only the security word rule or a lead does.

**FR-AI-10 — Laptop AI status and setup** · Must · 🏁 · Prototype: header chip "Local AI: …" and dialog "AI on your laptop"
When the policy uses laptops, the header shows "Local AI: not checked / checking… / ready / not found". The dialog explains the fallback, shows status, lists installed models to choose, and four steps: install Ollama; `ollama pull qwen3:4b` (about 2.5 GB); set `OLLAMA_ORIGINS` to the pinround address and restart Ollama; press "Check again" and allow the browser's local-network prompt.
- Accept: the browser checks `http://localhost:11434/api/tags` only after a click, never on page load ([ADR-0019](../03-adr/0019-browser-local-ai.md)), with a 3-second timeout.
- Accept: the default model is the first installed `qwen3*`, else `phi4*` or `gemma*`, else the first model; the user can pick another.

**FR-AI-11 — Laptop AI results** · Must · 🏁 · Prototype: n/a
For laptop mode the server gives the browser a prompt package (only data the user may see, following "what is sent"); the browser runs it on Ollama and posts the result back. The server validates it like user input and labels it ("posted by Omar using local model qwen3:4b (unverified)").
- Accept: the browser asks Ollama without "thinking" output (`think: false`) and removes any `<think>…</think>` text before posting.
- Accept: a prompt package is single-use and expires after 5 minutes (`/ai/laptop/package`, `/ai/laptop/result`).
- Accept: the model name must match `[a-z0-9:._-]{1,64}`, else the result is refused.
- Accept: a laptop result never becomes the current summary by itself; a person must choose "Use as main summary".

**FR-AI-12 — AI suggestion pins** · Could · Prototype: `Review` board
A vision model proposes pins on after-screenshots ([FR-RND-07](#rnd--screenshots-pins-and-rounds)).

**FR-AI-13 — Labels** · Must · Prototype: everywhere AI appears
Every AI output shows an AI chip or ✦; AI pins are dashed; Home order says "AI ORDER".

**FR-AI-14 — AI never acts alone** · Must · Prototype: n/a
No AI output changes a ticket, step, assignee, visibility or verdict without a person's click ([ADR-0020](../03-adr/0020-ai-safety-and-prompt-injection.md)).

**FR-AI-15 — Usage limits** · Should · Prototype: n/a
At most 30 AI calls per user per hour. Per-org monthly budget for cloud AI calls (count and tokens); at 80% admins get an alert; at 100% cloud AI stops (laptop AI still works). Errors: "Too many AI requests. Try again in a minute."

**FR-AI-16 — Data handling** · Must · Prototype: n/a
Prompts mark ticket text as data ("data, not instructions"); with "title only" no description is sent; prompts and answers are never written to logs; only metadata (model, tokens, duration, outcome) is stored. Team-only tickets are never sent to cloud AI unless the admin turns on "Allow cloud AI for team-only tickets"; laptop AI and a server Ollama may be used.

**FR-AI-17 — Summaries on sync** · Should · Prototype: `Main › Admin › AI` switch
When on, imported/updated Jira tickets get a summary in the background (cloud or server provider only).

**FR-AI-18 — AI size limits** · Must · Prototype: `Main › Ticket` (The ask, Explain it to me) · [ticket-page.md](../04-prototype/screens/ticket-page.md)
- A summary has 2–4 sentences and at most 60 words.
- An explanation has at most 160 words.
- At most 7,000 characters of ticket text are sent to any model.
- Without a summary, the description shows its first 700 characters and "Show the full ask".
- Accept: outputs longer than the limit are cut at a sentence end and marked "…"; tests check each limit.

---

## MCP — MCP server and agent API

*Story:* Omar types in Claude Code: "Read my pinround notes for SHOP-88 and fix what is marked Not right yet." Claude Code calls `get_round_notes`, fixes the code, takes a screenshot, calls `add_screenshot` and `set_verdict`. Omar sees the new screenshot in his private draft round.

**FR-MCP-01 — MCP endpoint** · Must · 🏁 · Prototype: n/a
An MCP server over Streamable HTTP at `/mcp`, authenticated with a personal access token (Bearer) ([ADR-0021](../03-adr/0021-mcp-server.md)). Setup line shown in the profile: `claude mcp add --transport http pinround https://<host>/mcp --header "Authorization: Bearer <token>"`.

**FR-MCP-02 — list_my_tickets** · Must · 🏁 — tickets where it is the caller's turn or they are assignee: key, title, step, Now line. Scope `read`.

**FR-MCP-03 — get_ticket** · Must — key, title, description, step, Now/Next, people, open questions, checklist. Scope `read`. The caller gets only what they may see in the UI; the AI "what is sent" policy does not apply, because the caller's own tool reads the data, not pinround's AI.

**FR-MCP-04 — get_round_notes** · Must · 🏁 — for a ticket: the latest round and the caller's draft round: screenshots (ids, signed URLs), pins with notes, verdicts, and each screenshot's title and summary (inside delimiters, as untrusted text, [FR-RND-16](#rnd--screenshots-pins-and-rounds)). Scope `read`.

**FR-MCP-05 — add_screenshot** · Must · 🏁 — add an image to the caller's draft round in two calls: `create_upload` returns a presigned upload URL and an `upload_id`; after the PUT, `add_screenshot` takes the `upload_id` (never image bytes in the tool call), optional title, summary and pins with notes. Scope `rounds:write`. Same file rules as [FR-RND-01](#rnd--screenshots-pins-and-rounds).

**FR-MCP-06 — set_verdict** · Must · 🏁 — set "Looks right"/"Not right yet" and optionally the screenshot's `summary` (max 600 characters, [FR-RND-16](#rnd--screenshots-pins-and-rounds)) on a draft screenshot, or mark a checklist item fixed (assignee only). Scope `rounds:write`.

**FR-MCP-07 — add_comment** · Should — comment on a ticket (never a question). Scope `comments:write`.

**FR-MCP-08 — move_ticket** · Should — run one allowed action by verb name (e.g. `send_to_code_review`); the hand-off dialog rule applies: the tool requires `draft: "share" | "keep"` when an unshared draft exists. Scope `tickets:move`.

**FR-MCP-09 — Same rules, audited** · Must · 🏁 — every tool runs through the same application services and voters as the UI; each call is in the audit log (tool, ticket key, result, not content).

**FR-MCP-10 — REST API v1** · Should — `/api/v1` JSON endpoints for the browser plugin: create upload, create report, add to draft round, list my tickets. Token auth, scopes, rate limited.

**FR-MCP-11 — Token page** · Must · 🏁 — profile page to create/revoke tokens ([FR-ID-05](#id--identity-and-access)) with the copyable setup line.

**FR-MCP-12 — create_report** · Should — a reporter's Claude Code reproduces a customer issue, takes screenshots and calls `create_report` {project, title, detail, screenshots[{upload_id, title?, summary?, pins?[]}]}. It creates a **report draft** visible only to its owner (the [ADR-0017](../03-adr/0017-private-draft-rounds.md) privacy rules); it never creates a live ticket, and no token endpoint does, MCP or REST (`POST /api/v1/reports` makes the same draft). The reporter opens it in the app, can edit it, and clicks **Send**, which creates the ticket with Round 0. Scope `reports:write`, which is not in Claude Code's default scopes: the user ticks it when creating the token. Screenshots use the same upload path (presigned PUT, then the tool), begun with `create_report_upload {project, mime, bytes, sha256}`, a tool of its own because `create_upload` needs `rounds:write`; same rate limits; audited without content ([ADR-0039](../03-adr/0039-agents-file-report-drafts-a-person-sends-them.md)).
- Accept: a token without `reports:write` gets an MCP error and nothing is created.
- Accept: the report draft is invisible to everyone but its owner (not admins, not the team), and a project of another organization is "not found".
- Accept: no ticket exists until a person clicks Send in the app.

**FR-MCP-13 — find_tickets** · Should — `find_tickets {project?, text}` returns at most 10 open tickets the caller may see, with key, title, step, whose turn, and whether the caller may start a draft on it now ([FR-RND-17](#rnd--screenshots-pins-and-rounds)). Scope `read`. Text comes back inside delimiters ([ADR-0045](../03-adr/0045-evidence-is-captured-where-the-person-works.md)).
- Accept: a ticket the caller may not see never appears; `text` over 200 characters is refused.

**FR-MCP-14 — Prompt: report while investigating** · Should — the MCP server offers the prompt `report_while_investigating`. It tells any MCP client to keep an evidence log while it checks a problem (each case: a before picture, title, summary, pins), to upload nothing until the person decides, and at the end to offer "New report", "Add to <key>" or "Check more", then to file with `create_upload`, `create_report` or `add_screenshot` ([ADR-0045](../03-adr/0045-evidence-is-captured-where-the-person-works.md)).
- Accept: `prompts/list` shows it and `prompts/get` returns its versioned text.
- Accept: a check that finds no bug leaves nothing in pinround.

---

## EXP — Explore: notebooks and scenarios

*Story:* Lena takes over the order page. She pins three spots she does not understand into a notebook and asks her Claude Code to explain them. Then she asks for every warning on the page. Claude Code saves six scenarios, and she plays them one by one on her local app with the extension ([ADR-0046](../03-adr/0046-notebooks-and-scenarios-for-understanding-an-app.md)).

**FR-EXP-01 — Notebook** · Should · Prototype: none (product owner's decision, 2026-11-19)
A private space of one person, not linked to a ticket: a name, an optional project, up to 10 screenshots with titles, summaries and pins, and up to 20 scenarios. "My notebooks" lists them; the owner can rename and delete.
- Accept: an 11th screenshot or a 21st scenario is refused with a plain message and nothing changes.

**FR-EXP-02 — Notebook privacy** · Should — only the owner sees a notebook: not admins, not the team. Everyone else gets "not found" in pages, REST and MCP.
- Accept: another person, an admin and another organization get "not found" on the page, a direct URL, REST and MCP.

**FR-EXP-03 — Read a notebook through MCP** · Should — `list_my_notebooks` and `get_notebook` (scope `read`) return the owner's notebooks, pictures (signed URLs), pins and scenarios; text comes back inside delimiters. Pinround stores no AI answer.

**FR-EXP-04 — Scenario format** · Should — a scenario has a title (≤ 120), `why` (≤ 600), `setup` (≤ 600), a `start_url` and at most 30 steps. Step types: `go_to`, `click`, `type`, `select`, `wait_for`, `expect_text`, `expect_visible`, `pause`, `capture`; a step finds its element by role and name, visible text or label, never by a CSS or XPath string, and has a `say` line (≤ 200).
- Accept: an unknown step type, a CSS or XPath target, a `go_to` to another host, or an over-cap value is refused and nothing is saved.

**FR-EXP-05 — save_scenarios** · Should — `save_scenarios {notebook, scenarios}` replaces a notebook's scenarios after validation. Scope `notebooks:write`, off by default on the token page.
- Accept: without the scope, the tool errors and nothing changes.

**FR-EXP-06 — Scenario player** · Should — the extension lists a notebook's scenarios and plays one step at a time or all steps, with "Next" and "Back" between scenarios; each step shows its `say` line. A failed `expect_*` stops and shows expected and found. `capture` adds a screenshot to the notebook. It plays only on hosts the person allowed, never on the pinround host, asks once per host before the first run, never types into a password field, and runs no code from a scenario.
- Accept: a host that is not allowed, the pinround host and a password field are each refused before any step runs.
- Accept: the extension source has no `eval(` or `new Function` (CI check).

---

## JIRA — Jira integration

*Story:* Acme Foods still uses Jira for some teams. Lena links project SHOP from Jira. Tickets come in with their status mapped to pinround steps. When Mia presses "Pass it", Jira moves to Closed too.

**FR-JIRA-01 — Connect Jira** · Should · Prototype: `Main › Admin › Connections` (Jira CONNECTED)
Admin connects Jira Cloud by OAuth 2.0 (preferred) or API token (encrypted). Shows site, last sync, project count.

**FR-JIRA-02 — Link projects to a team** · Should · Prototype: `Main › Team` (Add project dialog with search)
A lead links Jira projects to the team (search by key or name, multi-select) and removes links.

**FR-JIRA-03 — Import** · Should · Prototype: list note "Jira: <status>"
Linking imports open tickets and those updated in the last 90 days: title, description, type, priority, assignee/reporter (matched by email, else shown by name), releases, components, parent, status → step.

**FR-JIRA-04 — Webhooks** · Should — Jira changes arrive by webhook (verified secret) and update the ticket within 1 minute; a nightly check catches missed events.

**FR-JIRA-05 — Status mapping** · Should · Prototype: `Main › Admin › Connections` (mapping table)
Table Jira status → pinround step (and its group). Unmapped statuses fall back by Jira status category (done → Done, in progress → In progress, else To do) and show an admin alert.

**FR-JIRA-06 — Sync back** · Should — moves in pinround run the matching Jira transition; note "Moved in pinround just now. It syncs back to Jira."; failures retry and show on the ticket after 3 failures.

**FR-JIRA-07 — Origin note and link** · Should — synced tickets show "Jira status: … · updated <date>" and the Jira link.

**FR-JIRA-08 — pinround-only data** · Must — rounds, pins, verdicts, checks and private data are not sent to Jira. Comments are not synced in v1.

**FR-JIRA-09 — Conflicts** · Should — last writer wins per field; every overwrite is logged in the ticket activity ("Jira changed the priority to Major").

---

## ADM — Admin

*Story:* Sara spends ten minutes a week in Admin: one invite, one new team, a look at the activity log.

**FR-ADM-01 — People and roles** · Must · Prototype: `Main › Admin › People and roles`
List of people with name, team/source, Admin and Manager chips, team role select; role help text (Admin, Manager company-wide; Lead, Member, Viewer per team).

**FR-ADM-02 — Invite** · Must · Prototype: same (invite field) — email validation; see [FR-ID-02](#id--identity-and-access).

**FR-ADM-03 — Teams** · Must · Prototype: `Main › Admin › Teams` — list with lead, size, projects; create with name and lead.

**FR-ADM-04 — Statuses** · Must · Prototype: `Main › Admin › Statuses` — policy cards ([FR-WF-05](#wf--workflow-steps-and-turns)), company steps per group with add/remove, rules switches, and a preview of how managers see teams (five groups).

**FR-ADM-05 — AI** · Must · 🏁 (minimal) · Prototype: `Main › Admin › AI` — see [FR-AI-01](#ai--ai-assist); plus provider credentials and the recommended laptop model text ("qwen3:4b · about 2.5 GB … Lighter: phi4-mini").

**FR-ADM-06 — Pings** · Must · Prototype: `Main › Admin › Pings` — limit (1/4/8/24 h), auto-clear switch, quiet hours switch.

**FR-ADM-07 — New tickets** · Must · Prototype: `Main › Admin › New tickets` — lead-check switch, triager select, list of check reasons with explanations.

**FR-ADM-08 — Connections** · Should · Prototype: `Main › Admin › Connections` — Jira (status, mapping table), company login (set up / turn off).

**FR-ADM-09 — Activity log** · Must · Prototype: `Main › Admin › Activity log` — numbered entries "who · what (ticket)", filter by person/action/date, newest first ([FR-AUD-01](#aud--audit-and-data)).

**FR-ADM-10 — Data** · Must · Prototype: `Main › Admin › Data` — keep data 1 year / 2 years / always; "Export" (async, email when ready).

**FR-ADM-11 — Admin home** · Must · Prototype: `Main › Home` as Admin — see [FR-HOME-11](#home--home-page).

---

## AUD — Audit and data

*Story:* An auditor asks who could see security tickets in March. Sara filters the activity log by "role changes" and exports it.

**FR-AUD-01 — Audit events** · Must — the system records: logins (success/fail), role and membership changes, policy and settings changes, visibility changes, exports, token create/revoke, MCP calls, AI policy changes, Jira connection changes. Each: actor, action, target, time, IP hash.

**FR-AUD-02 — Append-only** · Must — audit events cannot be changed or deleted by the app (database permissions), except by the retention job.

**FR-AUD-03 — Retention** · Must — a nightly job deletes, when older than the org's setting (1 year / 2 years): closed tickets with their media and ticket events, and audit events. Open tickets are never deleted, whatever their age. "Always" keeps everything. Each purge run writes one audit event with counts.

**FR-AUD-04 — Export** · Must — admins export the organization's data (tickets, messages, rounds metadata, audit log) as JSON + CSV, screenshots as a ZIP; the file is kept 24 h. The email links to an app page that re-checks admin + a fresh MFA check, then issues a signed download URL valid 5 minutes. Exports never include unshared draft rounds or private AI explanations. Each download is audited.

**FR-AUD-05 — Ticket activity vs. audit log** · Must — ticket activity ([FR-TKT-12](#tkt--tickets)) is for the team; the audit log is for admins and covers security-relevant events. They are separate stores.

---

## Traceability notes

- Every phase file in [docs/05-plan](../05-plan/README.md) lists the FR/NFR IDs it covers under "Requirements covered".
- Every acceptance criterion maps to a test; test names include the ID (e.g. `test_FR_HOME_03_score_for_answer`).
- When the prototype and a requirement differ, the requirement wins; the difference is noted in the phase hand-off notes and, if lasting, in the [prototype guide](../04-prototype/prototype-guide.md).
