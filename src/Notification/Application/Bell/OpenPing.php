<?php

declare(strict_types=1);

namespace App\Notification\Application\Bell;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibility;

/**
 * Open on one group of the viewer's bell: marks their open notifications from one sender about one ticket read. A
 * notification keeps its ticket after the viewer leaves the ticket's team, so the group changes only while Ticketing
 * finds the ticket visible to them (ADR-0013). Runs in the request transaction under the tenant.
 */
final readonly class OpenPing
{
    public function __construct(private TicketQueries $ticketQueries, private BellInbox $bellInbox)
    {
    }

    /**
     * @return string|null the ticket's key to go to; null when the group has none open or the viewer may no longer see
     *                     the ticket, and then nothing changed
     *
     * @throws \App\SharedKernel\Application\TenantNotSet when no tenant is set, before any query
     * @throws \LogicException                            when no transaction is open
     */
    public function open(TicketVisibility $viewer, UserId $senderId, TicketId $ticketId, \DateTimeImmutable $now): ?string
    {
        if ([] === $this->ticketQueries->visibleTicketIdsOf($viewer, [$ticketId])) {
            return null;
        }

        return $this->bellInbox->markGroupRead(new BellGroupKey($viewer->viewerId(), $senderId, $ticketId), $now);
    }
}
