# Phase 07 — Home and My reports

Goal: every person opens pinround and sees, in one calm page, what waits on them, why, and in what order. Reporters can follow their tickets like a parcel.

## Index
- [Before you start](#before-you-start) — prototype screens, requirements, ADRs and docs to read first
- [Outcome](#outcome) — what people can do when this phase is done
- [Scope](#scope) — what this phase builds
- [Out of scope](#out-of-scope) — what waits for later phases
- [Requirements covered](#requirements-covered) — FR and NFR IDs
- [Design notes](#design-notes) — modules, tables, queries, routes, security and speed notes
- [Tasks](#tasks) — P07.T1 to P07.T8, one session each
- [Definition of done](#definition-of-done) — phase checklist
- [Hand-off notes](#hand-off-notes) — what phase 08 must know

## Before you start
1. **Open the prototype.** Link: in your `CLAUDE.local.md` (private to its owner). In Claude Code use the Artifact tool with `action: "read"`, `url` = the link and `path: "project/Main.dc.html"`. In the prototype, switch "view as" to Lead, Tester, Developer, Manager and "Lead + Admin" and look at **Home** each time; then open **My reports** as Tester. Use "Big team" to see avatar stacks and workload. "View as" and "Big team" are prototype-only devices: do not build them.
2. **If you cannot open the link**, read the written screens instead:
   - [home-work.md](../04-prototype/screens/home-work.md) — all sections, especially [focus chips](../04-prototype/screens/home-work.md#header-block-and-focus-chips), [needs you now](../04-prototype/screens/home-work.md#needs-you-now), [score](../04-prototype/screens/home-work.md#needs-you-score), [also waiting](../04-prototype/screens/home-work.md#also-waiting-on-you), [queue bar](../04-prototype/screens/home-work.md#queue-bar), [right column](../04-prototype/screens/home-work.md#right-column), [all teams mini](../04-prototype/screens/home-work.md#all-teams-mini-view).
   - [home-manager.md](../04-prototype/screens/home-manager.md), [home-admin.md](../04-prototype/screens/home-admin.md).
   - [my-reports.md](../04-prototype/screens/my-reports.md) — [groups](../04-prototype/screens/my-reports.md#groups), [card](../04-prototype/screens/my-reports.md#card), [interactions](../04-prototype/screens/my-reports.md#interactions).
   - [team.md](../04-prototype/screens/team.md#people-and-workload) and [dialogs-and-popovers.md](../04-prototype/screens/dialogs-and-popovers.md#pings-popover-bell).
3. **Read the requirements:** [HOME](../01-requirements/functional-requirements.md#home--home-page), [MINE](../01-requirements/functional-requirements.md#mine--my-reports), [MGR](../01-requirements/functional-requirements.md#mgr--manager-overview), FR-TKT-15 and FR-TKT-18 in [TKT](../01-requirements/functional-requirements.md#tkt--tickets), FR-ORG-08 in [ORG](../01-requirements/functional-requirements.md#org--organization-teams-and-roles), [PERF](../01-requirements/non-functional-requirements.md#perf--performance) and [USE](../01-requirements/non-functional-requirements.md#use--usability).
4. **Read the decisions and design:** [ADR-0015 turn read model](../03-adr/0015-turn-read-model.md), [ADR-0013 authorization](../03-adr/0013-authorization.md), [ADR-0023 Mercure](../03-adr/0023-realtime-mercure.md), [ADR-0007 server-rendered UI](../03-adr/0007-server-rendered-ui.md); [workflow-engine.md › Needs-you kinds and score](../02-architecture/workflow-engine.md#needs-you-kinds-and-score), [workflow-engine.md › Whose turn](../02-architecture/workflow-engine.md#whose-turn), [data-model.md › Read models](../02-architecture/data-model.md#read-models), [data-model.md › Query patterns](../02-architecture/data-model.md#query-patterns), [performance-and-scale.md › Home](../02-architecture/performance-and-scale.md#home), [modules.md › Insight](../02-architecture/modules.md#insight-ins_), [frontend.md › Route map](../02-architecture/frontend.md#route-map-prototype-screen--route--template), [ux-principles.md](../00-product/ux-principles.md).
5. **Check the previous phase:** the [Definition of done of phase 06](phase-06-intake-and-checks.md#definition-of-done) must be met (check flags and checker routing exist), and phase 04 pings must be stored in `ntf_ping`. If something is missing, tell the user first.

## Outcome
Lena opens pinround at 9:00. The page says "7 things wait on you, Lena. Do the top 5 first." The first card says "Answer Mia's question · Mia is blocked until you answer". She answers, then presses "Start reviews" and walks through three code reviews with Previous / Next. Ben from support opens My reports. One card is in "Needs you": "Lena asks: Which page was it?". He answers inside the card. Raj, the manager, sees each team as one bar in the five groups and spots the team where 38% of open work is waiting.

## Scope
- Needs-you list on Home: top 5 with "Why" line, grouped rest with "Start …" queues.
- Queue walk-through bar on the ticket page.
- Pings for you (top 3 + "Show N more"), release bar (leads), Your team stack, workload bars.
- Focus chips with red counts, Admin card, All teams mini bars for multi-role people.
- Pure manager Home ("Teams at a glance") and pure admin Home ("Your organization").
- My reports page: progress dots, groups Needs you / Stuck / Moving / Done, inline answer, ping, ⋯ menu, mute, nav badge.
- Live refresh of Home and My reports through Mercure refresh signals.

## Out of scope
- AI re-ranking of the top 10 (phase 08, optional, only when the org allows AI).
- Admin pages behind the Admin card links (phase 11). Links may point to placeholder admin tabs.
- Email digest content (phase 04 owns it).

## Requirements covered
FR-HOME-01, FR-HOME-02, FR-HOME-03, FR-HOME-04, FR-HOME-05, FR-HOME-06, FR-HOME-07, FR-HOME-08, FR-HOME-09, FR-HOME-10, FR-HOME-11, FR-MINE-01, FR-MINE-02, FR-MINE-03, FR-MINE-04, FR-MINE-05, FR-MINE-06, FR-MINE-07, FR-MINE-08, FR-MGR-01, FR-MGR-02, FR-MGR-03, FR-MGR-04, FR-TKT-15, FR-TKT-18 (workload thresholds, team list size), FR-ORG-08 (stacks, people list), FR-PNG-05 (pings tray part), FR-PNG-10 (mute from My reports), FR-WF-13.
NFR-PERF-01, NFR-PERF-10, NFR-SCAL-04, NFR-USE-01, NFR-USE-03, NFR-A11Y-01, NFR-A11Y-02, NFR-A11Y-03, NFR-A11Y-04, NFR-SEC-03, NFR-SEC-13.

## Design notes
- **Modules.** Insight owns all queries (`App\Insight\Application\HomeQueriesService` implementing `App\Insight\Api\HomeQueries`: `needsYou`, `whoseTurn`, `workload`, `myReports`, `teamsAtAGlance`). Web owns the page composition (`App\Web\UI\Controller\HomeController`, `templates/home/index.html.twig`). Notification provides `NotificationQueries::bell()` for the pings tray. Organization provides roles for focus chips.
- **Tables.** Read: `ins_ticket_turn`, `ins_team_counts`, `ins_release_progress`, `tkt_ticket` (through the Ticketing view allowed for Insight), `ntf_ping`, `ntf_mute`, `cnv_message` (open question text via Conversation Api). This phase adds no tables. If you must add one, it needs `organization_id`, RLS and an index starting with `organization_id`.
- **Score.** Insight does not hold the `base_score` formula: it calls `Workflow\Api\NeedsYouScore` (built in P02.T6), which is base by kind (answer 100, check 70 (+40 risk), review 30, test 30, assign 25, input 20, decide 15, build 10) + priority (Blocker 50, Critical 40, Major 20, Normal 8, Minor 3) + release (current 30, any 10). `base_score` is stored on `ins_ticket_turn`; only the time part, min(days waiting, 60) × 0.5, is added in SQL (see the Home query in data-model.md). Ties: older first.
- **Kinds order for groups:** Questions for you, New tickets to check, Code reviews, Tests, Need a developer, Your input, On hold, Your building work. A "check" item never also appears as another kind.
- **Routes.** `GET /` (`home`), `GET /my-reports` (`my_reports`), `GET /?focus=work|team|teams|admin` (focus stored per user in session, not in the URL only), queue: `GET /t/{key}?queue={queueId}` where the queue is a short-lived server-side list of keys in the session.
- **Security.** Read models copy `visibility` and `team_id`; every Home, My reports, Whose turn and workload query applies the one TicketVisibility predicate through `Ticketing\Api\TicketVisibility` (P03.T2; definition in [data-model.md](../02-architecture/data-model.md) and [ADR-0013](../03-adr/0013-authorization.md)). **Counts too:** `ins_team_counts` has a `visibility` column; viewers outside a team (including managers without a team role) count only `visibility = 'all'` rows, so a restricted ticket never changes a number they see. Draft rounds and private explanations never feed Home.
- **Speed.** Home ≤ 250 ms p95 and ≤ 8 SQL queries; titles loaded in one batch by id list; no lazy loading in Twig; page HTML cached per user for 0 s (no shared cache) — speed comes from indexes, not caching.

## Tasks

### P07.T1 — Needs-you query and score
- **Why:** the top 5 is the heart of pinround. It must be right and fast.
- **Steps:**
  1. Use `Workflow\Api\NeedsYouScore` and `NeedsYouKind` (built in P02.T6) for `base_score` and the kind; write only the SQL time part here.
  2. Make sure `TurnProjector` (phase 02) fills `kind`, `base_score`, `waiting_since`, `check_user_id`; add missing cases (check +40 for `risk`).
  3. Implement `HomeQueries::needsYou(UserId, OrganizationId): NeedsList` as three small queries, all with the visibility predicate:
     - **Top items:** `UNION ALL` of two index scans, one on `(organization_id, turn_user_id, base_score DESC)` and one on `(organization_id, check_user_id, base_score DESC)`, each ordered by `base_score`. The time part of the score adds at most 30 points (60 days × 0.5), so fetch only rows with `base_score ≥ (5th best base_score − 30)`, then compute the full score in SQL and keep the top 5. No `OR` across the two columns and no `ORDER BY` on a computed value over all rows.
     - **Group counts:** a separate `GROUP BY kind` count over the same two index paths, so "Also waiting on you" counts are exact (no `LIMIT 200` cut).
     - **Group rows:** loaded only when a group is opened (Turbo Frame), keyset paged by `(base_score DESC, waiting_since, ticket_id)`.
     Then batch-load titles, open question text and "Why" parts.
  4. Build the "Why" line: who is blocked, priority (Blocker/Critical/Major only), release ("in release vX" or "release vY"), wait ("no change for N days" when > 7, "changed today", else "updated <date>").
  5. Build the task sentence per kind ("Answer Mia's question", "Check the new ticket from Ben", "Review Omar's code", "Test it", "Pick a developer", "Give input", "Decide: go on or stop", "Start building"/"Build it").
- **Files:** `src/Insight/Application/HomeQueriesService.php`, `src/Insight/Infrastructure/Doctrine/NeedsYouQuery.php`.
- **Acceptance:**
  - [ ] One unit test per row of the kind table and per priority/release weight (`test_FR_HOME_03_*`).
  - [ ] Days capped at 60; ties break older first.
  - [ ] A ticket the viewer must check shows only as `check`.
  - [ ] Restricted tickets of other teams never appear in items or counts (cases added to the visibility leak suite).
  - [ ] With 5,000 turn rows for one lead, `EXPLAIN` (as `pinround_app`) shows index scans only and the counts equal a brute-force count.
- **Tests:** unit (score), integration (query with Foundry data, two orgs → isolation), EXPLAIN test, query count ≤ 3 for this query service.
- **Prototype check:** Home as Lead: order and "Why" wording match [needs you now](../04-prototype/screens/home-work.md#needs-you-now).
- **Size:** M

### P07.T2 — Home page for work roles
- **Why:** turn the list into the calm page people see first.
- **Steps:**
  1. `HomeController` composes: headline, date line (user locale), subline + role line, Needs you now (rank 1 highlighted, "Open ticket" / "Check it"), "AI ORDER" label with its explanation, empty state "Nothing waits on you right now."
  2. "Also waiting on you": groups in the fixed order, count, hint, expand (Twig `<details>` or Stimulus), rows with key/title/since.
  3. "Pings for you": top 3 ping groups from `NotificationQueries::bell()`, "Done" and "Open ticket", "Show N more" opens the bell popover.
  4. "Your team": avatar stack (4 + "+N"), "N people · see who has what" → Team page.
  5. Release bar for leads: `ins_release_progress` for the current release, five-group bar and list with step and turn.
- **Files:** `src/Web/UI/Controller/HomeController.php`, `templates/home/index.html.twig`, Twig components `NeedItem`, `GroupedList`, `PingCard`, `GroupBar`, `AvatarStack` (create if phase 00 did not).
- **Acceptance:**
  - [ ] Headline text exactly as the prototype ("N thing waits on you" singular).
  - [ ] With more than 5 items the subline says "Do the top 5 first…".
  - [ ] Only one filled primary button per card.
  - [ ] Works at 360 px; keyboard reaches every card and group.
- **Tests:** functional (`WebTestCase`) per role; query count Home ≤ 8; axe check in the Home e2e.
- **Prototype check:** compare Home as Lead, Tester, Developer side by side with the prototype.
- **Size:** L

### P07.T3 — Queue walk-through ("Start reviews")
- **Why:** doing one kind of work in a row is faster and calmer.
- **Steps:**
  1. "Start …" on a group stores a queue (id, label, ticket keys) in the session and opens the first ticket.
  2. Ticket page shows the queue bar: label, "2 of 4", Previous, Next ticket / Finish, Stop.
  3. Finish returns Home with toast "All done in <label>."; Stop removes the bar.
  4. Tickets that left the viewer's turn during the walk stay in the queue but show their new Now line.
- **Files:** `src/Insight/Application/Queue/*`, `templates/ticket/_queue_bar.html.twig`.
- **Acceptance:**
  - [ ] Previous disabled on the first ticket; last ticket shows "Finish".
  - [ ] The queue cannot include tickets the viewer cannot see (voter check on each step).
- **Tests:** functional walk through 3 tickets; e2e "Start reviews" journey.
- **Prototype check:** [queue bar](../04-prototype/screens/home-work.md#queue-bar).
- **Size:** S

### P07.T4 — Focus chips, Admin card, All teams mini
- **Why:** one Home for people with several roles, no mode switch.
- **Steps:**
  1. Build chips from roles: All, My work, My team, All teams (Manager), Admin (Admin). Pressed state stored per user (session).
  2. Hide/show parts per the table in [focus chips](../04-prototype/screens/home-work.md#header-block-and-focus-chips).
  3. Red count on a chip when its part is hidden and has items (My work = needs count; Admin = alert count).
  4. Admin card: alerts only (invites not accepted, company login not set up, AI on laptops needs setup, Jira sync paused) from an `AdminAlerts` query (Organization + Integration + Assist Api), each linking to its admin tab; "Nothing needs you as admin."; "Open settings".
  5. All teams mini: one thin five-group bar per team with the waiting flag, from `teamsAtAGlance`.
- **Files:** `src/Web/UI/Component/FocusChips.php`, `templates/home/_admin_card.html.twig`, `templates/home/_teams_mini.html.twig`, `src/Organization/Api/AdminAlerts.php`.
- **Acceptance:**
  - [ ] A Lead + Admin sees Admin chip and card; a pure Lead does not.
  - [ ] Chips are a `role="group"` with `aria-pressed`; red count has a text label for screen readers.
- **Tests:** functional per role combination (lead, lead+admin, lead+manager, lead+manager+admin); voter-style table test for chip visibility.
- **Prototype check:** Home as "Lead + Admin"; then give Lena the Manager role in prototype Admin › People and compare.
- **Size:** M

### P07.T5 — Manager and admin Homes
- **Why:** pure managers and pure admins need their own first page.
- **Steps:**
  1. Manager: "Teams at a glance", legend of the five groups, one card per team with stacked bar and counts, flag "N% of open work is waiting" (Waiting ÷ (all − Done) > 25%) else "Flowing", expand "Show this team's own steps".
  2. Keep `ins_team_counts` (organization_id, team_id, group, visibility, count) correct: event handlers + nightly recount job (already planned in operations.md; add it if missing). Viewers outside a team read only `visibility = 'all'` rows.
  3. Admin: "Your organization" stats (People, Teams, AI help, Jira) linking to admin tabs, "Open admin settings".
- **Files:** `templates/home/manager.html.twig`, `templates/home/admin.html.twig`, `src/Insight/Infrastructure/Messenger/TeamCountsProjector.php`.
- **Acceptance:**
  - [ ] Flag math has unit tests at 25% and just above.
  - [ ] Bars use the fixed five group colors and show numbers (color is never the only signal).
  - [ ] Adding a team-only ticket does not change Raj's (manager without team role) numbers; it does change a team member's.
- **Tests:** integration for counts after moves; functional for both Homes.
- **Prototype check:** [home-manager.md](../04-prototype/screens/home-manager.md), [home-admin.md](../04-prototype/screens/home-admin.md).
- **Size:** M

### P07.T6 — My reports page
- **Why:** reporters must see where their report is without opening it.
- **Steps:**
  1. `HomeQueries::myReports(TicketViewer, ?MyReportsCursor)`: tickets with `reporter_id = me`, newest first, keyset paged by `(created_at DESC, id DESC)` with 50 per page ("Show more"); join turn row; one query + one batch for open questions. The reporter keeps access to restricted tickets they reported (the visibility predicate includes `reporter_id`).
  2. Groups: Needs you (open question to me, all shown), Stuck (no change > 7 days, 4 + "Show N more"), Moving (4 + more), Done (folded "Show N done").
  3. Card: key, title, progress dots Reported → Building → Checking → Done (Waiting group shows the current dot amber labelled "Waiting"; Waiting sits at the Building dot), avatar of whose turn, Now line (or "Lena asks: "…""), side text ("no change for N days" / "changed recently" (≤ 2 days) / "updated 5 Nov"), pin progress "1 of 2 pins OK", tags MUTED.
  4. Intro line "N tickets you reported. K need your answer." / "None needs you right now." / empty state with "Report a problem".
  5. Nav badge "My reports (N)" = questions waiting on me in my reports.
- **Files:** `src/Web/UI/Controller/MyReportsController.php`, `src/Web/UI/templates/my_reports/show.html.twig`, Twig component `src/Web/UI/Component/ProgressDots.php` (built in P14.T15 in Web, which owns the page; the query is `src/Insight/Infrastructure/MyReports/DbalMyReportsRows.php`).
- **Acceptance:**
  - [x] Progress dots have an accessible text ("Step 2 of 4: Building"). (Built in P14.T15. `tests/Unit/Web/UI/Component/ProgressDotsTest::theTextAlternativeSaysTheStepInWords`: "Step 2 of 4: Building", step 1 and step 4; `tests/Functional/Web/MyReportsTest::eachCardShowsItsKeyTitleProgressTurnPersonAndNowLine`: each card's dots have `role="img"` and that text as `aria-label`, with ", waiting" for a waiting ticket. Both passed in main on 2026-11-21)
  - [x] Max two visible buttons per card. (Built in P14.T15. `MyReportsTest::onlyAReportThatNeedsTheViewerOffersAnswerAndNoCardHasMoreThanTwoButtons`: only the report whose question waits on Mia offers Answer, and no card has more than two buttons. Passed in main on 2026-11-21)
  - [x] A team-only ticket the viewer reported is listed (reporter access), with no other team-only tickets. (Built in P14.T15. `tests/Integration/Insight/Infrastructure/MyReports/DbalMyReportsRowsTest::itListsOnlyTheViewersOwnReportsTeamOnlyOnesIncludedNewestFirstThenTheHighestId`: Lena's team-only report of a team her viewer is not in is listed, Omar's team-only one is not; `::anotherReporterGetsOnlyTheirOwnReportsNotTheOnesTheyMaySee`; `tests/Functional/Web/MyReportsTest::eachGroupShowsItsReportsNewestFirstAndNoOneElsesReports`: Mia's team-only APP-1 of Mobile, a team she is not in, shows, Omar's team-only APP-2 does not)
  - [x] A reporter with 300 reports gets the first 50 and "Show more". (Built in P14.T15. `DbalMyReportsRowsTest::threeHundredReportsComeFiftyAPageThroughTheCursorWithNoGapAndNoRepeat`: 300 reports give the newest 50 and a cursor to the next 50; `MyReportsTest::showMoreOpensTheNextFiftyByTheKeysetWithNoGapOrRepeat`: the page shows the first 50 and a "Show more" link that opens the rest, proved with 60 reports)
- **Tests:** unit for grouping rules; integration for the query (isolation); functional page per group; query count ≤ 5.
- **Prototype check:** My reports as Tester — groups, card layout, texts.
- **Size:** L

### P07.T7 — My reports actions: answer, ping, menu, mute
- **Why:** act without opening the ticket.
- **Steps:**
  1. "Answer" opens an inline text box (Live Component or Turbo Frame); "Send answer" calls the Conversation answer command (resumes the ticket like the ticket page) and refreshes the card.
  2. "Ping <name>" (tonal in Stuck, text button in Moving) opens the ping sheet with the My reports draft text; hidden for label waiters (Customer, Product owner, Technical owner), integration users and when already pinged within the limit ("Pinged · again in N h").
  3. ⋯ menu: Open ticket, Add more info (ticket page with composer focused), Mute updates / Turn updates on (`ntf_mute`), Copy link; on Done cards for tester or lead: "Not fixed? Reopen" (runs the Reopen verb through Workflow).
  4. Every action uses the one action path (FR-WF-17): clears own pings on that ticket, one activity line, toast.
- **Files:** `src/Insight/UI/Component/ReportCard.php`, Stimulus `menu_controller.js`, `clipboard_controller.js`.
- **Acceptance:**
  - [ ] Answer from My reports and from the ticket page produce the same events (`QuestionAnswered`).
  - [ ] Menu is keyboard operable (Escape closes, focus returns).
  - [ ] Reopen is refused by the voter for non-tester/non-lead even with a crafted request.
- **Tests:** functional per action; voter test for Reopen; e2e "answer from My reports".
- **Prototype check:** [interactions](../04-prototype/screens/my-reports.md#interactions).
- **Size:** M

### P07.T8 — Workload, live refresh and the Home e2e suite
- **Why:** finish big-team behaviour and keep Home fresh without reloads.
- **Steps:**
  1. `HomeQueries::workload(TeamId)`: open tickets whose turn is each person, counted with the visibility predicate (viewers outside the team count only `visibility = 'all'`); bars amber ≥ 8, red ≥ 20; Team page shows 6 then "Show all N" with search (if phase 02 built the Team page without this, add it).
  2. Subscribe Home and My reports to the viewer's per-user Mercure topic; on `TicketMoved`, `QuestionAsked/Answered`, `PingSent/Cleared`, `TicketChecked` push a refresh signal (no content) and re-fetch the Turbo Frame.
  3. Write the e2e journeys: Lead Home → answer top item → item disappears; Start reviews → Finish; Tester My reports → answer.
  4. Add a k6 smoke script for Home (50 req/s, 1 min) to CI.
- **Files:** `src/Insight/Application/WorkloadQuery.php`, `assets/controllers/live_refresh_controller.js`, `tests/e2e/home.spec.ts`, `tests/load/home-smoke.js`.
- **Acceptance:**
  - [ ] Home updates within 5 s after another user's action, without a full reload.
  - [ ] Mercure messages carry no ticket text.
- **Tests:** e2e above; functional test that the published update has no content.
- **Prototype check:** Team page "People" with Big team on; Home after an action.
- **Size:** M

## Definition of done
- [ ] All P07 tasks ticked; prototype Home (all roles), My reports, manager and admin Homes match, or differences are written below.
- [ ] Home p95 ≤ 250 ms on the seeded large-org dataset (local measure), Home ≤ 8 queries, My reports ≤ 5.
- [ ] Tenant isolation and restricted-visibility tests cover every new query.
- [ ] Quality gates green: `composer test`, `composer phpstan`, `composer deptrac`, `composer cs`, `composer audit`.
- [ ] Docs updated if behaviour changed (and their Index).

## Hand-off notes
- Phase 08 may add AI re-ranking inside the top 10 only, behind the org AI policy. Keep `Workflow\Api\NeedsYouScore` as the default.
- Write here any prototype differences you chose, with the reason.
- 2026-11-20 (plan change): P07.T6 (My reports page) is built as [P14.T15](phase-14-tester-feedback.md#p14t15--my-reports-page) by the product owner's decision; tick its criteria here with a note when it is done. P07.T1–T5 stay here; until they land, Home shows the plain "your turn" list of P14.T14.
- 2026-11-21 (P07.T6, built in P14.T15): the reporter-access and "300 reports, first 50 and Show more" criteria are ticked on `DbalMyReportsRowsTest` and `MyReportsTest`. The page shows Needs you, Stuck, Moving and Done with 50 reports a page; the progress dots, the card buttons, the intro line and the nav badge are not built yet, so the dots and two-buttons criteria stay open.
- 2026-11-21 (P07.T6, cards built in P14.T15): the progress-dots and two-buttons criteria are ticked on `ProgressDotsTest` and `MyReportsTest`, so all four P07.T6 criteria are done. The page lives in Web (`MyReportsController`, `my_reports/show.html.twig`), not Insight UI; the Files line says so. The question text with its asker ("Lena asks: "…"") and the pin progress ("1 of 2 pins OK") are owed by P14 task 15f; Answer links to the ticket until P07.T7 adds the actions.
- 2026-11-21 (P07.T6, 15f in P14.T15): cards quote the open question to the viewer through Ticketing's port OpenQuestionQueries (Conversation's DbalOpenQuestionQueries) and show "k of n pins OK" through PinProgressQueries (Review's DbalPinProgressQueries); one query each per page; MyReportsTest::myReportsRunsTheSameQueriesForTwoReportsAndForSixty covers both. The avatar beside a quoted question is the asker's (prototype), where P07.T6 step 3 says whose turn.
- 2026-11-22 (from the P14 UI check, medium): the manager's Teams at a glance still shows placeholder counts (FR-MGR-01); P07.T5 must fill them from the real groups.
