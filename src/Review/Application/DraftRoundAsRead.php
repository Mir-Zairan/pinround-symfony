<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\Review\Api\ScreenshotWithPins;
use App\SharedKernel\Domain\TicketId;

/**
 * What DraftRoundContents::contentsOf read of a draft round: its id, the ticket it is on, the version it was read at
 * and its pictures.
 */
final readonly class DraftRoundAsRead
{
    /**
     * @param list<ScreenshotWithPins> $screenshots by position, each with its media id, title, summary and pins by number
     */
    public function __construct(
        public string $roundId,
        public TicketId $ticketId,
        public int $version,
        public array $screenshots,
    ) {
    }
}
