<?php

declare(strict_types=1);

namespace App\Ticketing\Application\LinkProject;

/**
 * The project is linked to a team; it is unlinked first, so it never has two owning teams (FR-ORG-06).
 */
final class ProjectStillLinked extends \DomainException
{
}
