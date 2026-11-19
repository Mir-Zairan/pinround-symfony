<?php

declare(strict_types=1);

namespace App\Ticketing\Api\Event;

/**
 * A ticket was reported: through the team ticket form in a project its team owns, or through Report a problem, where
 * the reporter may pick no team ("Not sure").
 */
final readonly class TicketCreated implements TicketEvent
{
    public function __construct(
        public string $organizationId,
        public string $ticketId,
        public string $projectId,
        /** Null for a ticket reported with no team. */
        public ?string $teamId,
        public string $reporterId,
        public ?string $assigneeId,
    ) {
    }
}
