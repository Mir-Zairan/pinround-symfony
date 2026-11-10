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
     * @throws \InvalidArgumentException when the ticket's core code is not one of the core steps
     */
    public function available(TicketState $ticket, ActorInTeam $actor, RuleSetView $rules): Availability;

    /**
     * Allowed exactly when available() lists the verb; an unknown verb is refused without a note.
     *
     * @param string $verb a verb's stable code, as TransitionView::$verb gives it
     *
     * @throws \InvalidArgumentException when the verb is known and the ticket's core code is not one of the core steps
     */
    public function check(TicketState $ticket, ActorInTeam $actor, RuleSetView $rules, string $verb): Decision;
}
