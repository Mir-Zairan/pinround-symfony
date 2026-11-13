<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Workflow\Api\MoveChoiceGroup;
use App\Workflow\Api\TransitionView;

/**
 * What the "Your next step" box shows one person on one ticket (ticket-page.md, Your next step box).
 */
final readonly class NextStep
{
    /**
     * @param string                $sentence    the line under YOUR NEXT STEP, for example "Test it."
     * @param list<TransitionView>  $transitions the verb buttons, as TransitionPolicy::available orders them
     * @param string|null           $ruleNote    why a move is not offered, with people named; null for none
     * @param list<MoveChoiceGroup> $groups      the "Move to another step…" list; empty for anyone but the lead
     */
    public function __construct(
        public string $sentence,
        public array $transitions,
        public ?string $ruleNote,
        public array $groups,
    ) {
    }
}
