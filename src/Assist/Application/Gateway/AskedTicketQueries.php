<?php

declare(strict_types=1);

namespace App\Assist\Application\Gateway;

use App\Organization\Api\ActorContext;
use App\SharedKernel\Domain\TicketId;

/**
 * The ticket an AI task is about, read for the gateway under the viewer's ticket visibility predicate (ADR-0013).
 */
interface AskedTicketQueries
{
    /**
     * Reads under row-level security, so it needs an open transaction whose tenant is the viewer's organization.
     *
     * @return AskedTicket|null null when the organization has no ticket with that id or the viewer may not see it; the
     *                          two answer the same, so the caller never learns a hidden ticket exists
     */
    public function visibleTicketOf(TicketId $ticketId, ActorContext $viewer): ?AskedTicket;
}
