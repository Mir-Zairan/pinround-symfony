<?php

declare(strict_types=1);

namespace App\Review\Api\Event;

/**
 * A reviewer gave a pin of the earlier round its verdict in a review round. It never moves the ticket. No module
 * consumes it yet; open round pages will use it to update the pin.
 */
final readonly class PinVerdictSet implements ReviewEvent
{
    /**
     * @param string $roundId the review round the verdict was given in
     * @param string $pinId   the rvw_pin id of the earlier round's pin
     * @param string $verdict VerdictCommands::PIN_FIXED or PIN_STILL_WRONG
     */
    public function __construct(
        public string $organizationId,
        public string $ticketId,
        public string $roundId,
        public string $pinId,
        public string $verdict,
        public string $actorId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
