<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * Whether one person may use one verb on one ticket now; a refusal may carry the rule note that says why.
 */
final readonly class Decision
{
    private function __construct(public bool $isAllowed, public ?RuleNote $ruleNote)
    {
    }

    public static function allowed(): self
    {
        return new self(true, null);
    }

    /**
     * @param RuleNote|null $ruleNote null when no rule explains it, for example a verb that does not apply in the ticket's step
     */
    public static function refused(?RuleNote $ruleNote): self
    {
        return new self(false, $ruleNote);
    }
}
