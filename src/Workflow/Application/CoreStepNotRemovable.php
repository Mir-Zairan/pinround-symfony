<?php

declare(strict_types=1);

namespace App\Workflow\Application;

/**
 * Core steps are never removed.
 */
final class CoreStepNotRemovable extends \DomainException
{
}
