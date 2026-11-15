# Review

Review rounds with screenshots, pins and verdicts, including private draft rounds that are shared later. It owns the tables `rvw_round`, `rvw_screenshot`, `rvw_pin` and `rvw_check_item`.

## Depends on

- Ticketing Api: `TicketTurnQueries`, to read a ticket's reporter and visibility.
- Media Api: the upload and `MediaTargetAuthorizer` contracts.
- Organization Api: the current actor and the roles that decide who may add to a round.

## What it has now

- Domain: `Round` (report and draft, sharing a draft), `Screenshot`, `Pin`, their ids and enums, and `RoundSlotTaken`. `Rounds` is the repository port; `DbalRounds` implements it.
- Api: `RoundCommands` (`createReport`), `RoundQueries`, and `RoundRefused` for content the domain refuses (422).
- Application: `UseCaseRoundCommands`, and `RoundUploadTargets`, which tells Media about a round.
- UI: the screenshot and pin components, and `RoundMediaTarget`, its `MediaTargetAuthorizer` for kind `round`: Media asks it who may add an upload to a round.

Not built yet: checklist and review rounds, verdicts, AI pins and the check items.

See the [module catalogue](../../docs/02-architecture/modules.md#review-rvw_).
