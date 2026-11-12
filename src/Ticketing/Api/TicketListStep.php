<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * One step of the tickets list with a page of its rows.
 */
final readonly class TicketListStep
{
    public const int PAGE_SIZE = 50;

    /**
     * The most tickets a step counts, so a big step never reads all its rows; past it the page shows "1,000+".
     */
    public const int COUNT_CAP = 1000;

    /**
     * @param string              $stepId        the step's uuid
     * @param int                 $ticketCount   the step's tickets the viewer may see, on any page, at most COUNT_CAP
     * @param bool                $isCountCapped true when the step holds more than COUNT_CAP of them, or the search hit
     *                                           its cap of COUNT_CAP matches across all steps
     * @param list<TicketListRow> $rows          at most PAGE_SIZE, in list order
     * @param Cursor|null         $nextCursor    null when no row follows the last one
     */
    public function __construct(
        public string $stepId,
        public string $name,
        public bool $isArchived,
        public int $ticketCount,
        public bool $isCountCapped,
        public array $rows,
        public ?Cursor $nextCursor,
    ) {
    }

    public function hasMore(): bool
    {
        return null !== $this->nextCursor;
    }
}
