<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\Review\Api\PlacedPin;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundRefused;
use App\Review\Api\ScreenshotWithPins;
use App\Review\Domain\Pin;
use App\Review\Domain\Round;
use App\Review\Domain\Rounds;
use App\Review\Domain\RoundSlotTaken;
use App\Review\Domain\Screenshot;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketTurnQueries;

/**
 * RoundCommands on the Round aggregate; the contract is on {@see RoundCommands}.
 */
final readonly class UseCaseRoundCommands implements RoundCommands
{
    public function __construct(
        private Rounds $rounds,
        private TicketTurnQueries $ticketTurnQueries,
        private TenantContext $tenantContext,
    ) {
    }

    public function createReport(TicketId $ticketId, array $screenshots, UserId $reporterId, \DateTimeImmutable $reportedAt): string
    {
        $organizationId = $this->tenantContext->require();
        // Before any query, so a list sized from outside is never walked past the cap.
        if (\count($screenshots) > Round::MAX_SCREENSHOTS) {
            throw new RoundRefused(\sprintf('A round holds at most %d screenshots.', Round::MAX_SCREENSHOTS));
        }
        $ticket = $this->ticketTurnQueries->factsOf($ticketId) ?? throw new TicketNotFound('That ticket does not exist.');
        if (!$ticket->reporterId->equals($reporterId)) {
            throw new RoundRefused('Only the person who reported the ticket can add its report.');
        }

        try {
            $round = Round::report(
                $this->rounds->nextIdentity(),
                $organizationId,
                $ticketId,
                $reporterId,
                array_map(
                    fn (ScreenshotWithPins $screenshot, int $position): Screenshot => $this->screenshotOf($screenshot, $position, $reporterId),
                    $screenshots,
                    array_keys($screenshots),
                ),
                $reportedAt,
            );
        } catch (\InvalidArgumentException $invalidContent) {
            throw new RoundRefused($invalidContent->getMessage(), 0, $invalidContent);
        }

        try {
            $this->rounds->add($round);
        } catch (RoundSlotTaken $taken) {
            throw new RoundRefused('This ticket has a report already.', 0, $taken);
        }

        return $round->id()->toString();
    }

    /**
     * @throws \InvalidArgumentException when the screenshot or one of its pins breaks a rule of the domain
     */
    private function screenshotOf(ScreenshotWithPins $screenshot, int $position, UserId $authorId): Screenshot
    {
        if (\count($screenshot->pins) > Screenshot::MAX_PINS) {
            throw new \InvalidArgumentException(\sprintf('A picture holds at most %d pins.', Screenshot::MAX_PINS));
        }

        return Screenshot::of(
            $this->rounds->nextScreenshotIdentity(),
            $screenshot->mediaId,
            $position,
            $screenshot->title,
            $screenshot->summary,
            array_map(
                fn (PlacedPin $pin, int $index): Pin => Pin::placedBy($this->rounds->nextPinIdentity(), $index + 1, $pin->xPercent, $pin->yPercent, $pin->note, $authorId),
                $screenshot->pins,
                array_keys($screenshot->pins),
            ),
        );
    }
}
