# Workflow

The steps, the five groups, the rules and the moves a ticket may make. It does not store tickets: Ticketing owns the current step and asks Workflow before every move. It owns the tables `wf_step`, `wf_rule_set` and `wf_transition`. Its Api is `StepQueries`, `TransitionPolicy` and `TurnRules`. It may use the Api of Organization. Every new organization gets the eight core steps and the default rule set in the transaction that creates it, through `SetUpOrganizationWorkflow` (an `OrganizationSetupStep`); organizations made before that got them from the migration `Version20261110140000`. Built so far of the Api: `StepQueries` (`stepsFor` a team in the groups' list order, then position; `groupOf` a step, removed ones included), implemented with plain SQL by `Infrastructure\Doctrine\DbalStepQueries`.

See the [module catalogue](../../docs/02-architecture/modules.md#workflow-wf_).
