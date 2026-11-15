# Review

Review rounds with screenshots, pins and verdicts, including private draft rounds that are shared later. It owns the tables `rvw_round`, `rvw_screenshot`, `rvw_pin` and `rvw_check_item`.

## Depends on

- Ticketing Api: `TicketTurnQueries` (a ticket's reporter, to check who may add its report), `TicketQueries`, `TicketVisibilities`, `TicketVisibility` (the viewer's, built by `RoundsController` and taken by `RoundsVisibleTo`) and `TicketVisibilityFacts` (what a person may see), and `TicketPermission`. Who may add an upload to a round is its draft owner, or a person with VIEW and EDIT on the ticket.
- Media Api: `MediaTargetAuthorizer`, which `UI\Media\RoundMediaTarget` implements, and `SignedUrls`, which signs the pictures of the Rounds section.
- Organization Api: `CurrentActor`, the request's actor, and `MemberNameQueries`, the names of the rounds' owners.

## What it has now

- Domain: `Round` (report and draft, sharing a draft), `Screenshot`, `Pin`, their ids and enums, and `RoundSlotTaken`. `Rounds` is the repository port; `DbalRounds` implements it.
- Api: `RoundCommands` (`createReport`), `RoundQueries` (`forTicket` returns `TicketRounds`), and `RoundRefused` for a report that is refused (422): content the domain refuses, someone who is not the reporter, or a ticket that already has a report.
- Application: `UseCaseRoundCommands`, `RoundsVisibleTo` (`RoundQueries` for a caller that has built the viewer's `TicketVisibility`; `DbalRoundQueries` implements both), and `RoundUploadTargets`, the read port `UI\Media\RoundMediaTarget` uses to read a round as an upload target; `Infrastructure\Doctrine\DbalRoundUploadTargets` implements it. Media never calls it.
- UI: the Rounds section of the ticket page (`Component\RoundsSection`, a heading and a lazy Turbo frame, embedded by name; `RoundsController` at `/t/{key}/rounds` fills the frame with the cards `RoundCards` builds: Round 0, the newest 20 numbered rounds and the viewer's own draft, at most 10 pictures a card), the screenshot and pin components, and `RoundMediaTarget`, its `MediaTargetAuthorizer` for kind `round`: Media asks it who may add an upload to a round.

Not built yet: checklist and review rounds, verdicts, AI pins and the check items.

See the [module catalogue](../../docs/02-architecture/modules.md#review-rvw_).
