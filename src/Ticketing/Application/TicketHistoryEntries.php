<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\SharedKernel\Domain\TicketId;

/**
 * The latest moves of one ticket from its history (tkt_ticket_event), for the ticket page's What happened.
 */
interface TicketHistoryEntries
{
    /** How many moves the page shows, before the "created" line it builds from the ticket itself. */
    public const int LATEST_COUNT = 6;

    /**
     * One query, under the organization of the open transaction's tenant. Only moves (gives included): the page words
     * the "created" line from the ticket row, as a ticket written before its history began has no created row.
     *
     * @param \DateTimeImmutable $ticketCreatedAt no row of the ticket is older, so the read skips the months before it
     *
     * @return list<TicketHistoryEntry> at most LATEST_COUNT, newest first, rows of the same time by id, newest first
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function latestOf(TicketId $ticketId, \DateTimeImmutable $ticketCreatedAt): array;
}
