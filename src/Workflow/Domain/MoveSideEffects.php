<?php

declare(strict_types=1);

namespace App\Workflow\Domain;

/**
 * Where a verb takes the ticket, as data (FR-WF-16). The step being left always becomes the ticket's previous step;
 * that is not computed here.
 */
final readonly class MoveSideEffects
{
    /**
     * The core step the ticket goes to, or null when it goes back to its previous step. Null only comes back for
     * "Input given · go on", "Go on" and a "Move on to…" from a Waiting step, and only when there is a previous step;
     * without one they fall back to To do, In progress and In progress. Every other verb goes to its row's `to`
     * ("Give it to…" from Backlog goes to To do, as it does from To do).
     *
     * @param Group|null $customStepGroup the group of the company or team step the ticket is in; null in a core step
     *
     * @throws \LogicException when the verb is "Move on to…" and the ticket is not in a company or team step outside
     *                         Done
     */
    public function coreTargetOf(Transition $transition, ?Group $customStepGroup, bool $hasPreviousStep): ?CoreStep
    {
        return match ($transition->verb) {
            Verb::InputGiven, Verb::GoOn => $hasPreviousStep ? null : $this->rowTarget($transition),
            Verb::MoveOn => $this->nextCoreStep($customStepGroup, $hasPreviousStep),
            default => $this->rowTarget($transition),
        };
    }

    private function rowTarget(Transition $transition): CoreStep
    {
        return $transition->to ?? throw new \LogicException(\sprintf('The verb "%s" has no fixed target step.', $transition->verb->value));
    }

    private function nextCoreStep(?Group $group, bool $hasPreviousStep): ?CoreStep
    {
        return match ($group) {
            Group::Todo => CoreStep::Progress,
            Group::Doing => CoreStep::Review,
            Group::Check => CoreStep::Test,
            Group::Wait => $hasPreviousStep ? null : CoreStep::Progress,
            Group::Done, null => throw new \LogicException('"Move on to…" leaves a company or team step outside the Done group.'),
        };
    }
}
