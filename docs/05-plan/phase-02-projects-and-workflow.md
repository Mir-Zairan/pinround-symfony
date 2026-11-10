# Phase 02 — Projects and workflow

Goal: projects with ticket keys, the five groups and their steps, the step policy and rules, the verb (transition) engine, and the "whose turn" read model, all tested before tickets exist.

## Index
- [Before you start](#before-you-start) — prototype screens, requirements, ADRs, docs
- [Outcome](#outcome) — what works after this phase
- [Scope](#scope) — what this phase builds
- [Out of scope](#out-of-scope) — what waits
- [Requirements covered](#requirements-covered) — FR and NFR ids
- [Design notes](#design-notes) — modules, tables, pure functions, events
- [Tasks](#tasks) — P02.T1 to P02.T8
- [Definition of done](#definition-of-done) — phase checklist
- [Hand-off notes](#hand-off-notes) — notes for P03

## Before you start
1. **Prototype.** Open https://claude.ai/artifact/FmbJosB3s5mDi75cLqMFQ2 (Artifact tool, `action: "read"`, `path: "project/Main.dc.html"`). Look at the Team page ("How work moves here"), a ticket page's status pill, "Now:" and "Next:" lines and the "YOUR NEXT STEP" box for each role; read the `turnOf`, `verbFor`, `nowText`, `nextText` helpers in the script. Fallback docs: [Team: projects](../04-prototype/screens/team.md#projects), [Team: how work moves here](../04-prototype/screens/team.md#how-work-moves-here), [Ticket page: turn rules](../04-prototype/screens/ticket-page.md#turn-rules), [Now and Next texts](../04-prototype/screens/ticket-page.md#now-and-next-texts), [Your next step box](../04-prototype/screens/ticket-page.md#your-next-step-box), [Every move](../04-prototype/screens/ticket-page.md#every-move), [Admin: statuses](../04-prototype/screens/admin.md#statuses).
2. **Requirements:** [FR-WF-01, FR-WF-02, FR-WF-03, FR-WF-04, FR-WF-05, FR-WF-06, FR-WF-07, FR-WF-08, FR-WF-09, FR-WF-10, FR-WF-11, FR-WF-12, FR-WF-13, FR-WF-14, FR-WF-15, FR-WF-16](../01-requirements/functional-requirements.md#wf--workflow-steps-and-turns) (FR-WF-14 reviewer and tester choice, FR-WF-15 needs-input target, FR-WF-16 move side effects), [FR-ORG-06, FR-ORG-07](../01-requirements/functional-requirements.md#org--organization-teams-and-roles), [NFR-PERF](../01-requirements/non-functional-requirements.md#perf--performance) NFR-PERF-01, NFR-PERF-06.
3. **ADRs:** [0014 five groups](../03-adr/0014-status-model-five-groups.md), [0015 turn read model](../03-adr/0015-turn-read-model.md), [0006 identifiers](../03-adr/0006-identifiers.md), [0002 modular monolith](../03-adr/0002-modular-monolith.md).
4. **Architecture docs:** [workflow engine](../02-architecture/workflow-engine.md) — read all of it, especially [five groups and core steps](../02-architecture/workflow-engine.md#five-groups-and-core-steps), [policy](../02-architecture/workflow-engine.md#company-steps-team-steps-and-the-step-policy), [rules](../02-architecture/workflow-engine.md#rules-guards), [transition catalogue](../02-architecture/workflow-engine.md#transition-catalogue), [whose turn](../02-architecture/workflow-engine.md#whose-turn), [Now and Next](../02-architecture/workflow-engine.md#now-and-next-sentences), [needs-you kinds and score](../02-architecture/workflow-engine.md#needs-you-kinds-and-score); [Workflow module](../02-architecture/modules.md#workflow-wf_), [Insight module](../02-architecture/modules.md#insight-ins_); tables [Workflow](../02-architecture/data-model.md#workflow), [ins_ticket_turn](../02-architecture/data-model.md#ins_ticket_turn-the-most-important-table-for-speed).
5. **Previous phase:** check the [P01 Definition of done](phase-01-identity-and-organization.md#definition-of-done).

## Outcome
Lena creates project SHOP. The next ticket will be SHOP-1, and two tickets created at the same moment never share a number. Every org has the eight core steps in the five groups. Lena adds a team step "Design check" under Checking because the policy is "extend". Given any ticket state and any person, the engine says which verbs they may use, why others are hidden ("Team rule: nobody approves their own code. Lena reviews it."), whose turn it is, and the "Now:" and "Next:" sentences. The Team page shows "How work moves here".

## Scope
- Projects and per-project key counters (Ticketing owns `tkt_project`).
- Steps (core, company, team), groups, step policy, rule sets.
- Guards, transition catalogue as data, the transition engine (`TransitionPolicy`).
- Turn rules, reviewer/tester choice, needs-input target, needs-you kind and base score (pure functions).
- `ins_ticket_turn` table and `TurnProjector` (Insight), ready to be called by Ticketing in P03.
- Now/Next sentence builder and per-person verb text ("Test it", "Review Omar's code").
- Team page: projects list and "How work moves here".

## Out of scope
- Tickets themselves (P03). This phase tests with `TicketState` fixtures.
- Admin "Statuses" page UI (P11). This phase gives the commands and a minimal lead UI on the Team page.
- Jira status mapping (P10).

## Requirements covered
FR-ORG-06, FR-ORG-07 (projects + how work moves here), FR-WF-01, FR-WF-02, FR-WF-03, FR-WF-04, FR-WF-05, FR-WF-06, FR-WF-07 (engine), FR-WF-08, FR-WF-09, FR-WF-10 (engine), FR-WF-13, FR-WF-14, FR-WF-15, FR-WF-16, NFR-PERF-06.

## Design notes
- **Workflow is pure where it can be.** `TransitionPolicy::available(TicketState, ActorContext)` and `TurnRules::turnFor(TicketState, TeamFacts)` are pure functions over value objects. They have no database access. This makes them fast and easy to test.
- **`TicketState`** (Workflow Api DTO): step id, core code, group, assignee, pending assignee, reviewer, tester, previous step, reporter, waiting-on user/label, open question (asked user), check needed + checker, priority, release flag, team id.
- **`TeamFacts`**: lead id, developers, testers with their open-review and in-testing counts (for reviewer and tester choice).
- **Tables:** `tkt_project` (`next_number`), `wf_step` (`grp`, `core_code`, `origin`, `position`, `archived_at`), `wf_rule_set`, `ins_ticket_turn` (all with RLS).
- **Events:** `StepAdded`, `StepRemoved`, `RulesChanged`, `ProjectCreated`.
- **Group colours** come from the fixed tokens ([five group colours](../00-product/ux-principles.md#the-five-group-colors)); never per team.
- **Performance:** steps and rule sets per team are cached in `valkey-cache` with tags `wf-team-{t}` and `wf-org-{o}`, cleared after a committed step or rule change and again on `StepAdded/StepRemoved/RulesChanged`.

## Tasks

### P02.T1 — Projects and ticket keys 🏁
**Why:** humans talk about "SHOP-41", not UUIDs ([FR-ORG-06](../01-requirements/functional-requirements.md#org--organization-teams-and-roles), [ADR-0006](../03-adr/0006-identifiers.md)).
**Steps:**
1. `Ticketing\Domain\Project`, `ProjectKey` (2–10 capital letters/digits, starts with a letter, unique per org).
2. Migration `tkt_project` with RLS and `UNIQUE (organization_id, key)`.
3. `NextTicketNumber` service: `UPDATE tkt_project SET next_number = next_number + 1 … RETURNING next_number - 1` (`next_number` is the number the next ticket gets, from 1) inside the caller's transaction.
4. Command CreateProject (lead of the owning team or admin; voter `PROJECT_CREATE`).
5. Seed SHOP for the Shop team in `app:demo:seed`.
**Files:** `src/Ticketing/Domain/{Project*,BlankProjectName,InvalidProjectKey,UnknownProject}.php`, `src/Ticketing/Application/NextTicketNumber.php`, `src/Ticketing/Application/CreateProject/*`, `src/Ticketing/Infrastructure/Doctrine/{DoctrineProjects,DbalNextTicketNumber}.php`, `src/Ticketing/Api/ProjectPermission.php`, `src/Ticketing/UI/Voter/ProjectVoter.php`, `src/Ticketing/Infrastructure/Demo/SeedDemoProjects.php`, `src/Organization/Api/DemoOrganizations.php`, `src/Organization/Infrastructure/Demo/FirstAdminDemoOrganizations.php`, `migrations/Version20261110100000.php`.
**Acceptance criteria:**
- [x] 50 number requests on one project from sessions taking turns give the numbers 1 to 50, each once: a second session waits on the holder's row lock, and a number a holder rolls back goes to the next taker (`NextTicketNumberConcurrencyTest::fiftyCommittedTicketsFromTakingTurnSessionsGetTheNumbersOneToFiftyEachOnce`). PHP cannot wait in two places, so the sessions take turns instead of running in parallel.
- [x] Key "shop", "1SHOP" and "SHOPPINGCART1" are rejected with a plain message (`ProjectKeyTest::itRejectsAnyOtherKeyWithAPlainMessage`, `CreateProjectTest::aKeyOutsideTheFormatIsRefusedAndNothingIsSaved`, `ProjectTableTest::theDatabaseRefusesABadKeyOrNextNumber`).
- [x] Org B cannot see or use org A's project (`ProjectIsolationTest::anotherOrganizationFindsTheProjectNeitherByKeyNorById`, `::anotherOrganizationCannotTakeANumberOfTheProject`, `CreateProjectTest::aTeamOfAnotherOrganizationCannotOwnTheProject`).
**Tests:** unit tests for `ProjectKey`; integration concurrency test (parallel connections); isolation and voter tests.
**Prototype check:** project key style in [Team: projects](../04-prototype/screens/team.md#projects) (key in mono font, name, count).
**Size:** S

### P02.T2 — Five groups and core steps 🏁
**Why:** every team maps to the same five groups, so managers can compare ([FR-WF-01, FR-WF-02](../01-requirements/functional-requirements.md#wf--workflow-steps-and-turns)).
**Steps:**
1. `Workflow\Domain\Group` enum (`todo`, `doing`, `check`, `wait`, `done`) with label and list order (Waiting, Checking, Doing, To do, Done).
2. `Step` entity; `CoreStep` codes (`backlog`, `todo`, `progress`, `review`, `test`, `input`, `hold`, `done`).
3. Migration `wf_step`, `wf_rule_set` with RLS. `SetUpOrganizationWorkflow`, an `Organization\Api\OrganizationSetupStep` ([ADR-0036](../03-adr/0036-organization-setup-steps-in-the-creating-transaction.md)) that `CreateOrganization` runs in the transaction that creates the organization, inserts the eight core steps and the default rule set (all three rules on); the migration `Version20261110140000` gives them to organizations that already exist.
4. `StepQueries::stepsFor(TeamId)` ordered by group list order, then origin (core, company, team; since P02.T3), then position, then id, without removed steps; `groupOf(string $stepId)` (the group code, removed steps included). The Api uses strings for ids and codes, as it may not expose Workflow's Domain types.
5. Hint texts per group for lists: "Someone must answer first", "Being checked", "Being built", "Not started".
**Files:** `src/Workflow/**`, migrations.
**Acceptance criteria:**
- [x] A new org has exactly eight core steps in the right groups and cannot remove them (`SetUpOrganizationWorkflowTest`: the eight rows, their groups and positions, none removable; `CoreStepBackfillTest` for organizations that already existed).
- [x] `stepsFor` returns a team's core, company and own steps in the list order above (Waiting, Checking, Doing, To do, Done), then by origin (core, company, team; added in P02.T3), then by position, then by id, without removed steps or another team's (`DbalStepQueriesTest::stepsForGivesTheCoreCompanyAndOwnTeamStepsInListOrderThenOriginThenPositionThenId`, `::stepsForLeavesOutAnotherTeamsStepsAndRemovedSteps`; isolation in `::anotherOrganizationSeesOnlyItsOwnSteps` and `::rowLevelSecurityAloneHidesTheStepsWithTheTenantFilterOff`).
**Tests:** unit tests for Group order and labels; integration test of seeding; isolation test.
**Prototype check:** step names and group labels in [Team: how work moves here](../04-prototype/screens/team.md#how-work-moves-here).
**Size:** S

### P02.T3 — Company steps, team steps, policy and rules
**Why:** teams differ, but the company decides how much ([FR-WF-03, FR-WF-04, FR-WF-05, FR-WF-06](../01-requirements/functional-requirements.md#wf--workflow-steps-and-turns)).
**Steps:**
1. Commands AddStep / RemoveStep (company: admin; team: lead when policy is `extend` or `team`), ChangeStepPolicy (admin), SetRule (admin; lead per team under `team`).
2. Validation: name required, group not `done`, a team step's team is the organization's; removal only when no open ticket is in the step (asked through `Workflow\Api\StepOccupancy`, which Ticketing implements, returning 0 until P03).
3. Policy `locked`: team steps are hidden from new moves; tickets already there keep them.
4. `RuleSet` resolution: team rule set under `team` policy, else the org rule set.
5. Events `StepAdded`, `StepRemoved`, `RulesChanged` (built); cache invalidation by tag (built, slice 5b-3).
**Files:** `src/Workflow/Application/{AddStep,RemoveStep,SetRule,StepChangeRights}.php` with their refusals `{StepGroupUnknown,StepTeamNotFound,TeamStepsLocked,StepNameMissing,StepInDoneRefused,UnknownStep,CoreStepNotRemovable,StepHasOpenTickets,RuleUnknown,RuleTeamNotFound,TeamRulesNeedTeamPolicy}.php`, `src/Workflow/Api/Event/{WorkflowEvent,StepAdded,StepRemoved,RulesChanged}.php`, `src/Workflow/Application/WorkflowEvents.php`, `src/Workflow/Infrastructure/Messenger/{MessengerWorkflowEvents,InvalidateWorkflowCacheHandler}.php`, `src/Workflow/Infrastructure/Cache/{WorkflowCache,WorkflowCacheTransactionMiddleware,CachedStepQueries,CachedRuleQueries}.php`, `config/packages/cache.yaml`, `src/Workflow/Api/{StepOccupancy,WorkflowPermission,RuleQueries,RuleSetView}.php`, `src/Workflow/Domain/{RuleSet,RuleSets,Step,Steps}.php`, `src/Workflow/Infrastructure/Doctrine/{DbalRuleSets,DbalRuleQueries,DbalStepQueries,DoctrineSteps}.php`, `src/Workflow/UI/Voter/WorkflowVoter.php`, `src/Ticketing/Infrastructure/Workflow/TicketStepOccupancy.php`, `src/Organization/Application/ChangeStepPolicy.php`, `src/Organization/Api/{SettingsQueries,StepPolicy,MemberJob,TeamQueries}.php`, `src/Organization/Domain/{OrgSettings,OrgSettingsStore}.php`, `src/Organization/Infrastructure/Doctrine/{DbalSettingsQueries,DbalTeamQueries,DoctrineOrgSettingsStore}.php`.
**Acceptance criteria:**
- [x] Under `locked`, a lead may not add a team step: the voter denies `STEP_CHANGE_TEAM` and AddStep refuses with `TeamStepsLocked`; under `extend`, the voter grants it for the lead's own team only (`WorkflowVoterTest::underLockedTheLeadChangesNeitherTheTeamStepsNorTheRules`, `::underExtendTheLeadChangesTheStepsOfTheirOwnTeamOnlyAndNoRules`, `StepChangeRightsTest`, `AddStepTest::underLockedACompanyStepIsAddedButATeamStepIsRefused`; a team of another organization: `AddStepTest::aTeamStepForATeamThatIsNotTheOrganizationsIsRefusedAndNothingIsSaved`).
- [x] Under `extend`, a lead may not change rules: the voter denies `RULES_CHANGE_TEAM` and SetRule refuses a team rule with `TeamRulesNeedTeamPolicy`; under `team`, the voter grants it for the lead's own team only (`WorkflowVoterTest::underExtendTheLeadChangesTheStepsOfTheirOwnTeamOnlyAndNoRules`, `::underTeamTheLeadChangesTheStepsAndRulesOfTheirOwnTeamOnly`, `StepChangeRightsTest`, `SetRuleTest::aTeamRuleIsRefusedUnderEveryPolicyButTeam`).
- [x] A step inside Done cannot be created: AddStep refuses with `StepInDoneRefused` and saves nothing (`AddStepTest::aBlankNameAnUnknownGroupAndTheDoneGroupAreRefusedAndNothingIsSaved`).
- [x] Under `locked`, `moveTargetsFor` leaves the team's own steps out and keeps core and company steps; under `extend`, `team` and with no settings it equals `stepsFor`; another organization's policy changes nothing; a bad team id is refused (`DbalStepQueriesTest::moveTargetsForLeavesOutTheTeamsOwnStepsUnderLockedAndKeepsCoreAndCompanySteps`, `::moveTargetsForEqualsStepsForUnderExtendTeamAndWithNoSettings`, `::anotherOrganizationsLockedPolicyDoesNotHideMyTeamSteps`, `::moveTargetsForRefusesAnIdThatIsNotATeamIdBeforeAnyQuery`).
- [x] `rulesFor` gives the team's rule set under `team` when it has one, else the organization's; under `extend`, `locked` and with no settings always the organization's; no rule rows means all three on; another organization's rows are never used, with row-level security alone as well (`DbalRuleQueriesTest::underTeamTheTeamsOwnRulesApply`, `::underTeamATeamWithoutRulesOfItsOwnGetsTheOrganizationsRules`, `::underExtendAndLockedAndWithNoSettingsTheOrganizationsRulesApplyEvenToATeamWithARow`, `::anOrganizationWithoutAnyRuleSetHasAllThreeRulesOn`, `::anotherOrganizationsRulesAreNeverUsed`, `::rowLevelSecurityAloneHidesTheOtherOrganizationsRulesWithTheTenantFilterOff`, `::anIdThatIsNotATeamIdIsRefusedBeforeAnyQuery`, `::withNoTenantTheRulesAreRefused`).
- [x] AddStep, RemoveStep and SetRule publish `StepAdded`, `StepRemoved` and `RulesChanged` with the right ids and codes, and none on a refusal; removing a removed step publishes once; each event is one outbox row after commit and none after a rollback; the relay routes `WorkflowEvent` to `async_notify` (`AddStepTest::aCompanyStepAndATeamStepEachPublishStepAddedWithTheirTeamAndGroup`, `RemoveStepTest::aTeamStepIsArchivedAndLeavesTheTeamsSteps`, `::aCompanyStepRemovedPublishesStepRemovedWithNoTeam`, `::removingARemovedStepAgainKeepsItsFirstTime`, `SetRuleTest::aTeamRuleChangePublishesRulesChangedWithTheTeamAndTheNewValue`, `::aRefusedRuleChangePublishesNothing`, `WorkflowEventOutboxTest`, `OutboxRelayRoutesConfigTest`).
- [x] Cache invalidation by tag: a committed AddStep, RemoveStep or SetRule (company or team) is seen by the next read of `stepsFor`, `moveTargetsFor` and `rulesFor`; a read is otherwise kept; a rolled-back change or a savepoint commit clears nothing; a changed step policy needs no clear, as it is in the key; two organizations never share an entry; the relayed event clears a second time (`CachedWorkflowQueriesTest::aCommittedAddStepOfTheCompanyOrTheTeamIsSeenByTheNextRead`, `::aCommittedRemoveStepIsSeenByTheNextRead`, `::aCommittedSetRuleOfTheOrganizationOrTheTeamIsSeenByTheNextRead`, `::aReadIsKeptSoARowWrittenWithoutAChangeIsNotSeen`, `::aRolledBackChangeIsReadInsideItsTransactionOnlyAndInvalidatesNothing`, `::aSavepointCommitInsideAChangeInvalidatesNothing`, `::aChangedStepPolicyIsSeenByTheNextReadWithoutAnyInvalidation`, `::twoOrganizationsNeverShareAnEntryForTheSameTeamId`, `::theRelayedEventInvalidatesTheTeamsEntriesASecondTime`; the tags, the key, the commit and rollback timing, the failure paths and reset in `WorkflowCacheTest`).
**Tests:** unit tests for policy decisions; voter matrix rows; integration tests for rule resolution.
**Prototype check:** policy texts and rule labels in [Admin: statuses](../04-prototype/screens/admin.md#statuses) and [Team: how work moves here](../04-prototype/screens/team.md#how-work-moves-here).
**Size:** M

### P02.T4 — Transition engine, guards and the hackathon verbs 🏁
**Why:** buttons are verbs, and the engine decides which ones a person sees ([FR-WF-07](../01-requirements/functional-requirements.md#wf--workflow-steps-and-turns), [ADR-0014](../03-adr/0014-status-model-five-groups.md)).
**Steps:**
1. `Transition` catalogue as data (verb name, from, to, who may), structured exactly as in the [transition catalogue](../02-architecture/workflow-engine.md#transition-catalogue). In this task fill the rows for: Plan it, Give it to…, Start work, Send to code review, Approve code, Ask for changes, Pass it, Needs changes.
2. Guards: `TesterMovesDone`, `NoSelfReview`, `ReviewBeforeTest`, `QuestionOpenPause` (no moves while a question is open). Each returns `null` or the plain note.
3. `TransitionPolicy::available(TicketState, ActorContext)` → ordered list of `TransitionView` (label, primary flag) + one rule note; `check(...)` → `Decision`.
4. Managers, viewers and admins without a team role get no verbs and the note "Managers can read and comment. Only the team moves tickets."
**Files:** `src/Workflow/Domain/{Transition,Guard,TransitionPolicy}*.php`, `src/Workflow/Api/TransitionPolicy.php`.
**Acceptance criteria:**
- [ ] For each of the eight rows, a test shows who sees the verb and who does not.
- [ ] With `no_self_review` on, Lena (lead and assignee) sees no "Approve code" and the note names the other reviewer.
- [ ] With a question open, `available()` returns no verbs and the note "Paused until Mia answers the question below."
- [ ] Managers, viewers and admins without a team role get no verbs and the note above.
**Tests:** table-driven unit tests (one row per catalogue row × role).
**Prototype check:** button labels, order (first one filled) and rule notes in [Your next step box](../04-prototype/screens/ticket-page.md#your-next-step-box) for these eight verbs.
**Size:** M

### P02.T5 — Full verb catalogue, move side effects and Move to another step
**Why:** the rest of the catalogue and its side effects make every move predictable ([FR-WF-07](../01-requirements/functional-requirements.md#wf--workflow-steps-and-turns), [FR-WF-10](../01-requirements/functional-requirements.md#wf--workflow-steps-and-turns), [FR-WF-16](../01-requirements/functional-requirements.md#wf--workflow-steps-and-turns)).
**Steps:**
1. Fill the remaining catalogue rows: Send to testing (only when `review_before_test` is off), Input given · go on, Go on, Reopen, Put on hold, Move on to <next core step>.
2. Move side effects as data on the decision (FR-WF-16): Backlog + assign → To do; "Input given · go on" → previous step else To do; "Go on" → previous step else In progress; "Move on to …" mapping by group (To do → In progress, Doing → Code review, Checking → Testing, Waiting → previous step).
3. "Move to another step…" list grouped by the five groups, current step marked (lead only); In progress → Testing is refused while `review_before_test` is on, with the note "Team rule: code review comes before testing."
4. Property test: no `available()` result ever contains a verb that `check()` denies.
**Files:** `src/Workflow/Domain/Transition*.php`, `src/Workflow/Domain/MoveSideEffects.php`.
**Acceptance criteria:**
- [ ] For each remaining catalogue row, a test shows who sees the verb and who does not.
- [ ] Each side effect in FR-WF-16 has a unit test with the expected target step.
- [ ] The property test runs 1,000 random states without a failure.
**Tests:** table-driven unit tests; property test.
**Prototype check:** [Every move](../04-prototype/screens/ticket-page.md#every-move) and the "Move to another step…" list in [Your next step box](../04-prototype/screens/ticket-page.md#your-next-step-box).
**Size:** M

### P02.T6 — Turn rules and the turn read model 🏁
**Why:** "whose turn" must be instant on Home and in lists ([FR-WF-08, FR-WF-14, FR-WF-15](../01-requirements/functional-requirements.md#wf--workflow-steps-and-turns), [ADR-0015](../03-adr/0015-turn-read-model.md)).
**Steps:**
1. `TurnRules::turnFor(TicketState, TeamFacts)` with the exact order from [whose turn](../02-architecture/workflow-engine.md#whose-turn): open question → asked person; check needed and no assignee → checker; Done → nobody; Backlog/On hold → lead; Code review → reviewer; Testing or any Checking step → tester; Needs input → waiting-on user or label (Customer, Product owner, Technical owner); else assignee or lead.
2. Reviewer choice (lead; if the lead is the author, the other developer with the fewest open reviews) and tester choice (the team tester with the fewest tickets in Testing) as pure functions (FR-WF-14). Fallbacks: no tester in the team → the Testing turn goes to the lead; the lead is the author and there is no other developer → the lead reviews and the override is written to the ticket activity ("Lena reviews her own code: no other developer in the team").
3. Needs-you `kind` (`answer`, `check`, `review`, `test`, `assign`, `input`, `decide`, `build`) and `base_score` = kind base + priority weight + release points ([needs-you kinds and score](../02-architecture/workflow-engine.md#needs-you-kinds-and-score)). The time part is computed in SQL at read time.
4. Migration `ins_ticket_turn` with the four indexes from the data model; RLS.
5. `Insight\Api\TurnProjector::project(TicketState, TeamFacts, ?OpenQuestion)`: upsert (or delete when Done), sets `waiting_since` only when the turn person changes.
**Files:** `src/Workflow/Domain/TurnRules.php`, `src/Insight/**`.
**Acceptance criteria:**
- [ ] Every turn rule has a unit test with the expected person or label.
- [ ] A label waiter ("Customer") has `turn_user_id` NULL and `turn_label` set.
- [ ] Reprojecting with the same turn keeps `waiting_since`; a new turn person resets it.
- [ ] A Done ticket has no row.
**Tests:** unit tests (rules, reviewer/tester choice and both fallbacks, score); integration tests for the projector and indexes (EXPLAIN uses the Home index). Tickets do not exist yet: test with a synthetic `TicketState` and a `ticket_id` without a foreign key; P03.T1 adds the foreign key from `ins_ticket_turn.ticket_id` to `tkt_ticket`.
**Performance notes:** one upsert per change; no reads of other tickets except the small `TeamFacts` counts (cached per request).
**Prototype check:** none (backend task; P03 shows the result on the ticket page).
**Size:** M

### P02.T7 — Now and Next sentences 🏁
**Why:** "Now: Lena reviews the code. Next: Mia tests it." replaces status jargon ([FR-WF-09](../01-requirements/functional-requirements.md#wf--workflow-steps-and-turns)).
**Steps:**
1. `NowNext::now(TicketState, Turn, names)` and `::next(...)` exactly as in [Now and Next sentences](../02-architecture/workflow-engine.md#now-and-next-sentences): "Omar builds it", "Lena reviews the code", "Mia tests it", "Waiting for input from Ben", "Lena decides if it goes on", "Lena plans it", "Lena picks a developer", "Lena takes a quick look at the new ticket", "Mia answers Lena's question", "Done. Nobody needs to act."
2. `VerbFor::person(...)`: the short task for Home and lists ("Test it", "Review Omar's code", "Answer Mia's question", "Check the new ticket", "Plan it", "Pick a developer", "Start building", "Build it", "Give input", "Decide: go on or stop").
3. First names only; the viewer becomes "you" where the prototype does ("Turn: you").
**Files:** `src/Workflow/Domain/NowNext.php`, `src/Workflow/Domain/VerbFor.php`.
**Acceptance criteria:**
- [ ] Each sentence in [Now and Next texts](../04-prototype/screens/ticket-page.md#now-and-next-texts) is produced by a test case with the same words.
- [ ] No sentence contains a raw step code.
**Tests:** table-driven unit tests; `lint:copy` passes on the strings.
**Prototype check:** Now/Next lines for each step on the ticket page header.
**Size:** S

### P02.T8 — Team page: projects and "How work moves here"
**Why:** a team sees its own way of working, in plain words ([FR-ORG-07](../01-requirements/functional-requirements.md#org--organization-teams-and-roles)).
**Steps:**
1. Route `GET /teams/{slug}` (`team_show`), voter `TEAM_VIEW`.
2. Projects section: key, name, open ticket count (0 until P03), link "See tickets", "Remove" and "Add project" for the lead only.
3. "How work moves here": the policy sentence, the five groups with their steps (team steps highlighted), "Add team step" (name + group chips, not Done) when allowed, rule list (editable switches only under `team` policy, else "· set by the admin").
4. Non-leads see "Only the team lead can change projects and steps."
5. People section shell (workload bars are filled in P03.T9).
**Files:** `src/Organization/UI/Controller/TeamController.php`, templates, Live Component for adding a step.
**Acceptance criteria:**
- [ ] Lena can add "Design check" under Checking; Omar cannot see the add form.
- [ ] Under `locked`, nobody sees "Add team step" and the policy sentence says so.
**Tests:** functional tests per role; Playwright add/remove team step.
**Prototype check:** [Team page](../04-prototype/screens/team.md): section order, policy sentences, step chips per group, rule list wording.
**Size:** M

## Definition of done
- [ ] All tasks ticked; engine, turn rules and Now/Next have full table-driven tests.
- [ ] Every new table has RLS, isolation tests and appears in the catalog test.
- [ ] Voter matrix rows added for project and workflow actions.
- [ ] Team page matches the prototype for lead and member.
- [ ] Global [Definition of done](README.md#definition-of-done-for-every-task) met for every task.

## Hand-off notes
- P03 must call `TransitionPolicy::check` before every move and `TurnProjector::project` in the same transaction.
- P03.T1 adds the foreign key `ins_ticket_turn.ticket_id → tkt_ticket.id` (P02.T6 tests ran without it).
- The step-removal check asks `Workflow\Api\StepOccupancy`, which Ticketing's `TicketStepOccupancy` implements and which returns 0 until P03 counts open tickets.
- 2026-11-10 (P02.T1): ProjectCreated is not built yet; add it with its outbox route, and CreateProject's acting user and time, when a consumer needs it.
- 2026-11-10 (P02.T1): P01.T3 "A user in two orgs picks one" and the P01 Definition of done are still open; phase 2 does not depend on them.
- 2026-11-10 (P02.T1): the demo seed finds Acme Foods through Organization's `DemoOrganizations::idBySlug('acme-foods')` (dev and test only), so no Ticketing class reads Identity. A lost race for a project key is proven by `CreateProjectRaceTest`.
- 2026-11-10 (P02.T2): core steps and the default rule set are created through `Organization\Api\OrganizationSetupStep` (implemented by `Workflow\Application\SetUpOrganizationWorkflow`, tagged by the `_instanceof` rule in `config/services.yaml`) in the transaction that creates the organization, chosen over an `OrganizationCreated` handler so no organization is ever without steps ([ADR-0036](../03-adr/0036-organization-setup-steps-in-the-creating-transaction.md); the fourth way in [modules.md, How modules talk](../02-architecture/modules.md#how-modules-talk)); organizations made before it got them from the idempotent backfill migration `Version20261110140000`. `tests/Functional/Identity/LogsInUsers.php` inserts organizations by raw SQL, so those test organizations have no steps: use `CreateOrganization` there if a later test needs them.
- 2026-11-10 (P02.T2 done): `Workflow\Api\StepQueries` (`DbalStepQueries`, plain SQL under row-level security, no cache yet) takes and returns string ids and group codes, since `Workflow_Api` may depend on SharedKernel only; `groupOf` returns null for an unknown step and throws `InvalidArgumentException` for an id that is not a UUIDv7, before any query, so a bad id never aborts the caller's transaction. The valkey cache of the design notes comes with P02.T3's invalidation.
- 2026-11-10 (P02.T2 review fix): `Version20261110140000` lifts FORCE row-level security on `org_organization` for one `SELECT` of every organization id and forces it again before writing; a resolver function was rejected, as resolvers take one exact key and one listing every tenant would stay callable by `pinround_app`. Its `set_config('app.org_id', …, true)` per organization is allow-listed in `bin/check-tenant-setting`. `Steps::add` takes several steps and flushes once. Open, outside this branch: `tests/Integration/Messaging/FillsTheTenantProbeTable.php` sets the tenant with `set_config(?, ?, true)` and a bound name, which the check cannot see.
- 2026-11-10 (P02.T2 design fix): `OrganizationSetupStep` moved from SharedKernel to `Organization\Api` (only Organization and Workflow use it) and is tagged by an `_instanceof` rule instead of `#[AutoconfigureTag]`; `SetUpOrganizationWorkflow` moved to `Workflow\Application`, as it uses Domain ports only. `composer lint` runs `lint:yaml config --parse-tags`, since `config/services.yaml` uses `!tagged_iterator`.
- 2026-11-10 (P02.T3): `ChangeStepPolicy` records no `SettingsChanged` yet: the event needs its entry in Audit's allow-list first. Add both, with the actor and time the command already takes, before the Admin screen's settings form ships. Other modules read the policy through `Organization\Api\SettingsQueries::stepPolicyOf` and a person's job through `TeamQueries::jobOf` (`MemberJob`, not Domain's `Job`). `OrgSettingsStore` gained `save`.
- 2026-11-10 (P02.T3): the open-ticket check of `RemoveStep` uses `Workflow\Api\StepOccupancy::openTicketsIn`, implemented by Ticketing (`Infrastructure\Workflow\TicketStepOccupancy`, 0 with a `TODO(P03)`), instead of the `Ticketing\Api` count step 2 first named: Ticketing already depends on Workflow's Api, so Workflow calling Ticketing would be a dependency cycle (the same inversion as `Organization\Api\OrganizationSetupStep`).
- 2026-11-10 (P02.T3): `ChangeStepPolicy` lives in `Organization\Application`, not in Workflow, as the step policy is stored in `org_settings`, Organization's table; Workflow reads it through `SettingsQueries::stepPolicyOf`. `StepChangeRights` is in `Workflow\Application`, not Domain, because Deptrac lets Workflow's UI (the voter) see Application but not Domain. AddStep and SetRule refuse a team that is not the organization's (`StepTeamNotFound`, `RuleTeamNotFound`, through `TeamQueries::teamsOf`). Built since: step 3 (`StepQueries::moveTargetsFor`, which P02.T5's "Move to another step" list will use; `DbalStepQueries` now also takes `SettingsQueries` and `TenantContext`) and step 4 (`RuleQueries::rulesFor`, `DbalRuleQueries`; a team or organization with no rule row gets all three rules on). Not built yet in T3 (slice 5b): step 5, the events `StepAdded`, `StepRemoved`, `RulesChanged` and cache invalidation by tag; the reads have no cache yet.
- 2026-11-10 (P02.T3, slice 5b-1): the events `StepAdded`, `StepRemoved` and `RulesChanged` are built (`Workflow\Api\Event\WorkflowEvent`, outbox, relay to `async_notify`). Audit has no allow-list entry for them yet, and `RulesChanged` is published on every set, also when the value did not change. Cache invalidation by tag was still open then; see the next line.
- 2026-11-10 (P02.T3, slice 5b-3): the workflow cache is built. `stepsFor`, `moveTargetsFor` and `rulesFor` are cached per team for a day in the pool `cache.workflow` (`CachedStepQueries`, `CachedRuleQueries`, `WorkflowCache`). Invalidation is by tag after commit: a DBAL middleware at priority -20 (`WorkflowCacheTransactionMiddleware`, the repo's first after-commit hook) clears the tags a change queued once the outermost transaction commits. A second invalidation runs on the relayed event (`InvalidateWorkflowCacheHandler`); it does not use `OnceOnlyHandling`, as clearing twice is harmless. The step policy is in the key, so `ChangeStepPolicy` needs no clear. Keys use `.`, as Symfony keys refuse `:`. The pool is on `cache.adapter.redis` with `tags: true`, not `redis_tag_aware`, because `valkey-cache` evicts by allkeys-lru. The design note on tags `org:{id}:workflow` is superseded by `wf-team-{t}` and `wf-org-{o}` (see performance-and-scale.md).
- (add dated notes here)
