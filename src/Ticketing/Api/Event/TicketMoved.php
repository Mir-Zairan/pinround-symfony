<?php

declare(strict_types=1);

namespace App\Ticketing\Api\Event;

/**
 * A ticket was moved from one step of its team to another.
 */
final readonly class TicketMoved implements TicketEvent
{
    /**
     * @param string      $key        the ticket's key, for example "SHOP-12"
     * @param string      $fromStepId the wf_step id the ticket left
     * @param string      $toStepId   the wf_step id the ticket is in now
     * @param string|null $verb       the code of the Workflow verb the person clicked, for example "start_work", or null
     *                                for a lead's "Move to another step…"
     */
    public function __construct(
        public string $organizationId,
        public string $ticketId,
        public string $key,
        public string $fromStepId,
        public string $toStepId,
        public ?string $verb,
        public string $actorId,
        public \DateTimeImmutable $movedAt,
    ) {
    }
}
