<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TicketId;

/**
 * The pin progress of a page of tickets. Review implements it, so a page outside Review shows "1 of 2 pins OK" without
 * depending on it. It runs in the caller's open transaction under the organization as tenant, under row-level
 * security, and does not check visibility: pass only tickets the viewer may see.
 */
interface PinProgressQueries
{
    public const int MAX_TICKET_IDS = 100;

    /**
     * One query, none for an empty $ticketIds. A ticket's current checklist is that of its open shared round with the
     * highest number among those that have check items: its checklist round, or a findings round shared after it. A
     * ticket with no such round, or of another organization, is left out.
     *
     * @param list<TicketId> $ticketIds at most MAX_TICKET_IDS
     *
     * @return array<string, PinProgress> by ticket id string
     *
     * @throws \InvalidArgumentException when $ticketIds holds more than MAX_TICKET_IDS ids, before any query
     * @throws \LogicException           when no transaction is open or no tenant is set, before any query
     */
    public function pinProgressOf(array $ticketIds): array;
}
