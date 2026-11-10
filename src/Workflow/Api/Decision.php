<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * Whether one person may use one verb on one ticket now. An allowed decision carries where the ticket goes; a refusal
 * carries no target and may carry the rule note that says why. On every move the step being left becomes the ticket's
 * previous_step_id (see MoveTarget).
 */
final readonly class Decision
{
    private function __construct(public bool $isAllowed, public ?RuleNote $ruleNote, public ?MoveTarget $moveTarget)
    {
    }

    public static function allowed(MoveTarget $moveTarget): self
    {
        return new self(true, null, $moveTarget);
    }

    /**
     * @param RuleNote|null $ruleNote null when no rule explains it, for example a verb that does not apply in the ticket's step
     */
    public static function refused(?RuleNote $ruleNote): self
    {
        return new self(false, $ruleNote, null);
    }
}
