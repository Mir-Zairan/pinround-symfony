# ADR-0013 — Authorization: org roles plus team roles, checked by Symfony voters

Every action is allowed only if a voter says yes; roles come from the organization and from each team.

## Index

- [Status](#status) — accepted, date, deciders.
- [Context and problem statement](#context-and-problem-statement) — many roles, private data.
- [Decision drivers](#decision-drivers) — security, clarity, multi-role people.
- [Considered options](#considered-options) — global roles, ACL lists, voters on org+team roles, a policy engine.
- [Decision outcome](#decision-outcome) — the role model, permission matrix, visibility, voters.
- [Confirmation](#confirmation) — test suites and checks.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — trade-offs.
- [Clarifications (2026-11-06)](#clarifications-2026-11-06) — the canonical visibility predicate, admin rights, tokens.
- [More information](#more-information) — links and revisit triggers.

## Status

Accepted · 2026-11-06 · Deciders: Zairan Mir (lead).

Superseded in part by [ADR-0038](0038-create-ticket-team-form-needs-team-role.md) (create ticket row): the team ticket form needs a Lead, Member or Viewer role on the team; "Report a problem" stays open to every role. Everything else here still stands.

## Context and problem statement

Lena leads the Shop team and is also an Admin for Acme Foods. Omar is a member of the Shop team
and a viewer in the Mobile team. Raj is a Manager: he sees every team's progress but should not move
tickets. Mia reports a password leak; the ticket must be hidden from people outside her team,
even from Raj. Omar's draft round and his private "Explain it to me" answers are only his.

How do we model who may do what, so that it is correct, easy to explain, and checked everywhere?

## Decision drivers

- Deny by default; one place per decision (NFR-SEC, OWASP A01 broken access control).
- People can hold several roles at once; no mode switch (product decision, see personas doc).
- Sensitive tickets and private data must stay private even to managers.
- The same checks for the UI, the REST API and MCP tools ([ADR-0021](0021-mcp-server.md)).
- Simple enough to explain on one admin page.

## Considered options

1. Global Symfony roles only (`ROLE_ADMIN`, `ROLE_LEAD` …).
2. Per-object access control lists (ACL) stored per ticket.
3. Org roles + team roles from memberships, with Symfony voters per resource and action.
4. An external policy engine (for example OPA/Cedar).

## Decision outcome

Chosen option: **org roles + team roles, checked by Symfony voters (option 3)**.

Role model:

- **Org roles** (company-wide, additive, any combination or none): `Admin`, `Manager`.
- **Team roles** (per team membership): `Lead`, `Member`, `Viewer`.
- **Job** (tester, developer, other) is a label for routing turns, not a permission.
- Effective permissions = union of the person's org roles and the role in the ticket's team.

Permission matrix (main actions; voters hold the full rules):

| Action | Team Viewer | Team Member | Team Lead | Manager | Admin |
|---|---|---|---|---|---|
| See tickets of the team (visibility `all`) | yes | yes | yes | yes | yes |
| See `team`-only (sensitive) tickets | yes | yes | yes | no (unless also in the team or working on the ticket) | yes |
| Comment, ask a question, ping | comment only | yes | yes | yes | yes (even without a team role) |
| Report a problem (create ticket) | yes | yes | yes | yes | yes |
| Move a ticket with a verb | no | if the rule allows the person (turn, assignee, tester) | yes | no | no |
| Give a ticket to someone, plan, hold | no | no | yes | no | no |
| Check new tickets | no | no | yes (own team); triager for no-team | no | triager if chosen |
| Change team steps / rules | no | no | if policy allows | no | yes (company level) |
| Manage people, roles, AI, connections, retention | no | no | no | no | yes |
| See all teams overview | no | no | own team | yes | yes |

Owner-only data, whatever the roles:

- Draft rounds until shared ([ADR-0017](0017-private-draft-rounds.md)).
- Private "Explain it to me" answers until shared ([ADR-0018](0018-ai-provider-abstraction.md)).
- Personal access tokens and personal notification settings.

Voters:

- One voter per aggregate: `TicketVoter`, `RoundVoter`, `ScreenshotVoter` (via its round), `ThreadVoter`,
  `TeamVoter`, `OrgSettingsVoter`, `TokenVoter`.
- Attributes are named actions, for example `TICKET_VIEW`, `TICKET_COMMENT`, `TICKET_MOVE` (with the transition as subject data),
  `TICKET_ASSIGN`, `TICKET_CHECK`, `ROUND_EDIT`, `ROUND_SHARE`, `TEAM_MANAGE_STEPS`, `ADMIN_PEOPLE`, `ADMIN_AI`.
- Voters read a precomputed `Permissions` object for the current user and org (memberships loaded once per request, cached per request,
  reset in worker mode).
- The access decision strategy is `unanimous` with `allow_if_all_abstain: false`, so missing voters mean deny (verify config keys at implementation).
- List queries do not call voters row by row. Repositories apply the same visibility rule in SQL — the
  canonical **ticket visibility predicate** below — and a test checks the SQL and the voter agree.
- Not found and not allowed both return 404 for tenant data, so ids cannot be probed.

### Consequences

- Good: one clear model that matches the product (multi-role, no mode switch).
- Good: the UI, API and MCP share the same voters.
- Bad: rules exist twice (voter and SQL filter) for lists; they need a sync test.
- Bad: matrix changes need care; every new action needs a voter attribute and tests.
- Neutral: Symfony's global roles are used only for `ROLE_USER` (signed in).

## Confirmation

- **Voter test suite**: a table-driven test runs every attribute against every role combination
  (Viewer/Member/Lead × Manager/Admin/none × visibility all/team × owner/non-owner) and compares with the matrix above.
- **List-vs-voter consistency test**: for random data, every row a list query returns must pass `TICKET_VIEW`, and no viewable ticket may be missing.
- A PHPStan/architecture rule: every controller action and every MCP tool calls `denyAccessUnlessGranted` or is marked public with a comment.
- An e2e test: Raj (Manager) cannot see a `team`-only ticket by URL, list, search, Home, or MCP.

## Pros and cons of the options

### Global roles only

- Good, because it is built into Symfony and simple.
- Bad, because it cannot express "lead in team A, member in team B" or ticket visibility.

### Per-object ACL

- Good, because it is very flexible.
- Bad, because there are millions of ACL rows, it is hard to explain, and lists are slow.

### Org + team roles with voters

- Good, because it matches the product, is fast, and is testable.
- Bad, because list filters must mirror voters.

### External policy engine

- Good, because policies are central and can be audited.
- Bad, because it is another system and language for a team of 3; latency per check.

## Clarifications (2026-11-06)

The decision is unchanged; these lines make it exact.

**Canonical ticket visibility predicate.** Who may see a ticket with `visibility = 'team'` is decided by one rule, defined here and in [data-model.md › Ticket visibility predicate](../02-architecture/data-model.md#ticket-visibility-predicate), implemented once in `App\Ticketing\Api\TicketVisibility` (SQL fragment + PHP check used by `TicketVoter`):

```sql
(   t.visibility = 'all'
 OR t.team_id = ANY(:teams)
 OR :me IN (t.reporter_id, t.assignee_id, t.pending_assignee_id,
            t.reviewer_id, t.tester_id, t.waiting_on_user_id)
 OR :is_admin
 OR (t.team_id IS NULL AND :me = :triager) )
```

Every path uses it: lists, search, duplicate suggestions, My reports, Home, Whose turn and workload counts, manager counts, Mercure signals, emails, AI inputs, MCP tools and exports. Without the people line, a reporter outside the team would lose their own security report the moment it is created.

**Admin without a team role.** May view every ticket, comment, ask questions and ping. May not move tickets or edit ticket fields (`TICKET_MOVE`, `TICKET_ASSIGN`, `TICKET_EDIT` deny unless the person also has a team role). Managers may comment, ask and ping too.

**Personal access tokens.** `ADMIN_*` attributes deny every request authenticated by a personal access token, so a token can never be used for admin work without an MFA-checked session. Creating a token needs a password + MFA check from the last 10 minutes ([ADR-0021](0021-mcp-server.md)).

## More information

- Personas and roles: [personas-and-roles.md](../00-product/personas-and-roles.md). Security: [security.md](../02-architecture/security.md).
- Revisit when: customers ask for custom roles, or the matrix needs more than 3 new attributes per quarter.
