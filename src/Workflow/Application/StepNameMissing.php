<?php

declare(strict_types=1);

namespace App\Workflow\Application;

/**
 * Nothing is left of the step's name after trimming. In the Application, not the Domain, so the step form may catch it
 * and show its message.
 */
final class StepNameMissing extends \InvalidArgumentException
{
}
