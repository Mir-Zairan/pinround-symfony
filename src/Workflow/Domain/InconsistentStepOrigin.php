<?php

declare(strict_types=1);

namespace App\Workflow\Domain;

/**
 * The origin, core code, team and group of a step do not fit together.
 */
final class InconsistentStepOrigin extends \InvalidArgumentException
{
}
