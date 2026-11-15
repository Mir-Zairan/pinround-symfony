<?php

declare(strict_types=1);

namespace App\Notification\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * The ping limit slot a sent ping took: no other ping from this sender to this person about this ticket goes out
 * before againAt. PingCommands::releaseSlot gives it back when the ping's transaction does not commit.
 */
final readonly class PingSlot
{
    /**
     * @param string             $pingId  the id of the ping that holds the slot
     * @param \DateTimeImmutable $againAt the first instant the sender may ping this person about this ticket again
     */
    public function __construct(
        public OrganizationId $organizationId,
        public UserId $fromUserId,
        public UserId $toUserId,
        public TicketId $ticketId,
        public string $pingId,
        public \DateTimeImmutable $againAt,
    ) {
    }
}
