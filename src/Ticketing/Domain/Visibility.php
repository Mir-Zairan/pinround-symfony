<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

/**
 * Who may see a ticket: everyone in the organization, or only its team (and the people on the ticket).
 */
enum Visibility: string
{
    case All = 'all';
    case Team = 'team';
}
