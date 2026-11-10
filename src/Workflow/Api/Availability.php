<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * The verb buttons one person sees on one ticket, and the rule note under them.
 */
final readonly class Availability
{
    /**
     * @param list<TransitionView> $transitions in the order the buttons are shown
     * @param RuleNote|null        $ruleNote    why a move is not offered, or null when there is nothing to explain
     */
    public function __construct(public array $transitions, public ?RuleNote $ruleNote)
    {
    }
}
