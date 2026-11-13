<?php

declare(strict_types=1);

namespace App\Ticketing\Api\Event;

/**
 * A ticket was given to a person with "Give it to…"; the TicketMoved of the same give comes with it.
 */
final readonly class TicketAssigned implements TicketEvent
{
    /**
     * @param string      $key                the ticket's key, for example "SHOP-12"
     * @param string|null $previousAssigneeId the person the ticket was given to before, or null when it had nobody
     * @param string      $assigneeId         the person the ticket is given to now
     */
    public function __construct(
        public string $organizationId,
        public string $ticketId,
        public string $key,
        public ?string $previousAssigneeId,
        public string $assigneeId,
        public string $actorId,
        public \DateTimeImmutable $assignedAt,
    ) {
    }
}
