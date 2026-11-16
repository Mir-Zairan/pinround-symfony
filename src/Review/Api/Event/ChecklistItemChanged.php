<?php

declare(strict_types=1);

namespace App\Review\Api\Event;

/**
 * Someone marked an item of a ticket's checklist round fixed, confirmed it OK or sent it back as not fixed (P05.T6).
 * It never moves the ticket and carries no pin note (ADR-0027). No module consumes it yet; the ticket page and the
 * activity log will.
 */
final readonly class ChecklistItemChanged implements ReviewEvent
{
    /**
     * @param string $roundId the checklist round of the item
     * @param string $itemId  the rvw_check_item id
     * @param string $state   the item's new state: ChecklistCommands::STATE_FIXED, STATE_OK or STATE_NOT_FIXED
     */
    public function __construct(
        public string $organizationId,
        public string $ticketId,
        public string $roundId,
        public string $itemId,
        public string $state,
        public string $actorId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
