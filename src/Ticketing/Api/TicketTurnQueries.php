<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TicketId;

/**
 * One ticket as Insight's turn projector needs it, for other modules. It reads the organization of TenantContext under
 * row-level security, so it needs an open transaction whose tenant is that organization; under any other tenant it
 * finds nothing.
 */
interface TicketTurnQueries
{
    /**
     * A fixed number of queries whatever the team's size: the ticket, its step (the team's steps are cached), the
     * team's lead, its people by job and the lead's name. A ticket with no team gets no lead, no people and, as only a
     * team's steps say it, no core code.
     *
     * @return TicketTurnSnapshot|null null when the organization has no ticket with this id
     *
     * @throws \LogicException when no tenant is set, before any query; when the ticket's step or team is not the
     *                         organization's, or the lead of its team is no user
     */
    public function factsOf(TicketId $ticketId): ?TicketTurnSnapshot;
}
