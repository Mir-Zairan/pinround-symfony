<?php

declare(strict_types=1);

namespace App\Workflow\Application;

/**
 * The team a team step is added for is not a team of the organization.
 */
final class StepTeamNotFound extends \DomainException
{
}
