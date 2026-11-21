# Phase 11 — Admin and governance

Goal: an admin can run the organization in ten minutes a week: people and roles, teams, how work moves, pings, data keeping, and a trustworthy activity log.

## Index
- [Before you start](#before-you-start) — prototype screens, requirements, ADRs and docs to read first
- [Outcome](#outcome) — what people can do when this phase is done
- [Scope](#scope) — what this phase builds
- [Out of scope](#out-of-scope) — what waits
- [Requirements covered](#requirements-covered) — FR and NFR IDs
- [Design notes](#design-notes) — modules, tables, routes, security and speed notes
- [Tasks](#tasks) — P11.T1 to P11.T9, one session each
- [Definition of done](#definition-of-done) — phase checklist
- [Hand-off notes](#hand-off-notes) — what phase 12 must know

## Before you start
1. **Open the prototype.** Link: in your `CLAUDE.local.md` (private to its owner). In Claude Code use the Artifact tool with `action: "read"`, `url` = the link and `path: "project/Main.dc.html"`. View as **Admin** and open every Admin tab: People and roles, Teams, Statuses, AI, Pings, New tickets, Connections, Activity log, Data. Then view as **Lead + Admin** to see the Admin card on Home. Turn on "Big team" to see long lists (prototype-only device; build real long-list behaviour, not the toggle).
2. **If you cannot open the link**, read [admin.md](../04-prototype/screens/admin.md): [layout](../04-prototype/screens/admin.md#layout), [defaults](../04-prototype/screens/admin.md#defaults), [people and roles](../04-prototype/screens/admin.md#people-and-roles), [teams](../04-prototype/screens/admin.md#teams), [statuses](../04-prototype/screens/admin.md#statuses), [pings](../04-prototype/screens/admin.md#pings), [new tickets](../04-prototype/screens/admin.md#new-tickets), [activity log](../04-prototype/screens/admin.md#activity-log), [data](../04-prototype/screens/admin.md#data); plus [home-admin.md](../04-prototype/screens/home-admin.md) and [team.md › How work moves here](../04-prototype/screens/team.md#how-work-moves-here).
3. **Read the requirements:** all of [ADM](../01-requirements/functional-requirements.md#adm--admin) (FR-ADM-01, FR-ADM-02, FR-ADM-03, FR-ADM-04, FR-ADM-05, FR-ADM-06, FR-ADM-07, FR-ADM-08, FR-ADM-09, FR-ADM-10, FR-ADM-11) and [AUD](../01-requirements/functional-requirements.md#aud--audit-and-data) (FR-AUD-01, FR-AUD-02, FR-AUD-03, FR-AUD-04, FR-AUD-05); FR-ID-02 in [ID](../01-requirements/functional-requirements.md#id--identity-and-access); FR-ORG-02, FR-ORG-03, FR-ORG-04, FR-ORG-08, FR-ORG-09 in [ORG](../01-requirements/functional-requirements.md#org--organization-teams-and-roles); FR-WF-03, FR-WF-04, FR-WF-05, FR-WF-06 in [WF](../01-requirements/functional-requirements.md#wf--workflow-steps-and-turns); FR-PNG-02, FR-PNG-05, FR-PNG-06 in [PNG](../01-requirements/functional-requirements.md#png--pings-and-notifications); [PRIV](../01-requirements/non-functional-requirements.md#priv--privacy), [SEC](../01-requirements/non-functional-requirements.md#sec--security).
4. **Read the decisions and design:** [ADR-0013 authorization](../03-adr/0013-authorization.md), [ADR-0014 status model](../03-adr/0014-status-model-five-groups.md), [ADR-0026 audit log](../03-adr/0026-audit-log.md), [ADR-0024 notifications and pings](../03-adr/0024-notifications-and-pings.md), [ADR-0012 authentication](../03-adr/0012-authentication.md); [personas-and-roles.md › Permission matrix](../00-product/personas-and-roles.md#permission-matrix), [personas-and-roles.md › Multi-role behaviour](../00-product/personas-and-roles.md#multi-role-behaviour), [workflow-engine.md › Company steps, team steps and the step policy](../02-architecture/workflow-engine.md#company-steps-team-steps-and-the-step-policy), [workflow-engine.md › Rules (guards)](../02-architecture/workflow-engine.md#rules-guards), [data-model.md › Partitions and retention](../02-architecture/data-model.md#partitions-and-retention), [modules.md › Audit](../02-architecture/modules.md#audit-aud_), [security.md › Authorization model](../02-architecture/security.md#authorization-model), [operations.md › Scheduled jobs](../02-architecture/operations.md#scheduled-jobs).
5. **Check the previous phase:** [phase 10 Definition of done](phase-10-integrations.md#definition-of-done). Several admin pieces exist already (AI tab from phase 08, Connections from phase 10, New tickets settings from phase 06, step policy from phase 02). In this phase you finish, unify and harden them; do not rebuild them.

## Outcome
Sara opens Admin on Monday. She invites a new tester, gives Raj the Manager role with one chip, creates the "Mobile" team with Noor as lead, and switches the step policy to "Company steps plus team steps". A manager preview shows that every team still maps to the same five groups. She sets data keeping to 2 years, checks the activity log for last week's role changes and exports it for an auditor. Every action is in the log, and nobody, not even Sara, can edit the log.

## Scope
- Admin shell: tab list, one layout, MFA re-check for sensitive tabs.
- People and roles: Admin/Manager chips, team role per team, invite, deactivate, revoke sessions/tokens.
- Teams: list and create with lead.
- Statuses: policy cards, company steps per group, rules switches, manager preview.
- Pings settings; New tickets settings check-up; Admin home stats.
- Activity log: filters, export.
- Data: retention setting, warning, nightly purge, organization export.
- Long-list behaviour for big orgs (search, paging, "+N").

## Out of scope
- Billing and plans.
- SSO group-to-role sync.

## Requirements covered
FR-ADM-01, FR-ADM-02, FR-ADM-03, FR-ADM-04, FR-ADM-05, FR-ADM-06, FR-ADM-07, FR-ADM-08, FR-ADM-09, FR-ADM-10, FR-ADM-11, FR-AUD-01, FR-AUD-02, FR-AUD-03, FR-AUD-04, FR-AUD-05, FR-ID-02, FR-ORG-02, FR-ORG-03, FR-ORG-04, FR-ORG-08, FR-ORG-09, FR-WF-03, FR-WF-04, FR-WF-05, FR-WF-06, FR-PNG-02, FR-PNG-05, FR-PNG-06, FR-HOME-09, FR-HOME-11.
NFR-SEC-01, NFR-SEC-04, NFR-SEC-05, NFR-PRIV-03, NFR-PRIV-04, NFR-PRIV-05, NFR-USE-01, NFR-A11Y-01, NFR-SCAL-04.

## Design notes
- **Modules.** Web owns the admin shell: `App\Web\UI\Controller\Admin\AdminShellController` (`GET /admin`, route `web_admin`, which redirects to the first tab the person may open), the tab rail `App\Web\UI\Admin\AdminTabRail` and the layout `@Web/admin/layout.html.twig` (`src/Web/UI/templates/admin/layout.html.twig`). Each tab is a page of its owning module at `/admin/<tab>` (tab ∈ people, teams, statuses, ai, pings, new-tickets, connections, activity, data) whose template extends that layout and whose forms post to the same module: Organization (people, teams, pings, new tickets), Workflow (statuses), Assist (AI), Integration (connections), Audit (activity, data). The rail lists each tab by its route name, so Web reads no module's UI. P14.T3 built the shell with People and AI, both Organization's today (the AI page moves to Assist only if P11 decides so).
- **Tables.** `org_membership` (org_roles `text[]`: admin, manager), `org_team`, `org_team_member` (team_role lead|member|viewer, job), `org_invitation`, `org_settings.data` (ping_limit_hours, auto_clear, quiet_hours, lead_check, triager_id, step_policy, ai…), `wf_step`, `wf_rule_set`, `aud_event` (append-only, monthly partitions), `aud_export`.
- **Security.** Voters `ADMIN_PEOPLE`, `ADMIN_TEAMS`, `ADMIN_STATUSES`, `ADMIN_PINGS`, `ADMIN_DATA`, `ADMIN_AUDIT`; deny by default. Sensitive actions (role changes, retention change, export, connection changes) need a recent MFA check (re-auth within 10 minutes). The last admin cannot remove their own Admin role. Role change rotates the affected user's session. Audit events hold old/new values, never ticket text. `pinround_app` has `INSERT`/`SELECT` on the `aud_event` **parent table only**; all privileges are revoked on its partitions (a direct partition query would skip the parent's RLS policy); the catalog test checks this through `pg_inherits` (P00.T4, P00.T7).
- **Retention (binding):** the purge never deletes open tickets; it deletes closed tickets older than the setting with their rounds, messages, media and **only their** events (events of open tickets stay, whatever their age), and old audit events (security events kept at least 1 year). A warning shows 30 days before the first purge after a change. It runs as `pinround_retention`, the only `BYPASSRLS` role ([ADR-0005](../03-adr/0005-multi-tenancy.md)).
- **Partitions and "always" retention:** partitions are shared by all orgs, so a monthly partition can be dropped only when no org still needs any row in it (every org's cutoff is past it, and no open ticket has events in it). Orgs with "always" keep partitions forever; for everyone else the purge deletes rows in batches by `organization_id`. Partition primary keys include the partition key (`(id, at)`).
- **Exports (binding):** the email never carries the file link. It links to an app page that checks again that the person is an admin with a recent MFA check, then issues a 5-minute signed URL. Exports never include unshared draft rounds or private AI explanations, and apply the TicketVisibility predicate for the exporting admin.
- **Speed.** People list paged (keyset) and searchable; activity log keyset paged on `(organization_id, at DESC)`; export always async.

## Tasks

### P11.T1 — Admin shell and access rules
- **Why:** one consistent place, safe by default.
- **Steps:**
  1. Admin layout with the tab list (labels as prototype), active tab, plain sub-titles: extend P14.T3's layout and add each new tab to `AdminTabRail::TABS`.
  2. Voters per tab; nav shows "Admin" only for admins; non-admins get 404 on `/admin/*` (P14.T3's `HideAdminAreaFromNonAdmins` does it for every `ADMIN_AREA` refusal).
  3. Use the re-auth flow ("Confirm it's you", `RecentAuth` from P01.T6, remembered 10 minutes) for every sensitive action listed in the design notes.
  4. Admin home for pure admins: "Your organization" stats (People, Teams, AI help, Jira) linking to tabs (if phase 07 built it, reuse).
- **Files:** `src/Web/UI/Controller/Admin/AdminShellController.php`, `src/Web/UI/Admin/AdminTabRail.php`, `src/Web/UI/templates/admin/layout.html.twig` (all from P14.T3).
- **Acceptance:**
  - [ ] Every admin route has a voter test for each role (viewer, member, lead, manager, admin).
- **Tests:** voter table test; functional 404 for non-admins.
- **Prototype check:** [admin.md › Layout](../04-prototype/screens/admin.md#layout).
- **Size:** M

### P11.T2 — People and roles, invites
- **Why:** roles decide what people see; mistakes here are security mistakes.
- **Steps:**
  1. List people: name, team/source, Admin and Manager chips (toggle, company-wide), team role select per team (Team lead / Team member / Team viewer), role help text.
  2. Search and keyset paging; avatar stacks elsewhere keep "4 + N".
  3. Invite by email (validation; single-use link valid 72 h; resend; cancel); "Invited · not joined yet" state; Admin card alert for invites not accepted.
  4. Deactivate person: ends sessions, revokes tokens, keeps their history.
  5. Guards: last admin cannot lose Admin; role change rotates the user's session; audit `OrgRolesChanged`, `TeamRoleChanged`, `MemberJoined`.
- **Files:** `src/Organization/UI/Admin/PeopleController.php`, `src/Organization/Application/ChangeOrgRoles.php`, `templates/admin/people.html.twig`.
- **Acceptance:**
  - [ ] A user can hold Admin and Manager and a team role at once; Home shows all parts (phase 07).
  - [ ] Copy matches: "Admin and Manager are for the whole company… The team role is per team."
- **Tests:** unit (last-admin guard), functional (chips, select, invite), audit rows, isolation.
- **Prototype check:** [people and roles](../04-prototype/screens/admin.md#people-and-roles).
- **Size:** L

### P11.T3 — Teams
- **Why:** teams own projects and steps.
- **Steps:**
  1. Teams list: name, lead, size, projects, tag NEW for teams created this week.
  2. Create team: name + lead (select from active people); lead gets team role Lead.
  3. Rename, change lead, archive (only when no open tickets, else explain).
- **Files:** `src/Organization/UI/Admin/TeamsController.php`, `templates/admin/teams.html.twig`.
- **Acceptance:**
  - [ ] Creating a team shows toast "Team created. <lead> can now link projects."
- **Tests:** functional, audit, voter.
- **Prototype check:** [teams](../04-prototype/screens/admin.md#teams).
- **Size:** S

### P11.T4 — Statuses: policy, company steps, rules, manager preview
- **Why:** admins decide who may shape the process, and managers must still compare teams.
- **Steps:**
  1. Policy cards: "Admin only" (locked), "Company steps plus team steps" (extend, default), "Team decides" (team); log "set "Who may change steps" to …".
  2. Company steps per group: add (name + group chip, not Done), remove (only company steps; core steps fixed); toast "Every team now has "<name>"."
  3. Rules switches: only the tester moves a ticket to Done; nobody approves their own code review; code review before testing. Under "Team decides" these are defaults that leads can change.
  4. Manager preview: five-group bars for each team, as managers see them.
  5. Removing a step that has tickets: ask where those tickets go (pick a step in the same group).
- **Files:** `src/Workflow/UI/Admin/StatusesController.php`, `templates/admin/statuses.html.twig`.
- **Acceptance:**
  - [ ] Every step sits in exactly one of the five groups (database constraint + UI).
  - [ ] Policy "locked" hides team-step controls on Team pages.
- **Tests:** unit (policy rules), functional, audit (`StepAdded`, `StepRemoved`, `RulesChanged`).
- **Prototype check:** [statuses](../04-prototype/screens/admin.md#statuses), [team.md › How work moves here](../04-prototype/screens/team.md#how-work-moves-here).
- **Size:** M

### P11.T5 — Pings and New tickets settings
- **Why:** keep pings useful and new-ticket checks fitting the org.
- **Steps:**
  1. Pings: "One ping per person per ticket every" 1/4/8/24 h; switch "Clear a ping when the person acts on that ticket"; switch "Quiet hours: hold pings between 18:00 and 09:00" (per recipient time zone).
  2. New tickets: confirm phase 06 built the lead-check switch, triager select and reasons list; fix gaps; audit changes.
  3. Settings saved in `org_settings.data` with optimistic `version`.
- **Files:** `src/Organization/UI/Admin/PingSettingsController.php`, `templates/admin/pings.html.twig`.
- **Acceptance:**
  - [ ] Changing the limit affects the next ping at once (RateLimiter reads the setting).
- **Tests:** functional; integration with Notification rate limiter.
- **Prototype check:** [pings](../04-prototype/screens/admin.md#pings), [new tickets](../04-prototype/screens/admin.md#new-tickets).
- **Size:** S

### P11.T6 — Activity log (audit) with filters and export
- **Why:** trust needs a record nobody can change.
- **Steps:**
  1. Make sure every event in FR-AUD-01 writes `aud_event` (logins, role/membership, settings, visibility, exports, tokens, MCP calls, AI policy, Jira connection).
  2. Activity log tab: numbered entries "who · what (target)", newest first, filters (person, action type, date range), keyset paging.
  3. Export filtered log as CSV/JSON (async; the "Export ready" email links to the app's download page, which re-checks admin + MFA and issues a 5-minute signed URL; the file is kept 24 h; audited).
  4. Database grants: `pinround_app` has `INSERT`/`SELECT` on the `aud_event` parent only, nothing on partitions; tests prove `UPDATE`/`DELETE` fail and a direct partition `SELECT` fails.
- **Files:** `src/Audit/UI/Controller/ActivityLogController.php`, `src/Audit/Infrastructure/Doctrine/AuditQuery.php`, migration for grants.
- **Acceptance:**
  - [ ] Audit data never contains ticket text (test with a marker).
  - [ ] Ticket activity and audit log stay separate stores (FR-AUD-05).
- **Tests:** integration (grants), coverage test listing admin actions → audit rows, functional filters.
- **Prototype check:** [activity log](../04-prototype/screens/admin.md#activity-log).
- **Size:** M

### P11.T7 — Data: retention setting and nightly purge
- **Why:** keep only what is needed (GDPR, NFR-PRIV-03).
- **Steps:**
  1. Data tab: keep data 1 year / 2 years / always; warning 30 days before the first purge after a change.
  2. Nightly purge job (Scheduler + Lock, runs as `pinround_retention`): closed tickets past the org's cutoff with their rounds, messages, media (bucket deletes by worker) and their own events; never events of open tickets; audit events past cutoff except security events < 1 year; batches of 5,000 rows per org.
  3. Partition drop only when no org needs any row in it (see design notes); otherwise row deletes.
  4. The purge writes its own audit event per org (counts only).
- **Files:** `src/Audit/Infrastructure/Scheduler/RetentionPurge.php`, `templates/admin/data.html.twig`, `config/packages/doctrine.yaml` (retention connection).
- **Acceptance:**
  - [ ] Purge never deletes open tickets or their events (test with a 3-year-old open ticket).
  - [ ] Purge is idempotent and resumable (kill it mid-batch, run again).
  - [ ] An org with "always" keeps everything; a partition holding its rows is not dropped.
  - [ ] Only the purge job is configured with `pinround_retention` (config test).
- **Tests:** integration with a time-shifted `Clock` and two orgs with different settings.
- **Prototype check:** [data](../04-prototype/screens/admin.md#data) (keep options and warning text).
- **Size:** M

### P11.T8 — Organization export and person erasure
- **Why:** admins can take their data with them, and people can ask to be forgotten (NFR-PRIV-04, NFR-PRIV-05).
- **Steps:**
  1. Organization export: tickets, messages, rounds metadata, audit log as JSON + CSV, screenshots as ZIP; async; excludes unshared draft rounds and private explanations; applies the TicketVisibility predicate for the exporting admin.
  2. "Export ready" email links to the app download page, which re-checks admin + MFA and issues a 5-minute signed URL; file kept 24 h; audited.
  3. Person erasure request (GDPR): anonymize author fields, keep tickets; audited.
- **Files:** `src/Audit/Application/ExportOrganization.php`, `src/Audit/UI/Controller/ExportDownloadController.php`, `src/Identity/Application/ErasePerson.php`.
- **Acceptance:**
  - [ ] The export contains only the own org (isolation) and no unshared draft or private explanation (marker tests).
  - [ ] A download link opened after 5 minutes fails; opening the email link as a non-admin fails.
  - [ ] After erasure, the person's name appears nowhere in the UI; tickets remain.
- **Tests:** integration (export content, isolation), functional (download page), erasure test.
- **Prototype check:** [data](../04-prototype/screens/admin.md#data) (export button and text).
- **Size:** M

### P11.T9 — Big-organization checks
- **Why:** pages that work with 3 people must also work with 2,000.
- **Steps:**
  1. Seed the large-org shape (2,000 people, 200,000 tickets) with the generator from testing-strategy.md.
  2. Check every list for search, paging and "+N": people picker, Team people (6 + "Show all"), avatar stacks, activity log, admin people.
  3. Measure p95 and query counts of admin pages; fix slow ones.
- **Files:** `tests/Load/Generator/*`, fixes where needed.
- **Acceptance:**
  - [ ] No admin page renders more than 100 rows at once.
  - [ ] Admin pages p95 ≤ 300 ms on the large org (local).
- **Tests:** functional with large fixtures (tagged slow), query-count tests.
- **Prototype check:** Big team toggle views (as reference only).
- **Size:** M

## Definition of done
- [ ] All P11 tasks ticked; every Admin tab matches the prototype or the difference is noted.
- [ ] Voter table tests for all admin routes; audit coverage test; append-only grant test; retention and export tests.
- [ ] Re-auth required for sensitive admin actions.
- [ ] Quality gates green; docs and Index updated.

## Hand-off notes
- Phase 12 runs the ASVS review and load tests; list here any admin page that was slow or any audit gap you accepted.
- 2026-11-20 (plan change): [P14.T3](phase-14-tester-feedback.md#p14t3--menu-admin-area-and-links-that-work) builds the smallest admin shell (route for the menu, tab rail with People and AI, a read-only people list). P11.T1 extends that shell instead of starting a new one; P11.T2 adds editing to the people list.
- 2026-11-22 (from the P14 UI check, medium): Admin › People shows no Admin/Manager chips, no team role per team and no Deactivate yet; P11.T2 adds them.
