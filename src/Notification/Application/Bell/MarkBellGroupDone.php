<?php

declare(strict_types=1);

namespace App\Notification\Application\Bell;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibility;

/**
 * Done on one group of the viewer's bell: their open notifications from one sender about one ticket. A notification
 * keeps its ticket after the viewer leaves the ticket's team, so the group changes only while Ticketing finds the ticket
 * visible to them (ADR-0013). Runs in the request transaction under the tenant.
 */
final readonly class MarkBellGroupDone
{
    public function __construct(private TicketQueries $ticketQueries, private BellInbox $bellInbox)
    {
    }

    /**
     * @return int how many it marked done; 0 when the group has none open or the viewer may no longer see the ticket,
     *             and then nothing changed
     *
     * @throws \App\SharedKernel\Application\TenantNotSet when no tenant is set, before any query
     * @throws \LogicException                            when no transaction is open
     */
    public function markDone(TicketVisibility $viewer, UserId $senderId, TicketId $ticketId, \DateTimeImmutable $now): int
    {
        if ([] === $this->ticketQueries->visibleTicketIdsOf($viewer, [$ticketId])) {
            return 0;
        }

        return $this->bellInbox->markGroupDone(new BellGroupKey($viewer->viewerId(), $senderId, $ticketId), $now);
    }
}
