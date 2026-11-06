<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

final readonly class Ticket
{
    public function __construct(public string $key)
    {
    }
}
