<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * A person's own open unshared draft round of a ticket, found without reading its pictures or pins. It reads under
 * row-level security, so it needs an open transaction whose tenant is the person's organization.
 */
interface OpenDraftQueries
{
    /**
     * $owner's open unshared draft of the ticket as RoundPermission VIEW and EDIT decide on it, in one query on the
     * index that holds at most one such draft per owner and ticket. Another person's draft, a shared, closed or deleted
     * one and another organization's round never come back. Pass the person asking as $owner: this method does not
     * check who asks, nor whether they may still see the ticket; the owner's facts grant RoundPermission without it.
     *
     * @return RoundAccessFacts|null null when $owner has no open unshared draft on the ticket, or the tenant has no such
     *                               ticket
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function openDraftOf(TicketId $ticketId, UserId $owner): ?RoundAccessFacts;
}
