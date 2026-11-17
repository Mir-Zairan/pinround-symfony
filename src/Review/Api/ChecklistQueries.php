<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\TicketId;

/**
 * A ticket's checklist round, and one item's facts for CheckItemVoter. It reads under row-level security, so it needs
 * an open transaction whose tenant is the viewer's organization.
 */
interface ChecklistQueries
{
    /**
     * The item as ChecklistPermission decides on it, in one query; who may change it is the voter's to decide.
     *
     * @param string $itemId the id as the request carried it, in any case
     *
     * @return CheckItemAccessFacts|null null for a malformed id, without a query, and for an item the tenant has not
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function accessFactsOf(string $itemId): ?CheckItemAccessFacts;

    /**
     * The ticket's checklist round with all its items, in one query whatever their number (Round 0 caps its pins).
     * It does not check who asks: the caller must have been granted TICKET_VIEW on the ticket first.
     *
     * @return ChecklistView|null null when the tenant's ticket has no checklist round, or the tenant has no such ticket
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function forTicket(TicketId $ticketId): ?ChecklistView;
}
