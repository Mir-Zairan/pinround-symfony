<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

interface TicketQueries
{
    public function titleOf(string $key): string;
}
