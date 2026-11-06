# ADR-0032 — Module call edges follow the modules catalogue

Which module may call which module's Api is defined by the "Depends on" lines in `modules.md`, and any module may listen to any module's events.

## Index

- [Status](#status) — accepted, supersedes part of ADR-0002.
- [Context and problem statement](#context-and-problem-statement) — two lists that disagree.
- [Decision drivers](#decision-drivers) — what the answer must allow.
- [Considered options](#considered-options) — keep the diagram, use the catalogue, merge both.
- [Decision outcome](#decision-outcome) — the rule, the edge differences, consequences.
- [Confirmation](#confirmation) — the Deptrac config and its tests.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — trade-off analysis.
- [More information](#more-information) — links and revisit triggers.

## Status

Accepted · Supersedes in part [ADR-0002](0002-modular-monolith.md): only the "Allowed dependencies" diagram (the Mermaid flowchart under "Rules"). The rest of ADR-0002, including its five rules, stays as decided.

- Date: 2026-11-06
- Deciders: Zairan Mir (lead)

## Context and problem statement

Omar builds the Ticketing move action for Acme Foods. ADR-0015 says the turn row must change in the same transaction as the move, so Ticketing has to call `Insight\Api\TurnProjector`. The diagram in ADR-0002 has no arrow from Ticketing to Insight, so Deptrac, if it followed the diagram, would block the code the other ADR asks for. Lena's Home page has the same kind of gap: the catalogue lets Web read only four modules, the diagram lists seven.

ADR-0002 and `docs/02-architecture/modules.md` were written together and disagree on several edges. Which list does the build enforce?

## Decision drivers

- The build must allow what ADR-0015 needs (a synchronous call from Ticketing to Insight).
- One list, so a reader and Deptrac never get different answers.
- Modules should react to other modules' events without knowing their internals (ADR-0002 rule 1, ADR-0009).

## Considered options

1. Keep the ADR-0002 diagram as the rule.
2. Use the "Depends on" lines in `modules.md` as the rule.
3. Use the diagram and patch it edge by edge when code needs one.

## Decision outcome

Chosen option: **the "Depends on" lines in `modules.md`**, because they are the detailed list, one per module, and they already include the Ticketing to Insight edge that ADR-0015 needs.

- **Api calls.** A module's Application, Infrastructure and UI layers may use the Api of the modules in its "Depends on" line, and nothing else.
- **Events.** Event classes live in `Api/Event`. Any module's Application and Infrastructure layers may use any module's event classes. This is the "listening to its domain events" that ADR-0002 rule 1 already allows. Event classes depend on SharedKernel only.

Edge differences from the ADR-0002 diagram (arrow means "may call the Api of"):

| Module | In ADR-0002 diagram | Now (modules.md) |
|---|---|---|
| Ticketing | Workflow, Organization, Media | adds Insight |
| Web | Insight, Ticketing, Conversation, Review, Organization, Assist, Notification | loses Conversation, Review, Assist |
| AgentApi | Ticketing, Review, Conversation, Insight | loses Insight; gains Identity, Media |
| Notification | Organization | adds Ticketing |
| Insight | events only from Ticketing | calls Workflow Api and Ticketing Api |
| Organization | none | Identity |
| Workflow | none | Organization |
| Audit | events only from Organization | none (events are open to every module) |

Unchanged: Review, Conversation, Assist, Integration and Media.

### Consequences

- Good: Ticketing can call `TurnProjector` in the same transaction.
- Good: the build and the catalogue say the same thing.
- Bad: Ticketing and Insight now call each other's Api, a cycle at module level. The Api layers stay interfaces and DTOs, so it is a compile-time cycle only.
- Neutral: event classes are visible to every module, so changing an event payload means adding fields only (see the event rules in `modules.md`).

## Confirmation

`deptrac.yaml` has the layers `<Module>_Api`, `<Module>_Event`, `<Module>_Domain`, `<Module>_Application`, `<Module>_Infrastructure` and `<Module>_UI`, and `composer deptrac` fails on any other edge or on an uncovered class. `tests/Unit/Architecture/DeptracConfigTest.php` checks the config shape. `tests/Integration/Architecture/ModuleBoundaryViolationTest.php` runs Deptrac on four fixtures: a forbidden Domain edge, an uncovered class, an Api call to a module not on the caller's "Depends on" line, and the allowed Api and event edges.

## Pros and cons of the options

### Keep the ADR-0002 diagram

- Good, because the ADR stays untouched.
- Bad, because it blocks the call ADR-0015 needs.

### Use the modules.md lines

- Good, because it is complete and matches the planned code.
- Bad, because the lines in `modules.md` must stay in step with `deptrac.yaml` by hand.

### Patch the diagram edge by edge

- Good, because changes are small.
- Bad, because the diagram stays a second list that drifts again.

## More information

- Related: [ADR-0002](0002-modular-monolith.md), [ADR-0009](0009-async-messaging-and-outbox.md), [ADR-0015](0015-turn-read-model.md), [modules.md](../02-architecture/modules.md#deptrac-rules).
- Revisit when: a module needs more than 6 Api edges, or Deptrac reports a cycle that is not Ticketing and Insight.
