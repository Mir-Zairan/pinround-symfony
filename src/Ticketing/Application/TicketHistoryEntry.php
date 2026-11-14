<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\SharedKernel\Domain\UserId;
use App\Ticketing\Application\MoveTicket\MoveTicket;

/**
 * One move of a ticket as its "moved" row of tkt_ticket_event holds it, for the ticket page's What happened.
 */
final readonly class TicketHistoryEntry
{
    /**
     * @param string|null $fromStepId the wf_step id the ticket left; a give in To do leaves and enters the same step
     * @param string      $toStepId   the wf_step id the ticket entered
     * @param string|null $verb       the Workflow verb code the person clicked, or null for a lead's "Move to another step…"
     * @param UserId|null $givenToId  the person a give named; null for any other move, or a payload with no valid id
     */
    public function __construct(
        public UserId $actorId,
        public ?string $fromStepId,
        public string $toStepId,
        public ?string $verb,
        public ?UserId $givenToId,
        public \DateTimeImmutable $at,
    ) {
    }

    public function isGive(): bool
    {
        return MoveTicket::GIVE_IT_TO === $this->verb;
    }
}
