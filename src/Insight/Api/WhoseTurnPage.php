<?php

declare(strict_types=1);

namespace App\Insight\Api;

/**
 * One page of the Whose turn view's cards (HomeQueries::whoseTurn).
 */
final readonly class WhoseTurnPage
{
    /**
     * @param list<TurnGroup>      $groups              the viewer's own group first on the first page, then at most
     *                                                  HomeQueries::GROUPS_PER_PAGE others, the oldest wait first
     * @param int                  $ticketCount         every ticket the viewer may see, of every group, on this page or not
     * @param TurnGroupCursor|null $nextCursor          null when no group follows the last one
     * @param bool                 $isTicketCountCapped true when a search found more tickets than the tickets list's cap, of which only
     *                                                  the best count, so $ticketCount and the groups are a lower bound
     */
    public function __construct(
        public array $groups,
        public int $ticketCount,
        public ?TurnGroupCursor $nextCursor,
        public bool $isTicketCountCapped = false,
    ) {
    }
}
