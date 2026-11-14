<?php

declare(strict_types=1);

namespace App\Notification\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * What other modules may read of a person's notifications. It reads the organization of TenantContext under row-level
 * security, so it needs a tenant; it finds nothing of another organization.
 */
interface NotificationQueries
{
    /**
     * Whether the person muted the ticket (ntf_mute): they get no notifications or emails about it, and it still shows
     * in their lists. One query by the mute's primary key.
     *
     * @throws \App\SharedKernel\Application\TenantNotSet when no tenant is set, before any query
     */
    public function isMuted(UserId $userId, TicketId $ticketId): bool;
}
