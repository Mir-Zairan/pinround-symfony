# Phase 06 — Intake and checks

Goal: anyone can report a problem with a screenshot and pins, and it becomes a ticket at once. pinround spots likely duplicates while you type, explains exactly what will happen, and asks a lead to take a quick look only when something is unclear.

## Index
- [Before you start](#before-you-start) — prototype screens, requirements, ADRs, docs
- [Outcome](#outcome) — what works after this phase
- [Scope](#scope) — what this phase builds
- [Out of scope](#out-of-scope) — what waits
- [Requirements covered](#requirements-covered) — FR and NFR ids
- [Design notes](#design-notes) — flows, word lists, visibility, routing
- [Tasks](#tasks) — P06.T1 to P06.T7
- [Definition of done](#definition-of-done) — phase checklist
- [Hand-off notes](#hand-off-notes) — notes for P07 and P08

## Before you start
1. **Prototype.** Open https://claude.ai/artifact/FmbJosB3s5mDi75cLqMFQ2 (Artifact tool, `action: "read"`, `path: "project/Main.dc.html"`). As the tester, open "Report a problem": type a title like an existing one, see "Maybe already reported", try "Add my report there", pick "Not sure" as team, watch the "When you send:" line change, create the ticket. As the lead, open the example new ticket and use the check box (Looks fine, Same problem · close this one). As admin, open "New tickets". Fallback docs: [Report a problem](../04-prototype/screens/report-a-problem.md) (all sections: [fields and copy](../04-prototype/screens/report-a-problem.md#fields-and-copy), [duplicate suggestions](../04-prototype/screens/report-a-problem.md#duplicate-suggestions), [where it goes](../04-prototype/screens/report-a-problem.md#where-it-goes), [check reasons and the preview sentence](../04-prototype/screens/report-a-problem.md#check-reasons-and-the-preview-sentence), [create ticket](../04-prototype/screens/report-a-problem.md#create-ticket)), [Ticket page: new ticket check box](../04-prototype/screens/ticket-page.md#new-ticket-check-box), [Admin: new tickets](../04-prototype/screens/admin.md#new-tickets), [Tickets: toolbar and filters](../04-prototype/screens/tickets-list-and-whose-turn.md#toolbar-and-filters).
2. **Requirements:** [FR-INT-01, FR-INT-02, FR-INT-03, FR-INT-04, FR-INT-05, FR-INT-06, FR-INT-07, FR-INT-08, FR-INT-09, FR-INT-10, FR-INT-11, FR-INT-12, FR-INT-13, FR-INT-14, FR-INT-15](../01-requirements/functional-requirements.md#int--intake-and-checks) (FR-INT-15 duplicate thresholds), [FR-TKT-07](../01-requirements/functional-requirements.md#tkt--tickets) (to-check chip), [FR-ORG-09](../01-requirements/functional-requirements.md#org--organization-teams-and-roles) (triager), [FR-ADM-07](../01-requirements/functional-requirements.md#adm--admin) (New tickets settings, minimal page), [NFR-SEC-13](../01-requirements/non-functional-requirements.md#sec--security) (team-only tickets leak nowhere), [NFR-USE-02](../01-requirements/non-functional-requirements.md#use--usability) (report in under 2 minutes).
3. **ADRs:** [0016 direct ticket creation with check flags](../03-adr/0016-direct-ticket-creation-with-check-flags.md), [0022 search and duplicates](../03-adr/0022-search-and-duplicates.md), [0013 authorization](../03-adr/0013-authorization.md), [0011 screenshots](../03-adr/0011-screenshot-storage-and-processing.md), [0015 turn read model](../03-adr/0015-turn-read-model.md).
4. **Architecture docs:** [new tickets and check flags](../02-architecture/workflow-engine.md#new-tickets-and-check-flags), [whose turn](../02-architecture/workflow-engine.md#whose-turn) (rule 2: checker), [Ticketing module](../02-architecture/modules.md#ticketing-tkt_), [Live Components](../02-architecture/frontend.md#live-components) (`ReportForm`), [search hot path](../02-architecture/performance-and-scale.md#search), [special visibility rules](../00-product/personas-and-roles.md#special-visibility-rules), [tenant isolation](../02-architecture/security.md#tenant-isolation).
5. **Previous phase:** check the Definitions of done of [P04](phase-04-conversation-and-pings.md#definition-of-done) (questions for "Ask for more") and [P05](phase-05-screenshots-pins-and-rounds.md#definition-of-done) (screenshots, pins, Round 0).

## Outcome
Ben from support hears customers see a doubled total. He opens "Report a problem", pastes a screenshot, drops a pin, and types the title. pinround shows "Maybe already reported (found by matching words)" with SHOP-41; Ben thinks his is different and goes on. He picks "Not sure" as team. The last line says: "It becomes ticket SHOP-88 right away. Sara takes a quick look, because maybe the same as an older ticket, no team was picked." He presses "Create ticket" and lands on SHOP-88. Sara, the triager, sees it on her to-check list, moves it to the Shop team, and presses "Looks fine". A report that mentions "password" is created team-only and never shows up for people outside the team, in any list, search, email or API.

## Scope
- "Report a problem" page (replaces the P03 simple form for most people) with fields, screenshot and pins, Round 0 creation.
- Live duplicate suggestions and "Add my report there".
- Check reasons (`dupe`, `info`, `team`, `risk`, `rule`), risk and security word lists, team-only visibility everywhere.
- "When you send:" preview sentence and creation toasts.
- Checker routing (lead or triager), the to-check filter, the check kind in the turn read model.
- The check box on the ticket with its actions; the lead-check rule with pending assignee; a minimal Admin "New tickets" page.

## Out of scope
- AI intake suggestions (✦ type/priority, AI duplicates, AI "missing" and "risky") — P08 plugs into the hooks built here.
- Home "New tickets to check" group (P07 reads the same data). The full admin area layout (P11).

## Requirements covered
FR-INT-01, FR-INT-02, FR-INT-03, FR-INT-04, FR-INT-05, FR-INT-06, FR-INT-07, FR-INT-08, FR-INT-09, FR-INT-10, FR-INT-11, FR-INT-12, FR-INT-13, FR-INT-14, FR-INT-15, FR-RND-16, FR-MCP-12, FR-TKT-07 (to-check chip), FR-ORG-09, FR-ADM-07 (minimal), FR-WF-08 (rule 2), NFR-SEC-13, NFR-USE-02.

## Design notes
- **One create command** `TicketCommands::create` gains: team (or none), pending assignee, check reasons, visibility, duplicate links, Round 0 screenshots/pins (via `Review\Api\RoundCommands::createReport` in the same transaction).
- **Check reasons** are computed by one pure service `CheckReasons::for(DraftReport, Context)`; the same service powers the live preview and the final create, so the preview never lies.
- **Word lists** are versioned PHP constants in `Ticketing\Domain\Intake\WordLists` (risk list and security list as in [FR-INT-08, FR-INT-09](../01-requirements/functional-requirements.md#int--intake-and-checks)); matching is case-insensitive on word boundaries. Only the security list sets visibility "team".
- **Duplicates:** take the top 20 candidates by FTS rank and trigram similarity (open tickets, inside the reporter's `TicketVisibility`), then apply the FR-INT-15 word rule (title ≥ 8 characters; ≥ 3 shared non-stop words and ≥ 30 % of the old title's words), then keep the top 3. Applying `LIMIT 3` before the word rule could return nothing. Stored in `tkt_duplicate_link` with `decided` = `same`/`different`/NULL.
- **Checker:** lead of the ticket's team, else `org_settings.triager_id`. `ins_ticket_turn.check_user_id` is set while unchecked; turn rule 2 applies when there is no assignee.
- **Visibility "team":** always through the one TicketVisibility predicate (`Ticketing\Api\TicketVisibility`, P03.T2; definition in [data-model.md](../02-architecture/data-model.md) and [ADR-0013](../03-adr/0013-authorization.md)). It keeps access for the people on the ticket (reporter, assignee, pending assignee, reviewer, tester, waiting-on user), the team, admins, and the triager while there is no team. Used in `TicketVoter`, every Ticketing query, Insight rows (`visibility` column), search, duplicates, notifications (re-check at delivery), email (no title), MCP/API (P09) and exports (P11).
- **Performance:** the live form re-renders at most every 400 ms; the duplicate query must stay ≤ 50 ms p95 (one indexed query).

## Tasks

### P06.T1 — Report a problem page and Round 0
**Why:** reporting must take under two minutes and never need approval ([FR-INT-01, FR-INT-02, FR-INT-05, FR-INT-06](../01-requirements/functional-requirements.md#int--intake-and-checks)).
**Steps:**
1. Route `GET/POST /report` (`report_new`) for everyone in the org; header button "Report a problem" points here.
2. `ReportForm` Live Component: "What is wrong, in one line", "More detail · optional", Screenshot ("Capture with pinround" disabled until P10 with a tooltip, "Upload a file", paste), pins with notes, and per screenshot an optional "Title" (≤ 120 characters) and "What this picture shows" summary (≤ 600 characters; [FR-RND-16](../01-requirements/functional-requirements.md#rnd--screenshots-pins-and-rounds)), "Where it goes": Team (teams + "Not sure"), Give it to (only for a chosen team: "The team (Lena decides)" + up to 4 members sorted by load), Project, Type (default Bug), Priority (default Normal).
3. Create: ticket + Round 0 in one transaction, as the 2026-11-15 hand-off note says (Ticketing never calls Review's Api; P05.T5 settles how); start in To do with an assignee and no lead-check rule, else Backlog; land on the ticket.
4. Keep `/tickets/new` for leads only, or remove it (decide and note it in hand-off).
5. Report drafts made by Claude Code ([FR-MCP-12](../01-requirements/functional-requirements.md#mcp--mcp-server-and-agent-api), [ADR-0039](../03-adr/0039-agents-file-report-drafts-a-person-sends-them.md); the tool itself is P09.T9): Review owns the list, the page and Send, all in Review's UI (`src/Review/UI`), not in Ticketing's `ReportForm`. The Report a problem page only links to Review's "Report drafts" list, which is visible to the owner only and backed by `Review\Api\ReportDrafts` (`forOwner`, `send`; Review owns `rvw_report_draft`, [ADR-0039](../03-adr/0039-agents-file-report-drafts-a-person-sends-them.md), [ADR-0040](../03-adr/0040-report-drafts-live-in-review.md)). A Review page opens one draft to edit it (title, detail, at most 10 pictures with title, summary and pins; more is refused with a plain message) and **Send**. Send turns the draft's project key into an id with `Ticketing\Api\ProjectQueries::projectByKey`, then creates the ticket through `Ticketing\Api\TicketCommands::create` with that project id and the draft's title, detail, type, priority, assignee and reported-at time, then Round 0 through Review's own `RoundCommands::createReport`, and deletes the draft in that transaction; Ticketing never calls Review's Api. A draft of another user is "not found". Decide the retention of unsent drafts and note it in hand-off.
**Files:** `src/Ticketing/UI/Component/ReportForm.php`, `src/Ticketing/Application/CreateTicket/*` (extended), `src/Review/UI/ReportDraftController.php` and `src/Review/UI/Component/ReportDraftForm.php` (the drafts list, the edit page, Send), templates (the Report a problem page links to Review's drafts list).
**Acceptance criteria:**
- [x] A report with a screenshot and 2 pins creates the ticket and "Round 0 · Report · by Ben · 2 pins". (`ReportControllerTest::aReportWithAScreenshotAndTwoPinsBecomesATicketWithRoundZeroAndNeedsNoCheck`, as Mia: "Round 0 · Report · by Mia · 2 pins".)
- [x] "Give it to" appears only after a team is chosen. (Only for a team of the reporter's own, as report-a-problem.md says; never for "Not sure": `ReportControllerTest::theFormShowsTheReportersTeamItsPeopleWithoutTestersAndItsProjects`, `aManagerInNoTeamReportsWithNotSureByDefault`; the server refuses a person for Not sure or one not offered, `aRefusedReportShowsWhyKeepsTheTextAndMakesNoTicket`.)
- [ ] The whole form works with keyboard only.
- [x] A summary over 600 characters or a title over 120 is refused with a plain message and the form keeps its text. (`ReportControllerTest::aRefusedReportShowsWhyKeepsTheTextAndMakesNoTicket`, case "a picture summary of 601 characters"; the title cap is NewTicketReport's, shared with the team form and tested in `NewTicketScreenshotTest`.)
- [x] A report draft is visible only to its owner; Send creates the ticket with Round 0 and the draft is gone; nobody else can open or send it. (`ReportDraftControllerTest`: `sendMakesTheTicketWithRoundZeroAndTheDraftIsGone`, `theListShowsOnlyTheOwnersDraftsAndNobodyElseOpensOne`, `nobodyButTheOwnerCanSendDeleteOrSaveAndNothingChanges`.)
**Tests:** functional create (with and without screenshot, with title and summary, report draft open and send, other user's draft → not found), Playwright journey with a stopwatch check (< 2 minutes scripted), axe.
**Prototype check:** [fields and copy](../04-prototype/screens/report-a-problem.md#fields-and-copy), [where it goes](../04-prototype/screens/report-a-problem.md#where-it-goes): labels, placeholders, order, defaults.
**Size:** L

### P06.T2 — Duplicate suggestions and "Add my report there"
**Why:** the cheapest duplicate is the one never created ([FR-INT-03, FR-INT-04, FR-INT-15](../01-requirements/functional-requirements.md#int--intake-and-checks)).
**Steps:**
1. `TicketQueries::duplicateCandidates(OrganizationId, text, exclude)`: top 20 by FTS rank and trigram similarity inside the reporter's `TicketVisibility`, then the FR-INT-15 word rule, then top 3 (see design notes).
2. In `ReportForm`: after 8+ characters, show up to 3 candidates with key, title and Now line; label "Maybe already reported (found by matching words)"; amber box as in the prototype; update ≤ 500 ms after typing stops.
3. "Add my report there": adds a comment "I see this too: <title> – <detail> (N pins on a screenshot)" with the screenshot and pins attached to the existing ticket, opens it, toast "Added to SHOP-41. No new ticket was made."; records `tkt_duplicate_link` decided `same`.
4. Creating anyway records the candidates with `decided` NULL and sets reason `dupe`.
**Files:** `src/Ticketing/Infrastructure/Doctrine/DuplicateFinder.php`, form partial.
**Acceptance criteria:**
- [ ] A team-only ticket is never suggested to someone outside the team.
- [ ] Titles under 8 characters show no suggestions.
- [ ] The query uses the trigram/FTS indexes in an `EXPLAIN` test run as `pinround_app` with RLS on (if not, use the `SECURITY DEFINER` search function from data-model.md) and stays ≤ 50 ms on the perf dataset.
- [ ] A case where the 3 best-ranked candidates fail the word rule but the 5th passes still returns the 5th.
**Tests:** integration (thresholds, stop words, visibility), functional "add there", Playwright.
**Prototype check:** [duplicate suggestions](../04-prototype/screens/report-a-problem.md#duplicate-suggestions).
**Size:** M

### P06.T3 — Check reasons, word lists and team-only visibility
**Why:** checks are flags, not a gate; sensitive reports stay inside the team ([FR-INT-08, FR-INT-09, FR-INT-13](../01-requirements/functional-requirements.md#int--intake-and-checks), [NFR-SEC-13](../01-requirements/non-functional-requirements.md#sec--security)).
**Steps:**
1. `CheckReasons::for(...)` returning the set of `dupe`, `info` (description < 40 characters and no pins), `team` (no team), `risk` (risk list), `rule` (org lead-check switch).
2. Security list → visibility `team` at creation; lead can change visibility later (audited event `TicketVisibilityChanged`).
3. Extend the **visibility leak suite** from P03.T2 with the intake paths: duplicate suggestions, the "Add my report there" target, the to-check list, the check box, the creation toast and the preview sentence.
4. Expose `CheckReasons::isSecuritySensitive(DraftReport): bool` (security word list on title, detail and pin notes; if the hackathon P08.T7 already created a minimal version, extend it). P08 calls it **before** any cloud AI call on a draft, so a security report never reaches cloud AI.
5. Tag "ONLY THE TEAM AND ADMINS SEE THIS" on the ticket header.
**Files:** `src/Ticketing/Domain/Intake/{CheckReasons,WordLists}.php`, `tests/Integration/Visibility/*`.
**Acceptance criteria:**
- [ ] Each reason has unit tests for its edge cases (39 vs 40 characters; pins present; "dropdown" does not match "down").
- [ ] The visibility leak suite passes and runs in CI.
- [ ] Admins and team members see the ticket; a manager without a team role does not.
- [ ] Ben, the outside reporter of a team-only ticket, still sees it in My reports and on the ticket page, and can answer a question to him.
**Tests:** unit, the leak suite, voter rows.
**Prototype check:** the tag text and colour in [Ticket page: header](../04-prototype/screens/ticket-page.md#header).
**Size:** M

### P06.T4 — "When you send" preview and toasts
**Why:** the reporter knows exactly what will happen before pressing the button ([FR-INT-07](../01-requirements/functional-requirements.md#int--intake-and-checks)).
**Steps:**
1. Preview sentence from the same services as creation: next key (shown as a forecast; the real number is taken at create), checker name or "you", reasons in plain words, "Omar can start already." / "Omar gets it after that check.", "It mentions security, so only the team and admins can see it."
2. Without reasons: "It becomes ticket SHOP-88 right away and goes straight to Omar. Nobody needs to check it."
3. Creation toasts: "Ticket SHOP-88 made. Lena takes a quick look." / "… It is on your list to check." / "… Nobody needs to check it."
**Files:** `src/Ticketing/Domain/Intake/PreviewSentence.php`.
**Acceptance criteria:**
- [ ] For every combination of reasons, the preview and the created ticket agree (property test).
- [ ] Texts match the prototype word for word (except names and keys).
**Tests:** unit (table of combinations), property test, `lint:copy`.
**Prototype check:** [check reasons and the preview sentence](../04-prototype/screens/report-a-problem.md#check-reasons-and-the-preview-sentence), [create ticket](../04-prototype/screens/report-a-problem.md#create-ticket).
**Size:** S

### P06.T5 — Checker routing and to-check lists
**Why:** only unclear tickets reach a human, and always the right human ([FR-INT-10, FR-INT-14](../01-requirements/functional-requirements.md#int--intake-and-checks), [FR-TKT-07](../01-requirements/functional-requirements.md#tkt--tickets)).
**Steps:**
1. Checker = team lead, or the triager when there is no team; stored in `ins_ticket_turn.check_user_id`; kind `check` with +40 for `risk`.
2. Turn rule 2 (no assignee → checker) wired into the projector call at creation and after each check action.
3. Tickets list: chip "New · to check · N" (shown when N > 0), row meta "To check by Lena" / "To check by you".
4. `HomeQueries` method `toCheck(UserId)` for P07.
**Files:** `src/Insight/Application/*`, `src/Ticketing/UI/Component/TicketFilters.php`.
**Acceptance criteria:**
- [ ] A ticket with no team goes to the triager; after "Move to team" it goes to that team's lead.
- [ ] With an assignee and no rule, Now shows the assignee while the lead still has it in the to-check list.
**Tests:** integration (routing, projector), functional chip and meta.
**Prototype check:** [toolbar and filters](../04-prototype/screens/tickets-list-and-whose-turn.md#toolbar-and-filters) chip text; list row meta.
**Size:** S

### P06.T6 — The check box on the ticket
**Why:** the checker confirms or fixes in one place ([FR-INT-11](../01-requirements/functional-requirements.md#int--intake-and-checks)).
**Steps:**
1. Box "New ticket · you take a quick look" (or "· Lena takes a quick look" for others), "Why:" list of reasons, the note ("Nobody is blocked. The ticket already counts; you just confirm or fix it." / "Nobody waits for this. The ticket already moves." / "It is not given to anyone until Lena checks it.").
2. Possible duplicates with key, title, Now line; for the checker "Same problem · close this one": closes as duplicate (Done), comments on both tickets ("Closed: same problem as SHOP-41. Follow that ticket." / "Ben reported this again as SHOP-88, with 2 pins on a screenshot."), notifies the reporter (`closed_duplicate`).
3. Checker buttons: **Looks fine** (marks checked, applies a pending assignee, toast "Checked. It goes to Omar now." / "Checked. It moves on like any ticket."), **Give it to…** (no assignee and team known), **Ask Ben for more** (reason `info` and reporter ≠ checker: question "Can you add the steps to see this, and a screenshot with pins?").
4. "Move to team" chips when there is no team (removes reason `team`).
5. `POST /t/{key}/check/{action}` with voter `CHECK`; every action clears the actor's pings (FR-WF-17).
**Files:** `src/Ticketing/UI/Component/CheckBox.php`, `src/Ticketing/Application/{ConfirmCheck,CloseAsDuplicate,MoveToTeam}/*`.
**Acceptance criteria:**
- [ ] Only the checker sees the action buttons; others see the box read-only.
- [ ] After Looks fine the ticket leaves every to-check list in the same request.
- [ ] Close as duplicate writes both comments and one event per ticket.
**Tests:** functional per action, voter rows, Playwright checker journey.
**Prototype check:** [new ticket check box](../04-prototype/screens/ticket-page.md#new-ticket-check-box): head text, reasons, notes, buttons.
**Size:** M

### P06.T7 — Lead-check rule, pending assignee and Admin "New tickets"
**Why:** some companies want every new ticket checked before anyone gets it ([FR-INT-12](../01-requirements/functional-requirements.md#int--intake-and-checks), [FR-ORG-09](../01-requirements/functional-requirements.md#org--organization-teams-and-roles), [FR-ADM-07](../01-requirements/functional-requirements.md#adm--admin)).
**Steps:**
1. Org setting `lead_check` (default off) and `triager_id` (default first admin) commands with audit events.
2. With the rule on: the chosen assignee is stored as `pending_assignee_id`; reason `rule`; Looks fine applies it and moves Backlog → To do.
3. Minimal admin page `/admin/new-tickets`: switch "Every new ticket needs a lead check before it is given to anyone", note "Off is the default. Turn it on only if your teams get many reports from outside, for example from support.", select "Who checks tickets with no team", the list of reasons. P11 wraps it in the full admin layout.
**Files:** `src/Organization/Application/ChangeSettings/*`, `src/Web/UI/Controller/Admin/NewTicketsController.php`.
**Acceptance criteria:**
- [ ] With the rule on, an assignee cannot start work before the check (no "Start work" verb; note "It is not given to anyone until Lena checks it.").
- [ ] Only admins with TOTP can open the page.
**Tests:** functional (rule on/off), voter, settings audit event emitted.
**Prototype check:** [Admin: new tickets](../04-prototype/screens/admin.md#new-tickets).
**Size:** S

## Definition of done
- [ ] All tasks ticked; the report → check → close-as-duplicate and report → move-to-team → looks-fine journeys pass in Playwright.
- [ ] The visibility leak suite runs in CI and passes.
- [ ] Preview sentence and creation always agree (property test).
- [ ] Duplicate query within its budget on the perf dataset.
- [ ] Global [Definition of done](README.md#definition-of-done-for-every-task) met for every task.

## Hand-off notes
- P07 shows the "New tickets to check" group on Home from `HomeQueries::toCheck` (+40 for risky).
- P08 adds AI suggestions (✦ type/priority, AI duplicates, `missing` → `info`, `risky` → `risk`) through the `CheckReasons` and `ReportForm` hooks; the AI never sets visibility.
- P09 must apply the visibility scope in every MCP tool.
- 2026-11-15: step 3 ("ticket + Round 0 in one transaction") must not have Ticketing's `ReportForm` or `CreateTicket` call Review's Api; Ticketing_UI and Ticketing_Application may not see Review_Api (`deptrac.yaml`). How Round 0 is created at ticket creation without a Ticketing to Review call is settled in P05.T5 (phase 5); step 3 must follow that decision.
- (add dated notes here)
- 2026-11-19 (P06.T1 step 5 only): the report drafts are built in Review (`rvw_report_draft`, `Version20261119190000`; `Review\Api\ReportDrafts`; `ReportDraftController` at `/report-drafts`). Send makes the ticket as Bug, Normal, no assignee, so it starts in Backlog; a picture still processing refuses Send with RoundRefused's text and keeps the draft. The edit page changes the text, picture titles, summaries and pin notes and removes pictures; it does not move pins or add pictures (an upload field is still to come). Steps 1 to 4 (the Report a problem page) are not built; its link to the drafts list comes with them.
- 2026-11-19 (P06.T1 steps 1 to 4): `/report` (`ticketing_report`, `ReportController`, `report.html.twig`), open to every role of the organization (`TicketPermission::REPORT`, `TicketReportVoter`, ADR-0038); the header button links to it. A plain server-rendered form, not a Live Component: radio chips for team, Give it to, project, type and priority; the `report-form` Stimulus controller shows the picked team's groups and disables the others' radios. Teams that own no project are left out; the reporter's own teams come first. "Give it to" (own teams only) is up to four people who are not testers, fewest open assigned tickets first (`AssigneeTicketCounts`). `TicketCommands::report` (new) works out the check reasons (`CheckReasons::for`, all five rules; `dupe` and `rule` wait for P06.T2 and P06.T7) and team-only visibility, stores them, and for "Not sure" makes a ticket with no team in the organization's core Backlog. The toast names the checker (team lead, or the triager for no team). Step 4: `/tickets/new` stays as the team ticket form (ADR-0038). The title placeholder is "For example: The cart total is counted twice", not the prototype's, which holds company wording. Still open: the keyboard-only check (Playwright, with P06.T4's journey). Known difference: a picture summary over 600 characters says "A picture summary holds at most 600 characters." (Review's domain text), not FR-RND-16's "Keep the summary to 600 characters or fewer."; the same on the team form.
- 2026-11-19 (P06.T5, the turn part only): a ticket with check reasons and no `checked_at` now needs a check in every turn snapshot (`TurnSnapshotRow` `is_check_needed`, `Ticket::isCheckNeeded`, `EntityTicketTurnSnapshots`), and its checker is the team's lead, or the organization's triager (`SettingsQueries::triagerOf`) for a ticket with no team. Turn rule 2 then gives the checker the turn while nobody is assigned, and `ins_ticket_turn.check_user_id` names them. A no-team ticket gets a turn row only while it waits on its check (`DbalTicketTurnSource`, the triager standing in as the lead). Proof: `ReportControllerTest` reads the turn row of a Not sure, a risky and an assigned report. Not built yet: the to-check list and chip, Home's group, and the check box on the ticket (P06.T5 steps 2 to 4, P06.T6), so nothing sets `checked_at` yet.
