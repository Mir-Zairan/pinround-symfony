<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * A ticket's current summary and the newest of the ones it replaced.
 */
final readonly class TicketSummaryHistory
{
    /**
     * @param ?TicketSummaryView      $current      null when the ticket has no summary yet
     * @param list<TicketSummaryView> $earlier      newest first, at most TicketSummaryQueries::EARLIER_LIMIT
     * @param int                     $earlierCount how many earlier summaries the ticket has, also past the limit
     */
    public function __construct(
        public ?TicketSummaryView $current,
        public array $earlier,
        public int $earlierCount,
    ) {
    }
}
