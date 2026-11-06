<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\Ticketing\Domain\Ticket;

final readonly class ShowTicket
{
    public function keyOf(Ticket $ticket): string
    {
        return $ticket->key;
    }
}
