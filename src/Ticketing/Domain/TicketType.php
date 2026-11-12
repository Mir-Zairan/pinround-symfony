<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

enum TicketType: string
{
    case Bug = 'bug';
    case Story = 'story';
    case Task = 'task';
}
