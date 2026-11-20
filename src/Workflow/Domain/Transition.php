<?php

declare(strict_types=1);

namespace App\Workflow\Domain;

/**
 * One row of the transition catalogue (workflow-engine.md): the button a person presses, the steps it leaves and the
 * one it goes to, and who may press it.
 */
final readonly class Transition
{
    /**
     * @param string         $label          the button text, for example "Plan it"
     * @param list<CoreStep> $from           the core steps the ticket may be in
     * @param CoreStep|null  $to             the core step it goes to; for the "go on" verbs the one it goes to when
     *                                       there is no previous step; null when the target depends on the ticket's
     *                                       group (Move on to…), see MoveSideEffects
     * @param list<Mover>    $movers         who may use it, at least one
     * @param bool           $isPrimary      the row's default: true for a filled button, false for an outline one;
     *                                       the policy fills at most the first primary verb it offers
     * @param bool           $fromCustomStep whether it also leaves a company or team step (one without a core code)
     *                                       that is not in the Done group
     * @param bool           $explainsBlock  false for a verb a rule hides without a note under the buttons (Send to
     *                                       testing); check() still gives the note
     *
     * @throws \InvalidArgumentException when it leaves no step at all or $movers is empty
     */
    public function __construct(
        public Verb $verb,
        public string $label,
        public array $from,
        public ?CoreStep $to,
        public array $movers,
        public bool $isPrimary,
        public AssigneeInTodo $assigneeInTodo = AssigneeInTodo::Any,
        public bool $fromCustomStep = false,
        public bool $explainsBlock = true,
    ) {
        if ([] === $from && !$fromCustomStep) {
            throw new \InvalidArgumentException('A transition leaves at least one step.');
        }

        if ([] === $movers) {
            throw new \InvalidArgumentException('A transition has at least one mover.');
        }
    }

    /**
     * Whether only the team's lead may use it ("Plan it", "Go on", "Put on hold"). Every other verb is a move-on verb,
     * which closes the ticket's open shared round when the person whose turn it is uses it (ADR-0048).
     */
    public function isLeadOnly(): bool
    {
        return [Mover::Lead] === $this->movers;
    }

    public function startsFrom(CoreStep $step): bool
    {
        return \in_array($step, $this->from, true);
    }

    /**
     * Whether the verb can be used from this step: one of its from steps, and in To do with or without an assignee as
     * its assigneeInTodo says.
     */
    public function appliesIn(CoreStep $step, bool $hasAssignee): bool
    {
        if (!$this->startsFrom($step)) {
            return false;
        }

        if (CoreStep::Todo !== $step) {
            return true;
        }

        return match ($this->assigneeInTodo) {
            AssigneeInTodo::Any => true,
            AssigneeInTodo::Nobody => !$hasAssignee,
            AssigneeInTodo::Someone => $hasAssignee,
        };
    }

    /**
     * Whether the verb can be used from a company or team step in this group; none leaves the Done group.
     */
    public function appliesInCustomStep(Group $group): bool
    {
        return $this->fromCustomStep && Group::Done !== $group;
    }
}
