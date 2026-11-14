<?php

declare(strict_types=1);

namespace App\Conversation\UI\Voter;

use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketVisibilityFacts;

/**
 * The subject MessageVoter decides on: one message of a ticket's thread, with what the decision reads of it.
 */
final readonly class MessageOnTicket
{
    /**
     * @param UserId|null $teamLeadId the lead of the ticket's team, null when the ticket has no team or the team no lead
     */
    public function __construct(
        public TicketVisibilityFacts $ticket,
        public UserId $authorId,
        public ?UserId $teamLeadId,
    ) {
    }
}
