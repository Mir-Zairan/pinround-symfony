<?php

declare(strict_types=1);

namespace App\Insight\Api;

/**
 * One answer of MyTicketQueries::myTicketsOf.
 */
final readonly class MyTickets
{
    /**
     * @param list<MyTicket> $tickets in the order myTicketsOf gives
     * @param bool           $hasMore true when the viewer has more such tickets than the limit let through
     */
    public function __construct(public array $tickets, public bool $hasMore)
    {
    }
}
