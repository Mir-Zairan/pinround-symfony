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
     * @param string              $stepId      the step's uuid
     * @param int                 $ticketCount every ticket of the step the viewer may see, on any page
     * @param list<TicketListRow> $rows        at most PAGE_SIZE, in list order
     * @param Cursor|null         $nextCursor  null when no row follows the last one
     */
    public function __construct(
        public string $stepId,
        public string $name,
        public bool $isArchived,
        public int $ticketCount,
        public array $rows,
        public ?Cursor $nextCursor,
    ) {
    }

    public function hasMore(): bool
    {
        return null !== $this->nextCursor;
    }
}
