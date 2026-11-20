# Personas and roles

Who uses pinround, what each person needs, and what each role may do.

## Index

- [Personas](#personas): seven people with goals and frustrations (lead, developer, tester, manager, admin, support reporter, new joiner).
- [Role model](#role-model): organization roles, team roles and jobs, and how they combine.
- [Permission matrix](#permission-matrix): every action in the product against every role.
- [Special visibility rules](#special-visibility-rules): team-only tickets, draft rounds, private AI answers.
- [Multi-role behaviour](#multi-role-behaviour): one Home, focus chips, admin as a separate page.
- [Examples of role combinations](#examples-of-role-combinations): four real-looking setups.

## Personas

All people are examples at Acme Foods. They are used in every doc, test fixture and story.

### Lena — team lead and developer

Lena leads the shop team (5 people) and still writes code.
- **Goals:** know the 3–5 things that need her today; keep work moving; keep reviews fast; answer questions so nobody is blocked.
- **Frustrations:** boards with 40 cards and no order; chasing people by chat; a triage inbox that only she can empty.
- **In pinround:** Home top 5 with "Why"; "Start reviews" to walk through code reviews; quick checks of unclear new tickets; release bar.

### Omar — developer

- **Goals:** a clear ask; the exact spot on the screen; to check his own fix before others see it; to use Claude Code for the boring parts.
- **Frustrations:** vague tickets; finding which number the tester meant; questions that get lost.
- **In pinround:** pins as a checklist; private draft rounds; Claude Code reads his notes through MCP; "Send to code review" in one click.

### Mia — tester

- **Goals:** report fast with pictures; re-test only what changed; give clear verdicts.
- **Frustrations:** long forms; re-checking whole pages; verdicts lost in comments; not knowing what happened to her reports.
- **In pinround:** "Report a problem" with pins; review rounds with before/after per pin; "Pass it" / "Needs changes"; My reports like parcel tracking.

### Raj — product manager

- **Goals:** see how each team is doing without asking; spot teams where work waits.
- **Frustrations:** each team uses other status names; spreadsheets every Friday.
- **In pinround:** "Teams at a glance": one bar per team in the five groups, a flag when more than 25% of open work waits.

### Sara — organization admin

- **Goals:** set up people and teams fast; decide who may change steps; control AI and data rules; see who changed what.
- **Frustrations:** settings spread everywhere; no audit trail; unclear AI data flow.
- **In pinround:** Admin pages with clear switches; activity log; AI "where it runs" and "what is sent" choices.

### Ben — support agent (reporter)

Ben is not in a product team. He hears about problems from customers.
- **Goals:** report a problem in two minutes; know what happened to it; answer follow-up questions quickly.
- **Frustrations:** he does not know which team owns a page; his reports vanish.
- **In pinround:** Team "Not sure" goes to the triager; My reports shows "Needs you" when someone asks him.

### Noor — new joiner (developer, first week)

- **Goals:** understand what to do without learning a workflow by heart.
- **Frustrations:** status words like "Awaiting implementation" or "Approval technical owner".
- **In pinround:** Now/Next lines; verbs; private "Explain it to me" for tickets full of product words.

## Role model

pinround separates **permission roles** from **jobs**.

### Organization roles (company-wide, additive)

| Role | What it adds |
|---|---|
| **Admin** | Admin pages: people and roles, teams, statuses and policy, AI, pings, new-ticket rules, connections, activity log, data. Sees all tickets, including team-only ones. Can comment, ask questions and ping on any ticket, but cannot move tickets or edit ticket fields unless they also have a team role. |
| **Manager** | "Teams at a glance" for all teams. Can read, comment, ask questions and ping on tickets of all teams (not team-only ones outside their teams). Does not move tickets. |

A person can have none, one or both. Every person in an organization is a **user** of it.

### Team roles (per team)

| Role | What it allows in that team |
|---|---|
| **Lead** | Everything a member can do, plus: plan, give tickets to people, check new tickets, move to any step, put on hold / go on, change team steps and rules if the policy allows, link projects. |
| **Member** | Report, comment, ask questions, work on tickets given to them, review code, test, use rounds. |
| **Viewer** | Read and comment. Cannot move tickets or be given tickets. |

A person can be Lead in one team and Member in another.

### Jobs (per team membership)

`tester`, `developer`, `other`. Jobs are not permissions. pinround uses them to know whose turn it is (testing → the team's tester) and to suggest reviewers and assignees.

### How a check is decided

For an action on a ticket, pinround looks at: the user's org roles, their team role in the ticket's team, their job, the ticket's step, the team rules and the ticket's visibility. Everything is denied unless a rule allows it ([ADR-0013](../03-adr/0013-authorization.md)).

## Permission matrix

Legend: ✅ allowed · ⚠️ allowed with a condition (see note) · — not allowed.
"Member" means a member of the ticket's team. "Other user" means a user of the organization outside that team.

| Action | Admin | Manager | Lead | Member | Viewer | Other user |
|---|---|---|---|---|---|---|
| See tickets (visibility "all") | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| See team-only tickets | ✅ | ⚠️ only if member of the team | ✅ | ✅ | ✅ | ⚠️ only if reporter, assignee, reviewer, tester, waited-on person, or the triager of a no-team ticket ⁵ |
| Report a problem (create ticket through the intake) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| New ticket form (create ticket on the team's board) ⁷ | ⚠️ only with a role on the team | ⚠️ only with a role on the team | ✅ | ✅ | ✅ | — |
| Add my report to an existing ticket | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Comment, reply | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Ask a question (pauses ticket) | ✅ ¹ | ✅ | ✅ | ✅ | — | ⚠️ only as reporter |
| Answer a question asked to me | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Resolve a comment | ⚠️ own | ⚠️ own | ✅ any in team | ⚠️ own | ⚠️ own | ⚠️ own |
| Ping a person | ✅ ¹ | ✅ | ✅ | ✅ | — | ⚠️ only as reporter |
| Plan it (Backlog → To do) | — | — | ✅ | — | — | — |
| Give it to… (assign) | — | — | ✅ | — | — | — |
| Start work / Send to code review | — | — | ⚠️ if assignee | ⚠️ if assignee | — | — |
| Approve code / Ask for changes | — | — | ⚠️ reviewer, not own code ² | ⚠️ reviewer, not own code ² | — | — |
| Send to testing (skip review) | — | — | ⚠️ if rule "review first" is off | ⚠️ assignee, same | — | — |
| Pass it (→ Done) | — | — | ⚠️ only if rule "tester moves to Done" is off | ⚠️ job tester ³ | — | — |
| Needs changes (from Testing) | — | — | — | ⚠️ job tester | — | — |
| Input given · go on | — | — | ✅ | ⚠️ if it is their turn | — | ⚠️ reporter, if their turn |
| Put on hold / Go on | — | — | ✅ | — | — | — |
| Reopen (from Done) | — | — | ✅ | ⚠️ job tester | — | — |
| Move to another step (any) | — | — | ✅ ⁴ | — | — | — |
| Check a new ticket (Looks fine, close as duplicate, ask for more) | ⚠️ if triager | ⚠️ if triager | ✅ own team | — | — | ⚠️ if triager |
| Mark checklist item fixed | — | — | ⚠️ if assignee | ⚠️ if assignee | — | — |
| Checklist item OK / Not fixed | — | — | — | ⚠️ job tester | — | — |
| Pin verdict in a review round (Fixed / Still wrong), section verdict | — | — | ✅ | ⚠️ job tester or the round's reviewer | — | — |
| Keep / dismiss AI suggestion pin | — | — | ✅ | ⚠️ the round's reviewer | — | — |
| Start / edit / share a draft round | — | — | ⚠️ if assignee or it is their turn ⁸ | ⚠️ if assignee or it is their turn ⁸ | — | — |
| See a draft round | ⚠️ only after shared (never unshared) | ⚠️ only after shared | ⚠️ owner, or after shared (a lead never sees others' unshared drafts) | ⚠️ owner, or after shared | ⚠️ after shared | — |
| Make it a new ticket (draft round to report draft) | ⚠️ owner of an unshared draft only | ⚠️ owner of an unshared draft only | ⚠️ owner of an unshared draft only | ⚠️ owner of an unshared draft only | — | — |
| See, edit and send my report drafts ⁹ | ⚠️ own only | ⚠️ own only | ⚠️ own only | ⚠️ own only | ⚠️ own only | ⚠️ own only |
| See and edit my notebooks ¹⁰ | ⚠️ own only | ⚠️ own only | ⚠️ own only | ⚠️ own only | ⚠️ own only | ⚠️ own only |
| Summarize with AI (shared summary) | ⚠️ AI policy | ⚠️ AI policy | ⚠️ AI policy | ⚠️ AI policy | — | — |
| Explain it to me (private) | ⚠️ AI policy | ⚠️ AI policy | ⚠️ AI policy | ⚠️ AI policy | ⚠️ AI policy | ⚠️ AI policy |
| Share my explanation | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Use an explanation as main summary | — | — | ✅ | ⚠️ if reporter | — | ⚠️ if reporter |
| Link / unlink Jira projects to the team | — | — | ✅ | — | — | — |
| Add / remove team steps | — | — | ⚠️ policy extend or team | — | — | — |
| Change team rules | — | — | ⚠️ policy team | — | — | — |
| Create personal access token (MCP) | ⚠️ ⁶ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Teams at a glance | ✅ | ✅ | — | — | — | — |
| Admin pages (all) | ✅ | — | — | — | — | — |
| Activity log, data export, retention | ✅ | — | — | — | — | — |

Notes:
1. An admin with no team role may see every ticket in the org (including team-only ones), comment, ask questions and ping, but may not move tickets or edit ticket fields. An admin who is also a member or lead of the team gets that team role's rights too.
2. Only when the rule "nobody approves their own code review" is on. The reviewer is the team lead, or another developer if the lead wrote the code.
3. Only when the rule "only the tester moves a ticket to Done" is on; otherwise also the lead.
4. Moving to Testing straight from In progress is blocked while "code review must happen before testing" is on.
5. The exact rule is the canonical [ticket visibility predicate](../02-architecture/data-model.md#ticket-visibility-predicate). It is the same for lists, search, duplicate suggestions, My reports, Home, counts, emails, AI, MCP and exports.
6. Creating any token asks for the password and MFA again if the last check is older than 10 minutes. Admin pages and admin actions never accept a token: `ADMIN_*` voters deny every token-authenticated request, so a token cannot be used to skip admin MFA.
7. The simple "New ticket" form files a ticket straight onto the team's board, so it needs a Lead, Member or Viewer role on that team; everyone can still report a problem through the intake ([ADR-0038](../03-adr/0038-create-ticket-team-form-needs-team-role.md)).
8. "Their turn" is the turn read model's person, for example the tester in Testing or the reviewer in Code review, and only on a ticket they may see and not in Done ([ADR-0044](../03-adr/0044-whose-turn-it-is-may-keep-a-draft-round.md)). The owner keeps editing and sharing their draft after the turn passes on.
9. A report draft is its owner's alone: no admin or lead sees it ([ADR-0039](../03-adr/0039-agents-file-report-drafts-a-person-sends-them.md)). A personal access token may make and change one (`reports:write`), but only the person's click in the browser sends it.
10. A notebook is its owner's alone: not admins, not the team, and nobody of another organization; everyone else gets "not found" ([ADR-0046](../03-adr/0046-notebooks-and-scenarios-for-understanding-an-app.md)). A personal access token of the owner may read and change it.

## Special visibility rules

- **Team-only tickets** (security words, or set by a lead): visible to members of the ticket's team, to admins, and to the people working on that ticket (reporter, assignee, pending assignee, reviewer, tester, the person it waits on) — and to the triager while it has no team. Not to managers outside the team. The one exact rule: [ticket visibility predicate](../02-architecture/data-model.md#ticket-visibility-predicate). The ticket page shows "ONLY THE TEAM AND ADMINS SEE THIS". See [FR-INT-09](../01-requirements/functional-requirements.md#int--intake-and-checks).
- **Draft rounds:** only the owner sees them until shared. Admins and leads do not see unshared drafts. Claude Code sees them only with the owner's personal access token ([ADR-0017](../03-adr/0017-private-draft-rounds.md)).
- **Private explanations ("Explain it to me"):** only the person who asked sees them until they share.
- **Pings:** only sender and recipient see a ping.
- **Invitation links:** an invitation is accepted only by the account with the invited email, logged in, or by a logged-out person when nobody has an account with that email yet.

## Multi-role behaviour

A person can have several roles at once, for example Lead of the shop team and Admin. pinround never asks them to switch modes.

- **One Home.** Home shows a part for each role, in a fixed order: My work (needs you now, also waiting, pings), My team (release bar, team), All teams (if manager), Admin card (if admin, only alerts).
- **Focus chips** at the top of Home: All · My work · My team · All teams · Admin. A chip only narrows the page. When a part is hidden and has something waiting, its chip shows a red number.
- **Navigation** shows the items for all the person's roles: Home, Tickets, My reports, Team, and Admin if they are an admin.
- **Admin is a separate page** because settings are rare and risky. The admin card on Home only shows alerts (for example "2 invites are not accepted yet").
- **Pure admins** (no team role) get a short Home: organization stats and "Open admin settings". **Pure managers** get "Teams at a glance" as their Home.

Why: hidden modes make people forget work; a page that shows all their work in order does not ([ux-principles.md](ux-principles.md#7-one-home-for-all-roles)).

## Examples of role combinations

| Person | Org roles | Team roles | What their Home shows |
|---|---|---|---|
| Lena | Admin | Lead (shop), job developer | My work, My team, Admin card. Nav has Admin. |
| Omar | — | Member (shop), developer; Member (mobile), developer | My work across both teams, My team. |
| Raj | Manager | Viewer (shop) | All teams as main part; can read and comment in shop. |
| Sara | Admin | — | Organization stats and admin alerts only. |
