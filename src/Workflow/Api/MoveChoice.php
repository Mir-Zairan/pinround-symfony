<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * One step in the "Move to another step…" list.
 */
final readonly class MoveChoice
{
    /**
     * @param bool $isCurrent true for the step the ticket is in: shown pressed, not a move
     */
    public function __construct(public StepView $step, public bool $isCurrent)
    {
    }
}
