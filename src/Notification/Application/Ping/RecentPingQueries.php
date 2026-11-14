<?php

declare(strict_types=1);

namespace App\Notification\Application\Ping;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Whom a sender pinged about a ticket lately, for the ticket page's "Pinged · again in N h".
 */
interface RecentPingQueries
{
    /**
     * Two queries: the organization's ping limit, then the newest ping of $fromUserId about $ticketId to each person
     * within that many hours before $at. Both read under row-level security, so they need an open transaction whose
     * tenant is $organizationId; under any other tenant, or none, nobody was pinged and the limit is the default.
     *
     * @throws \LogicException when the stored ping limit is not one of 1, 4, 8 or 24
     */
    public function recentPingsOf(OrganizationId $organizationId, UserId $fromUserId, TicketId $ticketId, \DateTimeImmutable $at): RecentPings;
}
