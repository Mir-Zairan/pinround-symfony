<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\Review\Api\TicketRounds;
use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Api\TicketVisibility;

/**
 * RoundQueries for a caller that has already built the viewer's ticket visibility, as the ticket page has, so the
 * viewer's roles and the triager are not read a second time (two queries fewer). Review's UI uses it because the Api
 * may see only SharedKernel and so cannot take a TicketVisibility.
 */
interface RoundsVisibleTo
{
    /**
     * The same rounds, cap and queries as RoundQueries::forTicket for the viewer the visibility belongs to, less the
     * reads of their roles and of the triager.
     *
     * @throws \LogicException           when no transaction is open or no tenant is set, before any query
     * @throws \InvalidArgumentException when $viewer is of another organization than the tenant, before any query
     */
    public function forVisibleTo(TicketId $ticketId, TicketVisibility $viewer): TicketRounds;
}
