<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\Ticketing\Api\TicketQueries;

final readonly class ShowTicket
{
    public function __construct(private TicketQueries $tickets)
    {
    }

    public function titleOf(string $key): string
    {
        return $this->tickets->titleOf($key);
    }
}
