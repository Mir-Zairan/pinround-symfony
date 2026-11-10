# Ticketing

Tickets from the first report to done: keys, moves, assignment, lead checks and duplicate links. It owns the tables `tkt_project`, `tkt_ticket`, `tkt_ticket_event`, `tkt_duplicate_link` and `tkt_summary`. Its Api is `TicketQueries` and `TicketCommands`, plus its public events. It may use the Api of Workflow, Organization, Media and Insight. It implements Workflow's `StepOccupancy` (`Infrastructure\Workflow\TicketStepOccupancy`), so Workflow learns how many open tickets are in a step without calling Ticketing; it returns 0 until P03 adds tickets. The Team page's project actions are its POST routes `ticketing_team_projects_unlink`, `ticketing_team_projects_link` and `ticketing_team_projects_create` under `/teams/{slug}/projects` (`UI\TeamProjectsController`); each runs in a transaction of its own through the port `Application\TicketingTransactions`.

See the [module catalogue](../../docs/02-architecture/modules.md#ticketing-tkt_).
