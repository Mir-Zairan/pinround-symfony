<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

/**
 * The project has no owning team, so there is nothing to unlink.
 */
final class ProjectNotLinked extends \DomainException
{
}
