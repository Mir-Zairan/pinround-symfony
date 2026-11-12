# Phase 03 — Tickets core

Goal: tickets exist and move. People create tickets, see them grouped by step or by whose turn, search them, and work on a ticket page that always says what happens now and next.

## Index
- [Before you start](#before-you-start) — prototype screens, requirements, ADRs, docs
- [Outcome](#outcome) — what works after this phase
- [Scope](#scope) — what this phase builds
- [Out of scope](#out-of-scope) — what waits
- [Requirements covered](#requirements-covered) — FR and NFR ids
- [Design notes](#design-notes) — tables, routes, queries, budgets
- [Tasks](#tasks) — P03.T1 to P03.T10
- [Definition of done](#definition-of-done) — phase checklist
- [Hand-off notes](#hand-off-notes) — notes for P04 and P05

## Before you start
1. **Prototype.** Open https://claude.ai/artifact/FmbJosB3s5mDi75cLqMFQ2 (Artifact tool, `action: "read"`, `path: "project/Main.dc.html"`). Study Tickets (List and "Whose turn?"), the ticket page for each role, the person picker, the queue bar after "Start reviews" on Home. Turn on "Big team" (prototype-only) to see the person picker. Fallback docs: [Tickets list and whose turn](../04-prototype/screens/tickets-list-and-whose-turn.md) (all sections), [Ticket page](../04-prototype/screens/ticket-page.md): [Header](../04-prototype/screens/ticket-page.md#header), [The ask](../04-prototype/screens/ticket-page.md#the-ask), [Your next step box](../04-prototype/screens/ticket-page.md#your-next-step-box), [People, What happened, Details](../04-prototype/screens/ticket-page.md#people-what-happened-details), [Every move](../04-prototype/screens/ticket-page.md#every-move), [Role differences](../04-prototype/screens/ticket-page.md#role-differences); [Person picker](../04-prototype/screens/dialogs-and-popovers.md#person-picker); [Queue bar](../04-prototype/screens/home-work.md#queue-bar); [Toast](../04-prototype/screens/dialogs-and-popovers.md#toast).
2. **Requirements:** [FR-TKT-01, FR-TKT-02, FR-TKT-03, FR-TKT-04, FR-TKT-05, FR-TKT-06, FR-TKT-07, FR-TKT-08, FR-TKT-09, FR-TKT-10, FR-TKT-11, FR-TKT-12, FR-TKT-13, FR-TKT-14, FR-TKT-15, FR-TKT-16, FR-TKT-17, FR-TKT-18](../01-requirements/functional-requirements.md#tkt--tickets), [FR-INT-01, FR-INT-02](../01-requirements/functional-requirements.md#int--intake-and-checks) (simple create only), [FR-WF-07, FR-WF-10, FR-WF-11, FR-WF-16, FR-WF-17](../01-requirements/functional-requirements.md#wf--workflow-steps-and-turns), [FR-ORG-08](../01-requirements/functional-requirements.md#org--organization-teams-and-roles), [NFR-PERF](../01-requirements/non-functional-requirements.md#perf--performance) NFR-PERF-02, NFR-PERF-03, NFR-PERF-04, NFR-PERF-06, NFR-PERF-10, [NFR-SCAL-04](../01-requirements/non-functional-requirements.md#scal--scalability).
3. **ADRs:** [0015 turn read model](../03-adr/0015-turn-read-model.md), [0022 search](../03-adr/0022-search-and-duplicates.md), [0007 server-rendered UI](../03-adr/0007-server-rendered-ui.md), [0009 outbox](../03-adr/0009-async-messaging-and-outbox.md), [0013 authorization](../03-adr/0013-authorization.md).
4. **Architecture docs:** [Ticketing module](../02-architecture/modules.md#ticketing-tkt_), [tables: Ticketing](../02-architecture/data-model.md#ticketing), [query patterns](../02-architecture/data-model.md#query-patterns), [partitions](../02-architecture/data-model.md#partitions-and-retention), [hot paths: list](../02-architecture/performance-and-scale.md#tickets-list-grouped-by-step), [ticket page](../02-architecture/performance-and-scale.md#ticket-page), [search](../02-architecture/performance-and-scale.md#search), [whose turn](../02-architecture/performance-and-scale.md#whose-turn-view), [avoiding N+1](../02-architecture/performance-and-scale.md#avoiding-n1-queries), [route map](../02-architecture/frontend.md#route-map-prototype-screen--route--template), [Turbo](../02-architecture/frontend.md#turbo-and-real-time-updates), [transition catalogue](../02-architecture/workflow-engine.md#transition-catalogue), [your next step box](../02-architecture/workflow-engine.md#your-next-step-box).
5. **Previous phase:** check the [P02 Definition of done](phase-02-projects-and-workflow.md#definition-of-done).

## Outcome
Mia creates SHOP-1 with a simple form. Lena opens Tickets: open tickets grouped Waiting, Checking, Doing, To do, each row with "Turn: Omar". She gives SHOP-1 to Omar from a person picker sorted by load. Omar opens it: "Now: Omar starts building. Next: Omar builds it, Lena reviews, Mia tests." He presses "Start work", then "Send to code review"; the toast says "Sent to code review. Lena's turn now." "Whose turn?" shows that Mia has 4 tickets waiting, the oldest for 9 days. Search for "invoice" finds SHOP-41 in under 300 ms.

## Scope
- `tkt_ticket`, `tkt_ticket_event` (monthly partitions), summary placeholder table `tkt_summary` (filled in P08).
- A simple "New ticket" form (the full "Report a problem" page is P06).
- List grouped by step, filters (project rail, people chips, person picker, Show done), search (FTS + trigram).
- "Whose turn?" view, Team page workload bars.
- Ticket page: header, status pill, Now/Next, note line, The ask (description), Your next step box with verbs, Move to another step, Give it to…, People, What happened, Facts, Previous/Next, queue bar.

## Out of scope
- Comments, questions, pings (P04). The People list shows no Ping button yet.
- Screenshots and rounds (P05). The Rounds section shows the empty state.
- Check reasons and "New · to check" (P06). AI summaries (P08). Home content (P07).

## Requirements covered
FR-TKT-01, FR-TKT-02, FR-TKT-03, FR-TKT-04, FR-TKT-05, FR-TKT-06, FR-TKT-07, FR-TKT-08, FR-TKT-09, FR-TKT-10, FR-TKT-11, FR-TKT-12, FR-TKT-13, FR-TKT-14, FR-TKT-15, FR-TKT-16, FR-TKT-17, FR-TKT-18 (FR-TKT-07 "Show done" only; the to-check chip comes in P06; FR-TKT-11 without Ping; FR-TKT-17 shows the link when an external ref exists), FR-INT-01 and FR-INT-02 (simple form), FR-WF-07, FR-WF-10, FR-WF-11, FR-WF-16, FR-WF-17 (activity line + toast; ping clearing joins in P04), FR-ORG-08, NFR-PERF-02, NFR-PERF-03, NFR-PERF-04, NFR-PERF-06, NFR-PERF-10, NFR-SCAL-04.

## Design notes
- **Ticket aggregate** (`Ticketing\Domain\Ticket`): owns step, assignee, pending assignee, reviewer, tester, previous step, waiting-on, priority + `priority_rank`, visibility, `version` for optimistic locking.
- **Every move** = one transaction: voter → `TransitionPolicy::check` → `ticket.move()` → `TurnProjector::refresh(TicketId)` (its returned override note goes to the activity) → `tkt_ticket_event` row → outbox `TicketMoved` → Turbo Stream response with a toast naming who acts next.
- **Search:** generated `tsvector` column on title + description (weights A/B), GIN index; `pg_trgm` GIN on title for key and fuzzy title matches ([ADR-0022](../03-adr/0022-search-and-duplicates.md)).
- **List query:** one SQL query for the whole page: the visible steps joined `LATERAL` to `(SELECT … ORDER BY priority_rank, updated_at DESC, id DESC LIMIT 51)` per step, so every group gets its first 50 rows plus a "more" flag; keyset pagination on `(priority_rank, updated_at, id)` inside one step for "Show more"; assignee names fetched in one batch through `Identity\Api\UserQueries::byIds`. Indexes come from the canonical list in [data-model.md](../02-architecture/data-model.md) (including `(organization_id, step_id, priority_rank, updated_at DESC, id DESC)` for "All projects"); do not invent extra ones here.
- **Query budgets:** list ≤ 5 queries, ticket page ≤ 10 (enforced by a query-count test helper).
- **Routes:** `/tickets`, `/tickets/turn`, `/tickets/new`, `/t/{key}`, `POST /t/{key}/move/{verb}`, `POST /t/{key}/assign`, `POST /t/{key}/move-to/{stepId}`.
- **Visibility:** one canonical **TicketVisibility predicate**, defined in [data-model.md](../02-architecture/data-model.md) and [ADR-0013](../03-adr/0013-authorization.md): `visibility = 'all' OR team_id = ANY(:my_team_ids) OR :me IN (reporter_id, assignee_id, pending_assignee_id, reviewer_id, tester_id, waiting_on_user_id) OR :is_admin OR (team_id IS NULL AND :me = :triager_id)`. P03.T2 implements it once as `Ticketing\Api\TicketVisibility`; every ticket query, count, search, voter and later the Home, My reports, MCP and export queries use it. `visibility = 'team'` is set in P06, but the predicate and its tests exist from P03.

## Tasks

### P03.T1 — Ticket aggregate and simple create 🏁
**Why:** tickets are the centre of everything ([FR-TKT-01](../01-requirements/functional-requirements.md#tkt--tickets), [FR-INT-01, FR-INT-02](../01-requirements/functional-requirements.md#int--intake-and-checks), [FR-WF-11](../01-requirements/functional-requirements.md#wf--workflow-steps-and-turns)).
**Steps:**
1. Domain: `Ticket`, `TicketKey`, `TicketType`, `Priority` (with rank), `Visibility`; invariants (title 1–200 chars, description ≤ 50,000 chars).
2. Migrations: `tkt_ticket` (all columns from [Ticketing tables](../02-architecture/data-model.md#ticketing), RLS, indexes), `tkt_ticket_event` partitioned monthly with primary key `(id, at)` (create the current and next 2 partitions; a scheduler job creates future ones; partitioned tables are excluded from the Doctrine schema diff), `tkt_summary`; add the foreign key `ins_ticket_turn.ticket_id → tkt_ticket.id`.
3. `TicketCommands::create`: number from `NextTicketNumber`; start in To do when an assignee is chosen, else Backlog; project the turn; event `TicketCreated`.
4. Page `/tickets/new`: title, description, project, type (default Bug), priority (default Normal), give it to (optional; shown to, and accepted from, a Lead of the project's team only, personas-and-roles.md). After create: go to the ticket, toast "Ticket SHOP-12 made."
5. `TicketVoter`: `VIEW`, `CREATE`, `EDIT`, `MOVE`, `ASSIGN` (rows from the [permission matrix](../00-product/personas-and-roles.md#permission-matrix)).
6. Foundry factories and demo seed: 40 tickets in SHOP across all steps with neutral titles.
**Files:** `src/Ticketing/**`, migrations, `tests/Factory/TicketFactory.php`.
**Acceptance criteria:**
- [x] A created ticket has key SHOP-n, an event "created", and a turn row (`CreateTicketTest`).
- [x] A pure manager cannot create tickets through the form (voter) unless they also have a team role (`TicketCreationVoterTest`, `VoterMatrixTest`, `NewTicketTest`; [ADR-0038](../03-adr/0038-create-ticket-team-form-needs-team-role.md)).
- [ ] Org B cannot read SHOP tickets by URL, list or search (isolation tests). URL (voter `VIEW`, `TicketVoterViewLeakTest`) and list (`TicketListLeakTest`, `TicketIsolationTest`) are done; search comes with P03.T4.
- [x] An admin without a team role cannot create or edit tickets (voter rows in `TicketCreationVoterTest` and `TicketVoterTest`).
**Tests:** domain unit tests; voter matrix rows; isolation tests; functional create test.
**Prototype check:** field labels and defaults follow [Report a problem: fields and copy](../04-prototype/screens/report-a-problem.md#fields-and-copy) where they overlap (Type, Priority).
**Size:** L

### P03.T2 — TicketVisibility predicate and the visibility leak suite 🏁
**Why:** a restricted ticket must stay inside its team in every read path, and the people involved (for example an outside reporter) must keep access ([NFR-SEC-13](../01-requirements/non-functional-requirements.md#sec--security), [ADR-0013](../03-adr/0013-authorization.md)).
**Steps:**
1. `Ticketing\Api\TicketVisibility::for(ActorContext)` that renders the canonical TicketVisibility predicate (see design notes) as a SQL fragment with bound parameters and as a PHP check used by `TicketVoter::VIEW`. No other code may write its own visibility condition (add a PHPStan rule or a grep check for `visibility =` outside this class).
2. `ins_ticket_turn` and every later read model carry `visibility` and `team_id` copies so Insight queries can apply the same scope.
3. Create the **visibility leak suite** `tests/Integration/Visibility/`: a team-only ticket with a marker title, an outside reporter, an outside admin, a triager, a manager without team role, and a user of another org. One test per read path; this task adds list, search, ticket page, counts and the voter. Later phases add their paths (P04 bell and email, P05 rounds, P06 duplicates, P07 Home, Whose turn and My reports, P08 AI candidates, P09 MCP, P11 export).
**Files:** `src/Ticketing/Api/TicketVisibility.php`, `src/Ticketing/Api/TicketVisibilitySql.php`, `src/Ticketing/Api/TicketVisibilities.php`, `src/Ticketing/Application/RoleTicketVisibilities.php`, `tests/Integration/Visibility/*`.
**Acceptance criteria:**
- [ ] An outside reporter of a team-only ticket sees it in the list, search and ticket page; an outside user with no link to it does not. List (`TicketListLeakTest`, first and cursor pages) and voter `VIEW` (`TicketVoterViewLeakTest`) are done; search comes with P03.T4 and the ticket page with P03.T6.
- [x] A triager sees a team-only ticket that has no team; a manager without a team role does not (`VisibilityLeakCast` viewers in `TicketVoterViewLeakTest`, `TicketListLeakTest` and `TicketCountsLeakTest`).
- [ ] Every query of this phase passes the leak suite; the check for hand-written visibility conditions passes. Counts, voter `VIEW`, list and cursor pages pass, and `HandWrittenVisibilityConditionTest` passes; search (P03.T4) and the ticket page (P03.T6) still have to add their cases.
**Tests:** the leak suite; unit tests for each branch of the predicate.
**Prototype check:** none (backend task).
**Size:** S

### P03.T3 — Tickets list grouped by step 🏁
**Why:** people look for work by where it stands ([FR-TKT-02, FR-TKT-18](../01-requirements/functional-requirements.md#tkt--tickets)).
**Steps:**
1. `TicketQueries::list(ListFilter, ?Cursor)` returning rows grouped by step in the order Waiting, Checking, Doing, To do (Done only with "Show done"); inside a step by priority, then last update.
2. Group header: step name, group tag, count, hint, collapse (state remembered per user in the session).
3. Row: key, title, meta "Bug · Release 2.4 · Turn: you" (first names; "you" for the viewer), priority (bold red for Critical/Blocker), assignee avatar, last update ("now" if moved in pinround in the last minute).
4. Turbo Frame for results; keyset "Show more" per group (50 rows).
5. Heading "All tickets" or "SHOP · Shop", count line "41 tickets · grouped by step".
**Files:** `src/Ticketing/Api/{TicketQueries,ListFilter,Cursor,TicketListGroup,TicketListStep,TicketListRow,ProjectQueries}.php`, `src/Ticketing/Infrastructure/Doctrine/{DbalTicketQueries,DbalProjectQueries}.php` (`list`, `projectByKey`), `src/Workflow/Api/{ListedStepView,StepQueries}.php`, `src/Workflow/Infrastructure/Cache/{CachedStepQueries,WorkflowCache}.php`, `src/Workflow/Infrastructure/Doctrine/DbalStepQueries.php`, `config/packages/cache.yaml`, `tests/Integration/Workflow/{CachedWorkflowQueriesTest,DbalStepQueriesTest}.php`, `tests/Unit/Ticketing/Application/FixedStepQueries.php`, `src/Insight/Api/TurnUserQueries.php`, `src/Insight/Infrastructure/Doctrine/DbalTurnUserQueries.php`, `src/Ticketing/UI/{CollapsedSteps,StepCollapseController,TicketListController,TicketListCursorParameter,TicketListText}.php`, `src/Ticketing/UI/templates/list.html.twig`, `assets/controllers/step-collapse_controller.js`, `assets/app.js`, `assets/controllers.json`, `assets/styles/tickets.css`, `config/services.yaml`, `config/routes/test_fixtures.yaml` (`test_menu_shell`), `tests/Fixtures/Http/MenuPageStandInController.php`, `tests/Unit/Ticketing/Api/{CursorTest,ListFilterTest,TicketListGroupTest}.php`, `tests/Unit/Ticketing/UI/{CollapsedStepsTest,TicketListTextTest,TicketListCursorParameterTest}.php`, `tests/Integration/Ticketing/{DbalTicketQueriesTest,DbalProjectQueriesTest,TicketListQueryPlanTest}.php`, `tests/Integration/Insight/DbalTurnUserQueriesTest.php`, `tests/Integration/Visibility/TicketListLeakTest.php`, `tests/Functional/Ticketing/TicketListTest.php`, `tests/e2e/tickets-list.spec.ts`, `src/Identity/Api/CurrentTimeZone.php`, `src/Identity/Infrastructure/Security/SymfonyCurrentTimeZone.php`, `tests/Unit/Identity/Infrastructure/Security/SymfonyCurrentTimeZoneTest.php`.
**Acceptance criteria:**
- [x] `/tickets` shows one section per step in group order Waiting, Checking, Doing, To do, then Done only with `?done=1` (the "Show done" control is P03.T5), and the group hints are "Someone must answer first", "Being checked", "Being built", "Not started", with none for Done (`TicketListTest`, `DbalTicketQueriesTest`, `DbalStepQueriesTest` for the labels and hints).
- [x] With 200 open tickets the page sends at most 5 queries more than the shell page (`test_menu_shell`) with a warm step cache, for all tickets and for one project: the triager, the project by key, the rows, the turns and the names; the first load after a workflow change reads one more (the steps; 6 for a project page), proven by the warm and cold cases of `TicketListTest`.
- [x] `EXPLAIN` of the list query as `pinround_app` (RLS on) over a 10,000-ticket organization reads `tkt_ticket` through an index and never with a sequential scan: the first page with and without Done, one project's, and a next page of Done (`TicketListQueryPlanTest`); the p95 budget (≤ 200 ms) is measured in P12.T1, not asserted in CI.
- [x] Each step's "Show more" is a link in a Turbo Frame that loads that step's next 50 rows in place and still works as a plain link without script; each step header has a collapse button (`aria-expanded`, `aria-controls`, keyboard) whose state is saved in the session (`StepCollapseController`, CSRF, same voter as the list) and printed collapsed on the next load (`TicketListTest`, `CollapsedStepsTest`).
- [x] Playwright `tickets-list.spec.ts` opens Tickets from the menu and runs the collapse journey on the seeded stack (it skips only on an empty database); it also covers axe, 360 px and a frame-missing response.
**Tests:** integration test for ordering; query-count test; EXPLAIN test; leak suite for the list; Playwright journey "open Tickets, collapse a group".
**Prototype check:** [List view](../04-prototype/screens/tickets-list-and-whose-turn.md#list-view): row layout, meta order, hint texts, collapsed state.
**Size:** M

### P03.T4 — Search 🏁
**Why:** people search by key or by words ([FR-TKT-05](../01-requirements/functional-requirements.md#tkt--tickets), [ADR-0022](../03-adr/0022-search-and-duplicates.md)).
**Steps:**
1. Migration: generated `search tsvector` column (title weight A, description weight B, `simple` + `english` configs as decided in ADR-0022), GIN index; `pg_trgm` extension and trigram index on title.
2. Query: exact key match first ("SHOP-41"), then FTS rank, then trigram similarity ≥ threshold; always inside current filters and the `TicketVisibility`.
3. RLS check: `@@`, `%` and `similarity()` are not leakproof, so the planner may skip the GIN indexes under RLS. Add an `EXPLAIN` test run as `pinround_app`; if the plan scans the whole org, switch to the `SECURITY DEFINER` search function described in [data-model.md](../02-architecture/data-model.md) (explicit `organization_id` filter, then the same visibility predicate).
4. Search box in the toolbar ("Search by key or words in the title"), 300 ms debounce in the `TicketFilters` Live Component.
**Files:** `src/Ticketing/Infrastructure/Doctrine/TicketSearch.php`, migration.
**Acceptance criteria:**
- [ ] "shop-41" finds SHOP-41 first.
- [ ] A team-only ticket never appears for a user outside the team (leak suite, visibility set by hand).
- [ ] The `EXPLAIN` test as `pinround_app` shows the GIN index in use.
- [ ] p95 ≤ 300 ms at the design point dataset of one large org (200k tickets) — measured in P12.T1, not in every CI run.
**Tests:** integration tests for ranking and visibility; EXPLAIN test; perf script for P12.T1.
**Prototype check:** [Toolbar and filters](../04-prototype/screens/tickets-list-and-whose-turn.md#toolbar-and-filters): placeholder text and position.
**Size:** M

### P03.T5 — Project rail, people filter, person picker, Show done
**Why:** narrowing a long list must be quick, also in big teams ([FR-TKT-04, FR-TKT-06, FR-TKT-07](../01-requirements/functional-requirements.md#tkt--tickets), [FR-ORG-08](../01-requirements/functional-requirements.md#org--organization-teams-and-roles)).
**Steps:**
1. Left rail: the team name in capitals, "All projects" + linked projects with open counts, "Manage projects" link. Counts come from a small read model `ins_project_counts` (organization_id, project_id, visibility, open, done) kept by `TicketCreated`/`TicketMoved` handlers, never from `COUNT(*)` on page load; viewers outside a team count only `visibility = 'all'` rows.
2. People chips: Everyone, Me, up to 4 team members; more than 4 people → "Person ▾" chip opening the `PersonPicker` dialog (search, sorted by open tickets, side text "12 open").
3. "Show done" chip.
4. Filters live in the URL (`?project=&person=&done=`), so links and Previous/Next work.
**Files:** `src/Ticketing/UI/Component/TicketFilters.php`, `src/Web/UI/Component/PersonPicker*.php`, `assets/controllers/person-picker_controller.js`.
**Acceptance criteria:**
- [ ] With 5+ team members, chips become "Person ▾"; choosing a person shows their first name on the chip.
- [ ] Counts in the rail respect "Show done".
- [ ] The picker works with keyboard only.
**Tests:** functional filter tests; Playwright picker journey with 2,000 seeded people (server-side search).
**Prototype check:** [Project rail](../04-prototype/screens/tickets-list-and-whose-turn.md#project-rail), [Toolbar and filters](../04-prototype/screens/tickets-list-and-whose-turn.md#toolbar-and-filters), [Person picker](../04-prototype/screens/dialogs-and-popovers.md#person-picker).
**Size:** M

### P03.T6 — Ticket page header and The ask 🏁
**Why:** the first glance must answer "where is this and who acts" ([FR-TKT-08, FR-TKT-09, FR-TKT-13, FR-TKT-14, FR-TKT-17](../01-requirements/functional-requirements.md#tkt--tickets)).
**Steps:**
1. Route `GET /t/{key}`; voter `VIEW`; 404 (not 403) for tickets the user may not see.
2. Header: back link (to where the user came from), key · type · project, tags (team-only tag placeholder), title, `StatusPill` (step name in group colours), "Now:" and "Next:" (from P02.T7), note line ("Reported in pinround" / "Moved in pinround just now." / "Group: Checking"), position "3 of 41" with Previous/Next inside the list the user came from.
3. The ask: description, first 700 characters with "Show the full ask" (summary layer comes in P08).
4. Facts: assignee, priority, release, parent, components, created; empty facts hidden; Jira link when an external ref exists.
5. Rounds section: empty state text "No rounds yet. The developer starts round 1 from Claude Code: it shoots the screens and puts them here."
**Files:** `src/Ticketing/UI/Controller/TicketShowController.php`, `templates/ticket/show.html.twig`.
**Acceptance criteria:**
- [ ] For each of the eight core steps, the status pill, "Now:" and "Next:" texts equal the strings in [Now and Next texts](../04-prototype/screens/ticket-page.md#now-and-next-texts) (one functional test per step).
- [ ] The page uses ≤ 10 queries (query-count test).
- [ ] A user outside the org gets 404 for a valid key.
**Tests:** functional tests per step; query-count test; isolation test.
**Prototype check:** [Header](../04-prototype/screens/ticket-page.md#header), [The ask](../04-prototype/screens/ticket-page.md#the-ask) (without the AI parts), [People, What happened, Details](../04-prototype/screens/ticket-page.md#people-what-happened-details).
**Size:** M

### P03.T7 — Your next step box, verbs and Move to another step 🏁
**Why:** one box tells each person what to do, with the allowed verbs ([FR-TKT-10](../01-requirements/functional-requirements.md#tkt--tickets), [FR-WF-07, FR-WF-10, FR-WF-16, FR-WF-17](../01-requirements/functional-requirements.md#wf--workflow-steps-and-turns)).
**Steps:**
1. `NextStepBox` component: the sentence for the viewer ("Test it." / "Nothing for you now. Lena's turn." / "Take a quick look at this new ticket."), verb buttons from `TransitionPolicy::available` (first filled, rest outline), the rule note.
2. `POST /t/{key}/move/{verb}` with CSRF; `TicketCommands::move` in one transaction (voter, policy check, domain move, turn projection, event, outbox, optimistic lock on `version`).
3. Turbo Stream answer: replace header, box, people, activity; toast naming who acts next ("Sent to code review. Lena's turn now.").
4. Lead-only "Move to another step…" disclosure listing steps by group, current marked; the review-first refusal message.
5. "Needs input" target dialog when moving there (person from the ticket, or a label).
6. Conflict handling: a stale `version` answers "Someone changed this ticket. The page is updated." and re-renders.
**Files:** `src/Ticketing/Application/MoveTicket/*`, `src/Ticketing/UI/Component/NextStepBox.php`.
**Acceptance criteria:**
- [ ] Every catalogue verb works end to end for the right role and is absent for others.
- [ ] A move writes exactly one event row and one outbox row and updates the turn row in the same transaction (rollback test).
- [ ] An admin without a team role gets 403 on `POST /t/{key}/move/{verb}`, `/assign`, `/move-to/{stepId}` and on ticket edits, and sees no verb buttons (decision in the brief: admins view, comment, ask and ping, but do not move or edit).
- [ ] Action p95 ≤ 150 ms is measured in P12.T1; here the move uses ≤ 8 queries (query-count test).
**Tests:** functional tests per verb; rollback test; Playwright journey "Start work → Send to code review → Approve code → Pass it" with four users.
**Prototype check:** [Your next step box](../04-prototype/screens/ticket-page.md#your-next-step-box), [Every move](../04-prototype/screens/ticket-page.md#every-move), toast texts in [Toast](../04-prototype/screens/dialogs-and-popovers.md#toast).
**Size:** L

### P03.T8 — Give it to, People and What happened 🏁 (steps 1–2)
**Why:** assignment is the most common lead action; people and history explain the ticket ([FR-TKT-11, FR-TKT-12, FR-TKT-16](../01-requirements/functional-requirements.md#tkt--tickets)).
**Steps:**
1. "Give it to…" / "Give it to someone else…" opens the `PersonPicker` in assign mode: team members who are not viewers, sorted by how much already waits on them (fewest first, from `ins_ticket_turn` counts), side text "3 waiting".
2. Assigning a Backlog ticket also moves it to To do (FR-WF-16); toast "Given to Omar."
3. People list: assignee, reporter, reviewer (during In progress/Code review), team tester (open tickets), with role, "· their turn" marker (Ping arrives in P04).
4. What happened: the latest 6 events + created, newest first, as sentences ("Lena gave it to Omar", "Omar moved it from In progress to Code review"). The query adds `at >= :ticket_created_at` so PostgreSQL prunes old partitions of `tkt_ticket_event`.
5. 🏁 Hackathon: steps 1–2 only (Give it to and the Backlog → To do move); steps 3–4 after the hackathon.
**Files:** `src/Ticketing/Application/AssignTicket/*`, `src/Ticketing/UI/Component/{People,Activity}.php`.
**Acceptance criteria:**
- [ ] The picker never lists viewers and is sorted by load.
- [ ] Activity sentences use first names and no raw codes.
**Tests:** functional assign tests; unit tests for event → sentence; Playwright picker assign.
**Prototype check:** [Person picker](../04-prototype/screens/dialogs-and-popovers.md#person-picker), [People, What happened, Details](../04-prototype/screens/ticket-page.md#people-what-happened-details).
**Size:** M

### P03.T9 — Whose turn? view and Team workload
**Why:** waiting work must be visible and have an owner ([FR-TKT-03, FR-TKT-18](../01-requirements/functional-requirements.md#tkt--tickets), [FR-ORG-07, FR-ORG-08](../01-requirements/functional-requirements.md#org--organization-teams-and-roles)).
**Steps:**
1. `HomeQueries::whoseTurn(ProjectFilter)` from `ins_ticket_turn` (+ the Ticketing view for titles, the only allowed join; see [Insight module](../02-architecture/modules.md#insight-ins_)), filtered by the `TicketVisibility`; counts and the "oldest" meter count only tickets the viewer may see. Use the whose-turn index from the canonical list in data-model.md.
2. Segmented control List / Whose turn?; groups: "You" first, then oldest wait; sentence "6 tickets wait on Mia · the oldest for 12 days" (+ "· outside the team"); meter (full at 60 days, amber > 10, red > 30); 5 oldest with the verb for that person ("Test it") and wait time; "Show N more".
3. Team page People section: workload bars from the same counts (amber ≥ 8, red ≥ 20), 6 shown then "Show all N", search box.
**Files:** `src/Insight/Application/HomeQueriesService.php`, `src/Insight/UI/templates/whose_turn.html.twig`, Team page partial.
**Acceptance criteria:**
- [ ] Thresholds and texts match FR-TKT-03 and FR-TKT-18.
- [ ] The view uses one grouped query plus one names query.
- [ ] A team-only ticket does not change any count or meter for a viewer outside the team (leak suite).
**Tests:** integration tests for grouping and ordering; query-count test; functional test of the Team people section.
**Prototype check:** [Whose turn view](../04-prototype/screens/tickets-list-and-whose-turn.md#whose-turn-view), [Team: people and workload](../04-prototype/screens/team.md#people-and-workload).
**Size:** M

### P03.T10 — Queue walk-through
**Why:** walking a group one ticket at a time removes "what next?" decisions ([FR-TKT-15](../01-requirements/functional-requirements.md#tkt--tickets)).
**Steps:**
1. A queue is a list of ticket keys plus a label, stored in the session (max 100 keys).
2. Start a queue from a list group header ("Go through them"); Home groups use the same entry point in P07.
3. `QueueBar` on the ticket page: "Code reviews · 2 of 4", Previous, Next ticket / Finish, Stop. Finish returns Home with "All done in Code reviews."
4. Tickets that left the viewer's turn stay in the queue but show the normal box ("Nothing for you now …").
**Files:** `src/Web/UI/Component/QueueBar.php`, `src/Ticketing/UI/Controller/QueueController.php`.
**Acceptance criteria:**
- [ ] Previous is disabled on the first ticket; the last ticket shows "Finish".
- [ ] Stop removes the bar and keeps the user on the ticket.
**Tests:** functional queue tests; Playwright journey through 3 tickets.
**Prototype check:** [Queue bar](../04-prototype/screens/home-work.md#queue-bar).
**Size:** S

## Definition of done
- [ ] All tasks ticked; the four-person journey (create → assign → build → review → test → done) passes in Playwright.
- [ ] Query budgets: list ≤ 5, ticket page ≤ 10; EXPLAIN tests green; the p95 budgets NFR-PERF-02, NFR-PERF-03, NFR-PERF-04 and NFR-PERF-06 are measured in P12.T1.
- [ ] Isolation and voter tests for every ticket action and query; 404 for invisible tickets; the visibility leak suite covers every read path of this phase.
- [ ] Texts match the prototype (Now/Next, notes, toasts, hints).
- [ ] Global [Definition of done](README.md#definition-of-done-for-every-task) met for every task.

## Hand-off notes
- 2026-11-12 (P03.T5 step 1, for P03.T7): `ins_project_counts` is kept by `TurnProjector::refresh`, which every change of a ticket's project, team, visibility or step already calls: it moves the ticket from the count row it was in (`ins_project_count_ticket`) to its new one, done tickets included, so T7's move needs no extra wiring for the counts. Done is the step's group (`grp = 'done'`, as the list's "Show done"), not `closed_at`. Replace the raw UPDATEs in `DbalProjectCountsProjectorTest::aTicketMovedToDoneCountsAsDoneAndBackAsOpenWhenReopened` with the move command. `ins_ticket_turn` could not be the source: it holds no row for a done ticket or a ticket with no team, so the counts read the facts the refresh already has (Ticketing's `TicketTurnQueries`). A ticket with no team is counted nowhere. No nightly recount job yet: the backfill `Version20261112140100` is the only full rebuild, and listing every organization needs FORCE row-level security lifted on `org_organization`, which only the migrator may do (see `AddTicketEventPartitions`).
- 2026-11-12 (P03.T5 step 1): the rail read is `ProjectCountQueries::countsOfTeam`: every viewer counts tickets for everyone; a team-only ticket counts only for people with a role in that ticket's own team (`ins_project_counts.team_id`, kept when its project moves to another team) and admins, so an outside reporter may see fewer in the rail than in the list (the chosen undercount).
- 2026-11-11 (P03.T3): the Playwright collapse criterion is ticked: `make seed` gives SHOP 40 open tickets, and in `make e2e` on that data `tickets-list.spec.ts` ran its collapse step on a real group (it skips only on an empty database).
- P04 adds Ping buttons to the People list and ping clearing to the one action path (FR-WF-17).
- Every later read path must add its case to `tests/Integration/Visibility/` (P03.T2).
- P05 replaces the Rounds empty state; P06 replaces `/tickets/new` with "Report a problem" (keep the simple form for leads as "New ticket" or remove it; decide in P06).
- 2026-11-11 (P03.T1): `Version20261111120000` creates `tkt_ticket` without its `search` tsvector; the task that adds the GIN indexes (`search`, `title gin_trgm_ops`) adds the column too. `tkt_summary` has no entity (P08 fills it) and is in the `schema_filter`. The `ins_ticket_turn` foreign key means Insight tests need a parent ticket row: use `tests/Integration/Ticketing/TicketRows`.
- 2026-11-11 (P03.T1): `AddTicketEventPartitions` is `#[WhenNot('prod')]` on the migrator connection, which prod does not have ([operations.md › Configuration](../02-architecture/operations.md#configuration)). Prod has the `tkt_ticket_event` partitions of `Version20261111140000` and `Version20261111150000`, through 2027-12. **Deadline: before 2027-12**, prod needs partition maintenance as `pinround_migrator` ([operations.md › Scheduled jobs](../02-architecture/operations.md#scheduled-jobs), "Partition maintenance"), or a migration adding the next months; from 2027-12 `/health/ready` fails (`next_ticket_event_partition`), and from 2028-01 every event insert is refused (no `DEFAULT` partition). `aud_event` ends at 2027-12 too.
- 2026-11-11 (P03.T2 steps 1–2): `TicketVisibility::for(OrganizationId, UserId, list<TeamId>, bool $isAdmin, ?UserId $triager)` instead of the planned `for(ActorContext)`: deptrac lets `Ticketing_Api` depend on SharedKernel only. Get a viewer's predicate from `Ticketing\Api\TicketVisibilities::of(OrganizationId, UserId)`, the one builder, implemented by `Ticketing\Application\RoleTicketVisibilities` with `MembershipQueries::rolesOf` and `SettingsQueries::triagerOf` (null when none, cached per request) under the caller's tenant transaction; `TicketVoter::VIEW` uses its `ofActor(ActorContext)`, which reuses the actor's roles, and the list queries of Ticketing and of every other module (Insight's Home and Whose turn, Review, Conversation, AgentApi) use `of()`. The SQL form is `Ticketing\Api\TicketVisibilitySql`, not in Infrastructure, so Insight may filter `ins_ticket_turn` with it (ADR-0013); its parameters are strings that the SQL casts, so callers pass no Doctrine types. The predicate and its SQL also refuse tickets of another organization, even for an admin, besides row-level security. `ins_ticket_turn` already carries `visibility`, `team_id` and the six people columns (P02), and `TicketVisibilitySqlTest` runs the condition on it. The check for hand-written visibility conditions is `tests/Unit/Architecture/HandWrittenVisibilityConditionTest` (as [data-model.md › Ticket visibility predicate](../02-architecture/data-model.md#ticket-visibility-predicate) lists: SQL or DQL comparisons of `visibility` or `alias.visibility`, bare, with a `::type` cast or as `CAST(visibility AS type)`, and `CASE visibility WHEN`; a `'visibility'` or `'alias.visibility'` key in the first argument of a `findBy`, `findOneBy` or `count` method call only, so sort keys and PHP's own `count()` are not flagged; and `'visibility'` or `'alias.visibility'` as the first argument of a Criteria or query-builder `expr()` method such as `eq` or `in`); a new repository that writes the column (`SET visibility = ?`) is reported too and needs an entry, with its reason, in `HandWrittenVisibilityConditions::ALLOWED`. The leak suite (step 3) is still to do; no criterion is ticked.
- 2026-11-11 (P03.T1 step 3): `TicketCommands::create(OrganizationId, UserId $reporter, string $projectId, string $title, string $description, string $type, string $priority, ?UserId $assignee, \DateTimeImmutable $reportedAt): string` instead of the planned `create(CreateTicket): TicketId`: it returns the key as a string (for example `SHOP-12`), as `TicketKey` is Domain and callers may not use it, and it takes `reportedAt` from the caller, as Application has no clock. `TicketCreated` carries `reporterId` and `assigneeId` as ids only, never the title or description (the outbox body is checked in `CreateTicketTest`). Every new ticket gets `Visibility::All` until the report form adds a visibility choice. `TicketOpenTicketCounts` and `TicketStepOccupancy` now count open tickets (`closed_at IS NULL`) of the current organization; `TicketRows::closeOf` and `addKeyedProjectOf` help tests build them. No criterion is ticked.
- 2026-11-11 (P03.T1 review fixes): the container's `TicketCommands` is `Ticketing\Application\UseCaseTicketCommands`, which hands each command to its use case (`create` to `CreateTicket\CreateTicket`); add `move`, `assign` and the others there the same way. `create` refuses an assignee who is not a lead or member of the project's team in the organization with `AssigneeNotInTeam` (a `DomainException`, before a number is taken); a viewer is refused too, as ADR-0013 lets no viewer move a ticket, so the report form should offer only the team's leads and members. The reporter is not checked there: it is the actor, whom the caller's voter checks. `TicketOpenTicketCounts` and `TicketStepOccupancy` now filter by the tenant's `organization_id` and throw with no transaction open or no tenant set.
- 2026-11-11 (P03.T1 step 5): `TicketPermission` in Ticketing's Api. `UI\Voter\TicketVoter` decides `VIEW`, `EDIT`, `MOVE` and `ASSIGN` on a ticket's `TicketVisibilityFacts`; `CREATE` is decided by a second voter, `UI\Voter\TicketCreationVoter`, on the `TeamId`, because `DenyByDefaultVoter` decides one subject class (as `ProjectVoter` does). `CREATE` is granted to a Lead, Member or Viewer of the team only (see the next note). `MOVE` grants a Lead or Member of the team; which verb a Member may use (turn, assignee, tester) is the workflow rule's to check when `move` is built. The unit matrices are `TicketVoterTest` and `TicketCreationVoterTest`; `VoterMatrixTest` also decides `VIEW`, `EDIT`, `MOVE` and `ASSIGN` through the container on two `TicketVisibilityFacts` subjects (a team-only Shop ticket and a team-only ticket with no team, with Noor as Acme Foods' triager in `org_settings`). No criterion is ticked.
- 2026-11-11 (P03.T1 step 5, CREATE): ADR-0013's row "Report a problem (create ticket)" lets every role report; the team ticket form (`/tickets/new`) follows this phase's criteria instead and needs a team role, so `TicketCreationVoter` grants `CREATE` only to a Lead, Member or Viewer of the team and denies a pure Manager, an Admin without a role on the team, and every other user. The any-role report path belongs to the intake ("Report a problem") work, not this voter. Recorded in [ADR-0038](../03-adr/0038-create-ticket-team-form-needs-team-role.md), which supersedes ADR-0013's create ticket row; personas-and-roles.md now has a "New ticket form" row beside "Report a problem". `VoterMatrixTest`'s `CREATE` rows cite the team-role text and that row.
- 2026-11-11 (Api reads for Insight's turn source): Organization's `TeamQueries::leadOf(TeamId): ?UserId` (null only for an unknown team or another organization's, as every team has exactly one lead in `org_team.lead_user_id`) and `TeamQueries::peopleByJob(TeamId)`, which gives `['developer' => …, 'tester' => …, 'other' => …]` lists of `UserId` in id order for the team's lead and members; viewers are left out, as they cannot move tickets, so they never get a review or test turn. Identity's `UserQueries::byIds(list<UserId>): array<string, UserView>` (`UserView`: `id`, `displayName`, `firstName()`) serves T3's assignee names too. It does not limit users to the tenant: `idn_user` is global, ADR-0002 forbids a join with `org_membership`, and Identity may not use Organization's Api, so pass only ids read under the tenant. No criterion is ticked.
- 2026-11-11 (Insight's turn source): the container's `TicketTurnSource` is `Insight\Infrastructure\Doctrine\DbalTicketTurnSource`, and the `when@test` alias to `InMemoryTicketTurnSource` is gone (`DbalTurnProjectorTest` still builds its projector with the in-memory source). Route: Ticketing's new `Api\TicketTurnQueries::factsOf(TicketId): ?TicketTurnSnapshot` (`DbalTicketTurnQueries`) reads the ticket and its step and builds the team's lead and people with Organization's `TeamQueries::leadOf` and `peopleByJob` and the lead's name with Identity's `UserQueries::byIds`, all as SharedKernel values in `TicketTurnSnapshot`; the one new deptrac edge is Ticketing (Application, Infrastructure, UI) → `Identity_Api`, and Insight has no Organization or Identity edge. The developers' open reviews and the testers' tickets in testing are counted from `ins_ticket_turn` in one query, leaving out the ticket's own row. Not filled yet: `currentRelease` is null until releases exist, and `TicketState`'s `isCheckNeeded`, `checkerId` and `askedUserId` stay unset until the quick check and questions are built; whoever builds them adds them to `TicketTurnSnapshot` and the source. A ticket with no team gets no facts, so no turn row (the projector deletes any it had). Tests: `DbalTicketTurnQueriesTest`, `tests/Integration/Insight/DbalTicketTurnSourceTest`. No criterion is ticked.
- 2026-11-11 (P03.T1 step 4): `/tickets/new` is `Ticketing\UI\NewTicketController`. After a create it redirects to the ticket list, `ticketing_tickets?project=KEY`, with the toast "Ticket SHOP-n made.", as the ticket page does not exist yet; **P03.T6 switches the redirect to `/t/{key}`** (and `NewTicketTest`'s expected redirects). A `DomainException` from `TicketCommands::create` other than `AssigneeNotInTeam` (the project vanished or lost its team after the form read it) answers 403, as a forged project does. Tests: `tests/Functional/Ticketing/NewTicketTest`. No criterion is ticked.
- 2026-11-11 (P03.T1 test helpers): `tests/Integration/Ticketing/ShopTickets` creates a committed bug in a project `ShopTeamRows` added, through `TicketCommands` as a person would; `tests/Integration/Tenancy/OwnConnectionTransactions` runs test work in a rolled-back transaction on a connection of its own, so its tenant stays fixed while the work changes `TenantContext`. `TicketTurnQueries::factsOf` and Insight's `DbalTicketTurnSource` refuse to run without an open transaction (they throw before any query rather than read nothing under row-level security), so open one in every caller and test.
- 2026-11-11 (P03.T2 step 3): the leak suite is `tests/Integration/Visibility/` (its README says how to add a path). `VisibilityLeakCast` commits the cast once per class and its `viewers()` provider yields `[viewer, seesTeamOnly, seesNoTeamTicket]`; tasks 5 and 6 and P03.T6 add one class each for list, search and the ticket page. Paths today: `TicketVoterViewLeakTest` (VIEW: outside reporter yes, outsider no, admin yes for both, triager yes for the no-team ticket only, manager without team role no, other org no) and `TicketCountsLeakTest`. **Open leak:** the team page shows each project's open count (`ProjectQueries::projectsOf`, `TicketOpenTicketCounts`) to anyone `TeamVoter` lets view the team, which includes a Manager with no team role, and the count includes team-only tickets they may not see (ADR-0013 lists "manager counts"); it is the `KNOWN_LEAKS` entry in `TicketCountsLeakTest` (marked incomplete) until the display count goes through `TicketVisibilitySql` per viewer; `UnlinkProject`'s guard must keep counting every ticket. `StepOccupancy` counts every open ticket by design, as a guard before a step is removed. The hand-written check's alias and CAST forms and the stored-roles `TicketVisibilities::of` test (team roles and a triager) were already in place. No criterion is ticked: list, search and ticket page are still to do.
- 2026-11-11 (P03.T2, leak fix): the team-page count leak above is closed. `OpenTicketCounts` has two reads: `openTicketsIn` (the guard of `UnlinkProject`, every open ticket, never shown) and `openTicketsVisibleTo(TicketVisibility, ProjectId ...)` (the same one query, filtered with `TicketVisibilitySql`). `ProjectQueries::projectsOf` now takes the viewer's `UserId` and uses the second, and `TeamController` passes the `CurrentActor`. `TicketCountsLeakTest` has no `KNOWN_LEAKS`: lead, admin and outside reporter count SHOP's team-only ticket, the manager, triager and outsider count 0, another organization finds no project. `StepOccupancy` is unchanged.
- 2026-11-11 (P03.T3, capped count): a step's count in the tickets list is capped. `DbalTicketQueries` counts at most `TicketListStep::COUNT_CAP + 1` (1,001) visible tickets per step in its own `LATERAL`, in the row fetch's order, so it reads `tkt_ticket_step_id_priority_rank_updated_at_id_idx` and stops there; the same shape serves the first page and a cursor page, still one query. Counting every visible ticket made PostgreSQL read `tkt_ticket` with a sequential scan once one step (Done, or To do in a small workflow) held a large share of the table. `TicketListStep` gives `ticketCount` (at most 1,000) and `isCountCapped`; **the page shows "1,000+"** when `isCountCapped` is true. `TicketListQueryPlanTest` now seeds 10,000 tickets, 70% in Done and the rest in four core steps, and checks the first page with and without Done, one project's, and a cursor page of Done.
- 2026-11-11 (P03.T3, the page): `/tickets` (`ticketing_tickets`, `TicketListController`) is built; `?project=KEY` (404 for a key the organization has no project with) and `?done=1` are its filters until T5 adds the controls. The "Show more" link carries the cursor unsigned (`TicketListCursorParameter`: `step` and `after=<rank>.<unix seconds>.<ticket id>`, anything else is a 400): it holds only what the viewer saw on the last row, and a forged one only moves where the step's page starts, as the list still reads through the visibility predicate. The updated time (`TicketListText::updatedOf`) is "now" under a minute (and for a time ahead), "N min ago", "N h ago", then the date "2 Nov" in the viewer's saved zone through `Identity\Api\CurrentTimeZone` (UTC when none is saved; old IANA names are accepted), with the year when it is not this year. Rows link nowhere until P03.T6 adds the ticket page. The test route `test_menu_shell` (`MenuPageStandInController`, the shell with an empty main) replaces the `ticketing_tickets` stand-in and is the query-count baseline; `TicketListTest` requests it once before counting, because the first request after `logIn()` skips the user refresh (`idn_user`, `idn_credential`) every later request pays. Turbo Frame, collapse and Playwright journey: see the next line.
- (add dated notes here)
- 2026-11-11 (P03.T3, frames and collapse): `symfony/ux-turbo` is installed (Drive is off, see the last line). "Show more" sits in `<turbo-frame id=step-<id>-after-<hash of the cursor>>`; the cursor page wraps its rows in a frame of the same id, so only they replace the link. Collapse posts to `ticketing_step_collapse` (`/tickets/steps/{stepId}/collapse`, 204) and is kept in the session (`CollapsedSteps`, at most 200 ids), so it is per login session, not per user across devices; the prototype says "for the session", so it matches. The journey makes no tickets and runs on the seeded 40 (the team-page Remove spec links and unlinks DOCS, which has no tickets, because an open ticket blocks unlinking SHOP); "Show more" is not in it (no seeded step has over 50 tickets), so a separate case injects a link in a frame and answers it with a page that has no frame, proving `turbo:frame-missing` does a real load (`window.location.assign`), not a Turbo visit that CSP nonces would break. `TicketListTest` proves both on the server. `main-menu.spec.ts` and the `shell` baselines now show the built Tickets link.
- 2026-11-11 (P03.T3, Drive off): Turbo is installed and Drive is off app-wide (`Turbo.session.drive = false` in `assets/app.js`); frames still work. A later change turns Drive on together with an e2e sweep (ADR-0007 is still the target; `frontend.md` says so).
- 2026-11-11 (P03.T3, steps): the list no longer joins `wf_step`; it reads the steps through Workflow's `StepQueries::listedSteps` (every step of the organization, removed ones too, with group label and hint, so `TicketListGroup` keeps no copy of them) and drives its one rows query by `unnest(...) WITH ORDINALITY` over their ids. `listedSteps` is cached per organization (`WorkflowCache::rememberForOrganization`, key `org.{o}.wf.org.listed-steps`, tag `wf-any-{o}`), and every workflow invalidation, a team's included, clears that tag; the first load after a step change pays one more query. The test env's `cache.workflow` now uses `test.cache.adapter.array_kept_between_requests` (an array adapter that the kernel reset does not empty, as Valkey is not), so `TicketListTest` loads the page once before counting a warm page; each test still starts with an empty pool. Turn users come from Insight's `TurnUserQueries::turnUsersOf`, names from Identity's `UserView::firstName()`.
