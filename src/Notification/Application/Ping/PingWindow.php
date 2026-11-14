<?php

declare(strict_types=1);

namespace App\Notification\Application\Ping;

use App\Notification\Api\PingsPaused;
use App\Notification\Api\PingTooSoon;
use App\Notification\Domain\PingId;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * One ping per sender, recipient and ticket within the organization's ping limit. A slot is taken before the ping is
 * stored, and given back when storing it fails.
 */
interface PingWindow
{
    /**
     * Takes the slot for this sender, recipient and ticket for $hours from $at, in one step with the check, so two
     * pings at once cannot both pass.
     *
     * @param int $hours the organization's ping limit in hours, at least 1
     *
     * @throws PingTooSoon when the slot is held; it carries the instant the holding ping's slot ends
     * @throws PingsPaused when the store cannot be reached; nothing was taken
     */
    public function take(OrganizationId $organizationId, UserId $fromUserId, UserId $toUserId, TicketId $ticketId, PingId $pingId, int $hours, \DateTimeImmutable $at): void;

    /**
     * Gives the slot back, but only while $pingId still holds it, so a late release never frees a later ping's slot.
     *
     * @throws PingsPaused when the store cannot be reached
     */
    public function release(OrganizationId $organizationId, UserId $fromUserId, UserId $toUserId, TicketId $ticketId, PingId $pingId): void;
}
