<?php

declare(strict_types=1);

namespace App\Audit\Infrastructure;

use App\Ticketing\Api\Event\TicketMoved;

final readonly class RecordTicketMoved
{
    public function keyOf(TicketMoved $event): string
    {
        return $event->key;
    }
}
