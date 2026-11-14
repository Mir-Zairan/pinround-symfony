<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TicketId;

/**
 * The tickets of an email digest as an email may name them (FR-PNG-09): a restricted ticket's title never leaves the
 * database. It reads the organization of TenantContext under row-level security, so it needs an open transaction whose
 * tenant is that organization.
 */
interface TicketDigestQueries
{
    /** The most ticket ids linesOf() takes in one call. */
    public const int MAX_TICKET_IDS = 100;

    /**
     * One query; none for an empty list. It does not check that anyone may see the tickets, so a caller keeps only
     * the ones TicketQueries::visibleTicketIdsOf gave its reader first.
     *
     * @param list<TicketId> $ticketIds at most MAX_TICKET_IDS; a repeated id counts once
     *
     * @return list<DigestTicket> each once, in the order first given; an unknown id and another organization's are
     *                            left out
     *
     * @throws \InvalidArgumentException                  when more than MAX_TICKET_IDS ids are given, before any query
     * @throws \App\SharedKernel\Application\TenantNotSet when no tenant is set, before any query
     * @throws \LogicException                            when no transaction is open, before any query
     */
    public function linesOf(array $ticketIds): array;
}
