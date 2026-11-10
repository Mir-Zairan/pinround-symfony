<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * The "Move to another step…" list one person sees on one ticket, and the rule note under the buttons when it is empty
 * for a reason a person should read.
 */
final readonly class MoveChoices
{
    /**
     * @param list<MoveChoiceGroup> $groups   empty for anyone but the team lead and while a question is open
     * @param RuleNote|null         $ruleNote the managers' note or the pause note, as Availability gives it; null when
     *                                        the list is shown or the person is a member who just does not lead
     */
    public function __construct(public array $groups, public ?RuleNote $ruleNote)
    {
    }
}
