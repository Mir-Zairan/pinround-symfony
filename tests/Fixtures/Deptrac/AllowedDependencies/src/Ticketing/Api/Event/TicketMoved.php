<?php

declare(strict_types=1);

namespace App\Ticketing\Api\Event;

final readonly class TicketMoved
{
    public function __construct(public string $key)
    {
    }
}
