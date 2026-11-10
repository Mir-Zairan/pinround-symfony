<?php

declare(strict_types=1);

namespace App\Ticketing\Application\CreateProject;

/**
 * The team that should own the project is not a team of the organization.
 */
final class ProjectTeamNotFound extends \DomainException
{
}
