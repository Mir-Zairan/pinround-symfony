<?php

declare(strict_types=1);

namespace App\Notification\Application\Mute;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * The tickets people muted in the tenant's organization, changed under row-level security. Every method needs a tenant
 * and throws TenantNotSet before any query without one, and an open transaction, throwing \LogicException without one.
 */
interface Mutes
{
    /**
     * @return bool true when the ticket was not muted for the person before; a second mute keeps the first
     */
    public function mute(UserId $userId, TicketId $ticketId, \DateTimeImmutable $at): bool;

    /**
     * @return bool true when a mute of the ticket for the person was removed
     */
    public function unmute(UserId $userId, TicketId $ticketId): bool;
}
