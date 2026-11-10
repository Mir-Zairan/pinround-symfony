<?php

declare(strict_types=1);

namespace App\Workflow\Domain;

/**
 * One row of the transition catalogue (workflow-engine.md): the button a person presses, the core steps it leaves
 * and the one it goes to, and who may press it.
 */
final readonly class Transition
{
    /**
     * @param string         $label     the button text, for example "Plan it"
     * @param list<CoreStep> $from      the steps the ticket may be in, at least one
     * @param list<Mover>    $movers    who may use it, at least one
     * @param bool           $isPrimary true for the filled button, false for an outline one
     *
     * @throws \InvalidArgumentException when $from or $movers is empty
     */
    public function __construct(
        public Verb $verb,
        public string $label,
        public array $from,
        public CoreStep $to,
        public array $movers,
        public bool $isPrimary,
        public AssigneeInTodo $assigneeInTodo = AssigneeInTodo::Any,
    ) {
        if ([] === $from) {
            throw new \InvalidArgumentException('A transition leaves at least one step.');
        }

        if ([] === $movers) {
            throw new \InvalidArgumentException('A transition has at least one mover.');
        }
    }

    public function startsFrom(CoreStep $step): bool
    {
        return \in_array($step, $this->from, true);
    }
}
