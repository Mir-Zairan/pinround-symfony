# ADR-0037 — The transition engine lives in Workflow's Application layer

The verb catalogue is data in `Workflow\Domain`; the guards and the policy that lists and checks verbs live in `Workflow\Application\Transition` behind `Workflow\Api\TransitionPolicy`.

## Index

- [Status](#status) — accepted, supersedes part of ADR-0014.
- [Context and problem statement](#context-and-problem-statement) — an engine the module boundaries do not let sit in Domain.
- [Decision drivers](#decision-drivers) — what the answer must allow.
- [Considered options](#considered-options) — Domain engine, wider Deptrac rules, Application engine.
- [Decision outcome](#decision-outcome) — where each part lives, what the Api takes, consequences.
- [Confirmation](#confirmation) — the checks that hold it.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — trade-off analysis.
- [More information](#more-information) — links and revisit triggers.

## Status

Accepted · Supersedes in part [ADR-0014](0014-status-model-five-groups.md): only the Engine bullet naming `Workflow\Domain\TransitionEngine`. The engine is `Workflow\Application\Transition\CatalogueTransitionPolicy` behind `Workflow\Api\TransitionPolicy`; it stays pure PHP and now takes `TicketState`, `ActorInTeam` and `RuleSetView`; the team's step set is not passed. The rest of ADR-0014 stays as decided.

- Date: 2026-11-10
- Deciders: Zairan Mir (lead)

## Context and problem statement

Mia opens a ticket of the Shop team in Testing. The ticket page asks Workflow which buttons she gets, and she sees "Pass it" and "Needs changes". Omar, who tests this one ticket but has the job developer, sees only "Needs changes" and the note "Team rule: only the tester moves a ticket to Done." To answer, the engine must know Mia's team role and job in the ticket's team.

ADR-0014 places the engine in `Workflow\Domain` as `TransitionEngine`, with "actor permissions" as input. But `deptrac.yaml` lets `Workflow_Domain` and `Workflow_Api` use only `SharedKernel`, so neither may take Organization's `ActorContext` or call `TeamQueries::jobOf`. Where does the engine live, and what does its Api take?

## Decision drivers

- Modules talk only through their Api, checked by Deptrac ([ADR-0002](0002-modular-monolith.md), [ADR-0032](0032-module-call-edges-follow-modules-catalogue.md)).
- The engine stays pure: no database access, fast table-driven tests ([ADR-0014](0014-status-model-five-groups.md), FR-WF-07).
- Ticketing (P03) and the MCP server ask the same question through one Api.
- The verb list stays data that one release changes.

## Considered options

1. Keep `Workflow\Domain\TransitionEngine` and widen Deptrac so Workflow's Domain may use `Organization_Api`.
2. Keep the engine in Domain and give it only SharedKernel types, with the guards there too.
3. Keep the catalogue in Domain; put the guards and the policy in `Workflow\Application\Transition`, behind an Api that takes plain data.

## Decision outcome

Chosen option: **option 3**, because it keeps Domain and Api on SharedKernel as Deptrac requires, keeps the engine pure, and gives callers one Api.

- The catalogue is data in `Workflow\Domain`: `Verb`, `Transition`, `TransitionCatalogue`.
- The guards (`TesterMovesDone`, `NoSelfReview`, `ReviewBeforeTest`), the pause check `QuestionOpenPause`, `MoveRights` and `CatalogueTransitionPolicy` live in `Workflow\Application\Transition`.
- `Workflow\Api\TransitionPolicy` (`available`, `check`) takes `TicketState`, `ActorInTeam` (the person's id, team role and job in the ticket's team, plain data) and `RuleSetView`. It does not take Organization's `ActorContext`.

### Consequences

- Good: Deptrac stays as it is; Workflow's Domain and Api depend on SharedKernel only.
- Good: the policy is pure and tested without a kernel, role by role.
- Bad: every caller builds `ActorInTeam` itself: Ticketing (P03) takes the team role from `ActorContext` roles and the job from `TeamQueries::jobOf`, and passes `RuleQueries::rulesFor`.
- Neutral: the guards are Application classes, not Domain ones; they hold no state and read nothing.

## Confirmation

- `composer deptrac` fails if `Workflow_Domain` or `Workflow_Api` uses anything but SharedKernel (`deptrac.yaml`, the Workflow rows).
- `CatalogueTransitionPolicyTest` proves each catalogue row role by role, with the guards and the pause check.
- `TransitionPolicyWiringTest` proves the container gives `CatalogueTransitionPolicy` for `Workflow\Api\TransitionPolicy` and that it answers.

## Pros and cons of the options

### Widen Deptrac for Workflow's Domain

- Good, because ADR-0014's class name stays true.
- Bad, because Workflow's Domain would depend on another module's Api, against the layer rules every other module follows.

### Domain engine on SharedKernel types only

- Good, because the engine stays in Domain.
- Bad, because the Api types it takes would live in Domain or be copied, and Workflow's UI and other callers cannot see Domain.

### Catalogue in Domain, policy in Application

- Good, because each layer keeps its allowed dependencies and the policy stays pure.
- Bad, because callers do the mapping to `ActorInTeam`.

## More information

- Related: [ADR-0014](0014-status-model-five-groups.md), [ADR-0002](0002-modular-monolith.md), [ADR-0032](0032-module-call-edges-follow-modules-catalogue.md), [workflow-engine.md](../02-architecture/workflow-engine.md#implementation-notes), [modules.md](../02-architecture/modules.md), FR-WF-07.
- Revisit when: a guard needs data the caller cannot pass in one call, or more than 2 modules build `ActorInTeam` the same way.
- Note 2026-11-22 (Undo after a move, gap list 1.10, beyond the prototype): the policy has a fourth check, `TransitionPolicy::checkUndo`. The person who made a move may take it back to the step it left for `UNDO_SECONDS` (30) after it, by the server clock, while that move is still the last change (no save or history row since). It refuses anyone else ("Only {mover} can undo this move."), a late Undo ("Undo works for 30 seconds after a move. Ask {lead} to move it back."; the lead is told to use Move to another step… instead), a changed ticket, a step that is no move target any more, and, as every move, a person with no team role or a ticket with an open question. Ticketing keeps the fields a move changes in the move's history row (payload `moved_from`) and `MoveTicket::undoMove` puts them back (step, previous step, waiting-on, reviewer, tester, closed_at), so the turn is the one before the move; the Undo is a `moved` row with verb `undo`, which no Undo takes back. A round the move closed stays closed, and a move that decided a draft round offers no Undo. Gives have no Undo.
