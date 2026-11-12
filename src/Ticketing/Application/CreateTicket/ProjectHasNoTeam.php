<?php

declare(strict_types=1);

namespace App\Ticketing\Application\CreateTicket;

/**
 * No team owns the project, so a ticket in it would have no team, no steps of its own and nobody whose turn it is.
 */
final class ProjectHasNoTeam extends \DomainException
{
}
