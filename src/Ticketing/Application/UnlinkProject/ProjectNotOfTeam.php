<?php

declare(strict_types=1);

namespace App\Ticketing\Application\UnlinkProject;

/**
 * The project is not linked to the team that asked to unlink it: another team owns it, or none does.
 */
final class ProjectNotOfTeam extends \DomainException
{
}
