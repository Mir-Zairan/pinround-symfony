<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * Which verbs a person may use on a ticket (workflow-engine.md, "Transition catalogue"). Pure: the caller passes the
 * ticket, the person and the team's rules (RuleQueries::rulesFor), and nothing is read from the database.
 */
interface TransitionPolicy
{
    /**
     * Nobody without the team role lead or member in the ticket's team gets a verb, and nobody gets one while a question
     * is open. The note explains a move a rule blocks for this person, also one they could not use anyway, as the ticket
     * page tells the assignee in code review who reviews their code.
     *
     * @throws \InvalidArgumentException when the ticket's core code is not one of the core steps, or its group code is
     *                                   not one of the five groups while it is in a company or team step
     */
    public function available(TicketState $ticket, ActorInTeam $actor, RuleSetView $rules): Availability;

    /**
     * Allowed exactly when available() lists the verb, with the same move target; an unknown verb is refused without a
     * note. A verb a rule hides from available() without a note (Send to testing) is refused here with the rule's note.
     *
     * @param string $verb a verb's stable code, as TransitionView::$verb gives it
     *
     * @throws \InvalidArgumentException when the verb is known and the ticket's core code is not one of the core steps,
     *                                   or its group code is not one of the five groups while it is in a company or
     *                                   team step
     */
    public function check(TicketState $ticket, ActorInTeam $actor, RuleSetView $rules, string $verb): Decision;

    /**
     * The "Move to another step…" list: only the team lead gets it, and not while a question is open; the steps are
     * StepQueries::moveTargetsFor the ticket's team. Grouped in the order the steps come, the ticket's own step marked
     * current. In progress → Testing stays in the list while review_before_test is on and is refused by checkMoveTo.
     *
     * @param list<StepView> $steps StepQueries::moveTargetsFor the ticket's team
     */
    public function moveChoices(TicketState $ticket, ActorInTeam $actor, array $steps): MoveChoices;

    /**
     * Whether the lead may move the ticket to this step with "Move to another step…". Refused for anyone but the lead,
     * while a question is open, to the step the ticket is in, to a step whose id is not in $steps (without a note:
     * another team's step, a removed one, or a team step under the policy locked), and from In progress to Testing
     * while review_before_test is on (with that rule's note).
     *
     * @param list<StepView> $steps StepQueries::moveTargetsFor the ticket's team, the list moveChoices took
     */
    public function checkMoveTo(TicketState $ticket, ActorInTeam $actor, RuleSetView $rules, StepView $target, array $steps): Decision;
}
