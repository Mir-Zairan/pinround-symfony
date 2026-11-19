<?php

declare(strict_types=1);

namespace App\Ticketing\Application\CreateTicket;

/**
 * Another team than the one a report picked owns the project, so the ticket would sit in a team that does not own it.
 */
final class ProjectNotOfTeam extends \DomainException
{
}
