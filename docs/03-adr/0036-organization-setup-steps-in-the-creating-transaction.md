# ADR-0036 — Set up a new organization's required data in the transaction that creates it

Other modules give every new organization the data it cannot exist without through setup steps that Organization runs in its own creating transaction, not through an `OrganizationCreated` event.

## Index

- [Status](#status) — accepted.
- [Context and problem statement](#context-and-problem-statement) — a new organization with no steps.
- [Decision drivers](#decision-drivers) — what the answer must allow.
- [Considered options](#considered-options) — an event through the outbox, a setup hook in the transaction.
- [Decision outcome](#decision-outcome) — the hook, its rules, consequences.
- [Confirmation](#confirmation) — the tests and checks that hold it.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — trade-off analysis.
- [More information](#more-information) — links and revisit triggers.

## Status

Accepted

- Date: 2026-11-10
- Deciders: Zairan Mir (lead)

## Context and problem statement

Sara creates the organization Acme Foods and opens the team page at once. Every team maps its work to the five groups through the eight core steps (FR-WF-01, FR-WF-02), so the page, and Lena's first ticket a minute later, need those steps to exist. The steps are Workflow's rows (`wf_step`, `wf_rule_set`), but the organization is made by Organization's `CreateOrganization`, and Organization may not call Workflow ([ADR-0032](0032-module-call-edges-follow-modules-catalogue.md): Workflow depends on Organization, not the other way).

How does another module add rows that a new organization cannot exist without, without Organization depending on it and without a moment when the organization exists but the rows do not?

## Decision drivers

- No organization is ever visible without its core steps and rule set: no "not ready yet" state in the UI or in other modules' queries.
- Organization must not call the Api of a module that depends on it ([ADR-0032](0032-module-call-edges-follow-modules-catalogue.md)).
- Tenant rows are written under row-level security with the new organization as tenant ([ADR-0005](0005-multi-tenancy.md)).
- Events stay facts delivered at least once through the outbox ([ADR-0009](0009-async-messaging-and-outbox.md)); their delay must not matter for correctness.

## Considered options

1. Workflow handles an `OrganizationCreated` event from the outbox and creates the steps.
2. A setup hook: Organization defines `Organization\Api\OrganizationSetupStep`, other modules implement it, and `CreateOrganization` runs every implementation inside the creating transaction.

## Decision outcome

Chosen option: **a setup hook in the creating transaction**, because it is the only option where the organization and its required rows commit together or not at all, and the dependency still points from Workflow to Organization's Api. The user chose it over the event.

- `Organization\Api\OrganizationSetupStep::setUp(OrganizationId)` is the interface. The `_instanceof` rule in `config/services.yaml` tags each implementation `app.organization_setup_step` (an Api may not use a vendor attribute), and `CreateOrganization` gets them as a tagged iterator, highest tag priority first.
- A step runs inside the caller's open transaction, after the organization row exists, with the new organization already the tenant.
- A step never begins, commits or rolls back a transaction. It records no events of its own, except through the outbox in that same transaction.
- Anything a step throws reaches the caller, which rolls back: the organization is not created either.
- The hook is only for data an organization cannot exist without. Anything that may arrive a few seconds later reacts to an Organization event instead.
- The first step is Workflow's `Application\SetUpOrganizationWorkflow` (eight core steps, the default rule set). Organizations made before it got them from the idempotent backfill migration `Version20261110140000`.

This is a fourth way for modules to talk, listed in [modules.md](../02-architecture/modules.md#how-modules-talk).

### Consequences

- Good: a new organization always has its core steps and rule set; no reader needs a "not set up yet" branch.
- Good: no new module edge: Workflow implements Organization's Api, as its "Depends on" line already allows.
- Bad: a bug or a slow query in any setup step fails or slows organization creation for everyone; creation is only as reliable as the weakest step.
- Bad: Organization runs code it does not own inside its transaction, so a step's rules (no transaction control, no events outside the outbox) are enforced by review and tests, not by the type system.
- Neutral: organizations created before a new step exists need a backfill of their own, as `Version20261110140000` did for Workflow.

## Confirmation

- `SetUpOrganizationWorkflowTest::aNewOrganizationGetsTheEightCoreStepsAndTheDefaultRuleSet` runs the `CreateOrganization` the container wires, so it fails if the `_instanceof` tag or the tagged iterator is removed.
- `SetUpOrganizationWorkflowTest::aFailingSetupStepRollsBackTheWholeOrganization` proves a failing step leaves no organization, membership, settings, steps or rule set.
- `CreateOrganizationTest` (unit) pins the order of the steps and that a failing step stops the later ones.
- `composer deptrac` keeps `Organization_Api` free of Vendor and lets only modules that depend on Organization implement the interface.

## Pros and cons of the options

### OrganizationCreated event through the outbox

- Good, because Organization knows nothing of who reacts, the usual way modules learn of facts.
- Bad, because the relay delivers it later: for a few seconds, longer if a worker is down, the organization exists without steps, and every page and module must handle that.
- Bad, because a handler that keeps failing leaves an organization without steps for good, found only through the failed transport.

### Setup hook in the creating transaction

- Good, because the organization and its required rows commit together.
- Good, because the dependency still points from the implementing module to Organization's Api.
- Bad, because every step adds to the creating transaction's time and its failure modes.

## More information

- Related: [ADR-0005](0005-multi-tenancy.md), [ADR-0009](0009-async-messaging-and-outbox.md), [ADR-0032](0032-module-call-edges-follow-modules-catalogue.md), [modules.md](../02-architecture/modules.md#how-modules-talk), [P02.T2](../05-plan/phase-02-projects-and-workflow.md#p02t2--five-groups-and-core-steps-), FR-WF-01, FR-WF-02.
- Revisit when: more than 3 modules implement `OrganizationSetupStep`, or creating an organization takes more than 500 ms at p95.
