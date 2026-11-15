<?php

declare(strict_types=1);

namespace App\Notification\Api\Event;

/**
 * A ping was delivered to its recipient: at once, or at the end of their quiet hours for a held ping, so occurredAt is
 * the delivery time. A ping refused by the rate limit publishes nothing.
 */
final readonly class PingSent implements PingEvent
{
    /**
     * @param string $pingId     the ntf_ping id
     * @param string $fromUserId the person who pinged
     * @param string $toUserId   the person pinged
     * @param string $actorId    the person who pinged, the same as fromUserId
     */
    public function __construct(
        public string $organizationId,
        public string $ticketId,
        public string $pingId,
        public string $fromUserId,
        public string $toUserId,
        public string $actorId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
