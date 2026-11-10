<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

/**
 * The organization has no project with that id, or the id is not a project id.
 */
final class ProjectNotFound extends \DomainException
{
}
