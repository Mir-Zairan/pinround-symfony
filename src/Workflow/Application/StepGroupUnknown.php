<?php

declare(strict_types=1);

namespace App\Workflow\Application;

/**
 * The group code is not one of todo, doing, check, wait or done.
 */
final class StepGroupUnknown extends \InvalidArgumentException
{
}
