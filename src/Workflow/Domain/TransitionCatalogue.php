<?php

declare(strict_types=1);

namespace App\Workflow\Domain;

/**
 * The verbs as data, one row per button of the transition catalogue in workflow-engine.md. Who may still passes the
 * guards: Pass it names the lead, and the tester_moves_done rule blocks the lead while it is on. "Move on to…" has no
 * fixed target and no core from step: it leaves a company or team step, and MoveSideEffects names where it goes.
 */
final readonly class TransitionCatalogue
{
    /**
     * @return list<Transition> in the order the buttons are shown
     */
    public function all(): array
    {
        return [
            new Transition(Verb::PlanIt, 'Plan it', [CoreStep::Backlog], CoreStep::Todo, [Mover::Lead], isPrimary: true),
            new Transition(Verb::GiveItTo, 'Give it to…', [CoreStep::Todo, CoreStep::Backlog], CoreStep::Todo, [Mover::Lead, Mover::Checker], isPrimary: true, assigneeInTodo: AssigneeInTodo::Nobody),
            new Transition(Verb::StartWork, 'Start work', [CoreStep::Todo], CoreStep::Progress, [Mover::Assignee], isPrimary: true, assigneeInTodo: AssigneeInTodo::Someone),
            new Transition(Verb::SendToCodeReview, 'Send to code review', [CoreStep::Progress], CoreStep::Review, [Mover::Assignee, Mover::LeadWhenUnassigned], isPrimary: true),
            new Transition(Verb::SendToTesting, 'Send to testing', [CoreStep::Progress], CoreStep::Test, [Mover::Assignee], isPrimary: false, explainsBlock: false),
            new Transition(Verb::ApproveCode, 'Approve code', [CoreStep::Review], CoreStep::Test, [Mover::Reviewer], isPrimary: true),
            new Transition(Verb::AskForChanges, 'Ask for changes', [CoreStep::Review], CoreStep::Progress, [Mover::Reviewer], isPrimary: false),
            new Transition(Verb::PassIt, 'Pass it', [CoreStep::Test], CoreStep::Done, [Mover::Tester, Mover::Lead], isPrimary: true),
            new Transition(Verb::NeedsChanges, 'Needs changes', [CoreStep::Test], CoreStep::Progress, [Mover::Tester], isPrimary: false),
            new Transition(Verb::InputGiven, 'Input given · go on', [CoreStep::Input], CoreStep::Todo, [Mover::WaitedOn, Mover::Lead], isPrimary: true),
            new Transition(Verb::GoOn, 'Go on', [CoreStep::Hold], CoreStep::Progress, [Mover::Lead], isPrimary: true),
            new Transition(Verb::Reopen, 'Reopen', [CoreStep::Done], CoreStep::Progress, [Mover::Lead, Mover::Tester], isPrimary: false),
            new Transition(Verb::PutOnHold, 'Put on hold', [CoreStep::Backlog, CoreStep::Todo, CoreStep::Progress, CoreStep::Review, CoreStep::Test, CoreStep::Input], CoreStep::Hold, [Mover::Lead], isPrimary: false, fromCustomStep: true),
            new Transition(Verb::MoveOn, 'Move on to…', [], null, [Mover::Assignee, Mover::Lead], isPrimary: true, fromCustomStep: true),
        ];
    }

    public function for(Verb $verb): Transition
    {
        foreach ($this->all() as $transition) {
            if ($transition->verb === $verb) {
                return $transition;
            }
        }

        throw new \LogicException(\sprintf('The catalogue has no row for the verb "%s".', $verb->value));
    }
}
