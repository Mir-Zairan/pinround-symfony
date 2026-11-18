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

    /**
     * As storeCurrent, when the ticket's current summary is still the one made at $replacedCreatedAt, checked while
     * the ticket's row is held, so a summary stored meanwhile is never replaced unseen.
     *
     * @param \DateTimeImmutable|null $replacedCreatedAt when the current summary the person saw was made; null when
     *                                                   they saw none
     *
     * @return bool false, with nothing written, when the organization has no such ticket or its current summary is
     *              another one
     *
     * @throws \App\SharedKernel\Application\TenantNotSet when no tenant is set, before any query
     * @throws \LogicException                            when no transaction is open, before any query
     */
    public function storeCurrentInPlaceOf(Summary $summary, ?\DateTimeImmutable $replacedCreatedAt): bool;
}
