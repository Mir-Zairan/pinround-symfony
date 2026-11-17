<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

/**
 * Where a ticket's summaries are kept: one current summary per ticket, and every one it replaced as history.
 */
interface TicketSummaries
{
    /**
     * Makes the summary its ticket's current one and moves the one before to history, in the caller's transaction
     * under the organization of TenantContext. It holds the ticket's row until that transaction ends, so of two stores
     * for one ticket the second waits for the first and then replaces it: never two current summaries.
     *
     * @return bool false, with nothing written, when the organization has no such ticket
     *
     * @throws \App\SharedKernel\Application\TenantNotSet when no tenant is set, before any query
     * @throws \LogicException                            when no transaction is open, before any query
     */
    public function storeCurrent(Summary $summary): bool;
}
