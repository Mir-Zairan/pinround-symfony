<?php

declare(strict_types=1);

namespace App\Notification\Api\Event;

/**
 * An open ping was cleared, by its recipient clicking "Done" or by acting on its ticket. A ping archived after 30 days
 * is not cleared and publishes nothing.
 */
final readonly class PingCleared implements PingEvent
{
    /**
     * @param string $pingId     the ntf_ping id
     * @param string $fromUserId the person who pinged
     * @param string $toUserId   the person pinged
     * @param string $actorId    the person who cleared it, always the recipient
     */
    public function __construct(
        public string $organizationId,
        public string $ticketId,
        public string $pingId,
        public string $fromUserId,
        public string $toUserId,
        public PingClearedReason $reason,
        public string $actorId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
