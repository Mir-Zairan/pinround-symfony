<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TicketId;

/**
 * A ticket's summaries for a page. It reads the organization of TenantContext under row-level security, so it needs an
 * open transaction whose tenant is that organization.
 */
interface TicketSummaryQueries
{
    /** The most earlier summaries historyOf() returns. */
    public const int EARLIER_LIMIT = 20;

    /**
     * One query. The earlier summaries are ordered newest first, a tie in time by the newer id first.
     *
     * @return ?TicketSummaryHistory null when the viewer may not see the ticket or the organization has no such ticket;
     *                               a visible ticket with no summary has an empty history, not null
     *
     * @throws \App\SharedKernel\Application\TenantNotSet when no tenant is set, before any query
     * @throws \LogicException                            when no transaction is open, before any query
     */
    public function historyOf(TicketId $ticketId, TicketVisibility $visibility): ?TicketSummaryHistory;
}
