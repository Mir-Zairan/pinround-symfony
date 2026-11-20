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
    /**
     * @param bool $isLeadOnlyMove true for an allowed verb only the team's lead may use ("Plan it", "Go on", "Put on
     *                             hold") and for "Move to another step…"; such a move never closes a shared round
     *                             (ADR-0048). False for a move-on verb and for every refusal.
     */
    private function __construct(public bool $isAllowed, public ?RuleNote $ruleNote, public ?MoveTarget $moveTarget, public bool $isLeadOnlyMove = false)
    {
    }

    /**
     * @param bool $isLeadOnlyMove see the constructor
     */
    public static function allowed(MoveTarget $moveTarget, bool $isLeadOnlyMove = false): self
    {
        return new self(true, null, $moveTarget, $isLeadOnlyMove);
    }

    /**
     * @param RuleNote|null $ruleNote null when no rule explains it, for example a verb that does not apply in the ticket's step
     */
    public static function refused(?RuleNote $ruleNote): self
    {
        return new self(false, $ruleNote, null);
    }
}
