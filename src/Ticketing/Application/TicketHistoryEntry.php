<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\SharedKernel\Domain\UserId;
use App\Ticketing\Application\MoveTicket\MoveTicket;

/**
 * One row of tkt_ticket_event for the ticket page's What happened: a move ("moved" row), or a checklist or verdict
 * change of the ticket's rounds, which carries its ReviewChange and no steps.
 */
final readonly class TicketHistoryEntry
{
    /**
     * @param string|null       $fromStepId   the wf_step id the ticket left; a give in To do leaves and enters the same step
     * @param string|null       $toStepId     the wf_step id the ticket entered; null exactly for a review change
     * @param string|null       $verb         the Workflow verb code the person clicked, or null for a lead's "Move to another step…"
     * @param UserId|null       $givenToId    the person a give named; null for any other move, or a payload with no valid id
     * @param ReviewChange|null $reviewChange the checklist or verdict change; null for a move
     *
     * @throws \InvalidArgumentException when the entry is neither a move into a step nor a review change, or both
     */
    public function __construct(
        public UserId $actorId,
        public ?string $fromStepId,
        public ?string $toStepId,
        public ?string $verb,
        public ?UserId $givenToId,
        public \DateTimeImmutable $at,
        public ?ReviewChange $reviewChange = null,
    ) {
        if ((null === $toStepId) === (null === $reviewChange)) {
            throw new \InvalidArgumentException('A ticket history entry is either a move into a step or a review change.');
        }
    }

    public function isGive(): bool
    {
        return MoveTicket::GIVE_IT_TO === $this->verb;
    }
}
