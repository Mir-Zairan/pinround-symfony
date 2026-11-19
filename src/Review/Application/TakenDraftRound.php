<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\Review\Api\ScreenshotWithPins;
use App\SharedKernel\Domain\TicketId;

/**
 * What DraftRoundContents::take took out of a draft round: the ticket it was on and its pictures.
 */
final readonly class TakenDraftRound
{
    /**
     * @param list<ScreenshotWithPins> $screenshots by position, each with its media id, title, summary and pins by number
     */
    public function __construct(
        public TicketId $ticketId,
        public array $screenshots,
    ) {
    }
}
