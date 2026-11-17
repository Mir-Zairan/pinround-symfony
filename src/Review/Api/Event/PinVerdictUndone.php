<?php

declare(strict_types=1);

namespace App\Review\Api\Event;

/**
 * A reviewer cleared a pin's verdict in a review round, so it can be given again. Ticketing writes it into the
 * ticket's What happened and Notification refreshes the ticket's open pages; open round pages will use it to update
 * the pin.
 */
final readonly class PinVerdictUndone implements ReviewEvent
{
    /**
     * @param string $roundId       the review round the verdict was undone in
     * @param string $pinId         the rvw_pin id of the earlier round's pin
     * @param string $undoneVerdict VerdictCommands::PIN_FIXED or PIN_STILL_WRONG, the verdict the pin had
     */
    public function __construct(
        public string $organizationId,
        public string $ticketId,
        public string $roundId,
        public string $pinId,
        public string $undoneVerdict,
        public string $actorId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
