<?php

declare(strict_types=1);

namespace App\Workflow\Application;

/**
 * A team step was added while the organization's step policy is locked, under which no team has steps of its own.
 */
final class TeamStepsLocked extends \DomainException
{
}
