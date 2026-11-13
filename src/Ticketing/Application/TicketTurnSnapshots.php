<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\Ticketing\Domain\Ticket;

/**
 * The turn snapshot of a ticket the caller has loaded and changed, so the turn row is refreshed from the ticket as it is
 * about to be saved without reading it back. It equals what TicketTurnQueries::factsOf reads once the ticket is saved.
 */
interface TicketTurnSnapshots
{
    /**
     * Its step and team people come from the same caches as factsOf, so a warm call runs no query; a step its team no
     * longer lists costs one. The team people window of factsOf applies.
     *
     * @throws \LogicException when no transaction is open, the ticket is not of the tenant's organization, the
     *                         organization has no such step, or the ticket's team has no lead Identity knows or is no
     *                         team of the organization
     */
    public function of(Ticket $ticket): TicketTurnSnapshot;
}
