<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * Where one ticket sits in a tickets list, in the list's own order: "N of M" and the keys either side of it, for the
 * ticket page's Previous and Next (ticket-page.md, Header). Counts stop where the list's do (TicketListStep::COUNT_CAP
 * per step), so a big list never sorts the whole organization.
 */
final readonly class TicketPosition
{
    /**
     * @param int         $index         1-based; with isIndexCapped, a lower bound: the ticket comes at least that far down
     * @param int         $total         the tickets of the list, each step counting at most TicketListStep::COUNT_CAP
     * @param string|null $previousKey   the ticket before it, across step boundaries; null for the first
     * @param string|null $nextKey       the ticket after it, across step boundaries; null for the last
     * @param bool        $isTotalCapped true when a step holds more than it counts, or the search hit its cap
     * @param bool        $isIndexCapped true when a step before the ticket, or the tickets before it in its own step,
     *                                   hold more than they count
     */
    public function __construct(
        public int $index,
        public int $total,
        public ?string $previousKey,
        public ?string $nextKey,
        public bool $isTotalCapped,
        public bool $isIndexCapped = false,
    ) {
    }
}
