<?php

declare(strict_types=1);

namespace App\Ticketing\Api\Event;

/**
 * A ticket was reported in a project its team owns.
 */
final readonly class TicketCreated implements TicketEvent
{
    public function __construct(
        public string $organizationId,
        public string $ticketId,
        public string $projectId,
        public string $teamId,
        public string $reporterId,
        public ?string $assigneeId,
    ) {
    }
}
