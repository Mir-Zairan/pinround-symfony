<?php

declare(strict_types=1);

namespace App\Workflow\Application;

/**
 * A new step was put in the Done group, where only the core Done step is. In the Application, not the Domain, so the
 * step form may catch it and show its message.
 */
final class StepInDoneRefused extends \InvalidArgumentException
{
}
