# Workflow

The steps, the five groups, the rules and the moves a ticket may make. It does not store tickets: Ticketing owns the current step and asks Workflow before every move. It owns the tables `wf_step`, `wf_rule_set` and `wf_transition`. Its Api is `StepQueries`, `TransitionPolicy` and `TurnRules`. It may use the Api of Organization.

See the [module catalogue](../../docs/02-architecture/modules.md#workflow-wf_).
