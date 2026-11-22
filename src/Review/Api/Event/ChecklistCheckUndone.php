<?php

declare(strict_types=1);

namespace App\Review\Api\Event;

/**
 * A tester took back their check of a checklist item, OK or not fixed, so the item is marked fixed and waits for its
 * check again. It never moves the ticket and carries no pin note (ADR-0027). Ticketing writes it into the ticket's What
 * happened and Notification refreshes the ticket's open pages.
 */
final readonly class ChecklistCheckUndone implements ReviewEvent
{
    /**
     * @param string $roundId     the checklist or findings round of the item
     * @param string $itemId      the rvw_check_item id
     * @param string $undoneState ChecklistCommands::STATE_OK or STATE_NOT_FIXED, the state the check had given the item
     */
    public function __construct(
        public string $organizationId,
        public string $ticketId,
        public string $roundId,
        public string $itemId,
        public string $undoneState,
        public string $actorId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
