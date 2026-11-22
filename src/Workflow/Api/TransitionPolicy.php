<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * Which verbs a person may use on a ticket (workflow-engine.md, "Transition catalogue"). Pure: the caller passes the
 * ticket, the person, the team's rules (RuleQueries::rulesFor) and the same TeamFacts TurnRules::turnFor takes, and
 * nothing is read from the database. Whoever turnFor gives the turn to with those facts (a team member, while no
 * question is open) gets at least one verb here.
 */
interface TransitionPolicy
{
    /** How long after a move the person who made it may take it back with Undo. */
    public const int UNDO_SECONDS = 30;

    /**
     * Nobody without the team role lead or member in the ticket's team gets a verb, and nobody gets one while a question
     * is open. The note explains a move a rule blocks for this person, also one they could not use anyway, as the ticket
     * page tells the assignee in code review who reviews their code. Without a reviewer or tester on the ticket, the one
     * the turn rules choose from $team is the ticket's reviewer or tester here too.
     *
     * @param TeamFacts $team the ticket's team, as TurnRules::turnFor takes it
     *
     * @throws \InvalidArgumentException when the ticket's core code is not one of the core steps, or its group code is
     *                                   not one of the five groups while it is in a company or team step
     */
    public function available(TicketState $ticket, ActorInTeam $actor, RuleSetView $rules, TeamFacts $team): Availability;

    /**
     * Allowed exactly when available() lists the verb, with the same move target; an unknown verb is refused without a
     * note. A verb a rule hides from available() without a note (Send to testing) is refused here with the rule's note.
     *
     * @param TeamFacts $team the ticket's team, as TurnRules::turnFor takes it
     * @param string    $verb a verb's stable code, as TransitionView::$verb gives it
     *
     * @throws \InvalidArgumentException when the verb is known and the ticket's core code is not one of the core steps,
     *                                   or its group code is not one of the five groups while it is in a company or
     *                                   team step
     */
    public function check(TicketState $ticket, ActorInTeam $actor, RuleSetView $rules, TeamFacts $team, string $verb): Decision;

    /**
     * The "Move to another step…" list: only the team lead gets it, and not while a question is open; the steps are
     * StepQueries::moveTargetsFor the ticket's team. The groups come in their fixed order To do, Doing, Checking,
     * Waiting, Done (ADR-0014), whatever order the steps come in, and a group with no step is left out; inside a group
     * the steps come by position, a tie in the order given. The ticket's own step is marked current. In progress →
     * Testing stays in the list while review_before_test is on and is refused by checkMoveTo.
     *
     * @param list<StepView> $steps StepQueries::moveTargetsFor the ticket's team
     *
     * @throws \InvalidArgumentException when a step's group code is not one of the five groups
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

    /**
     * Undo after a mistaken move (gap list 1.10): the person who made the move may take it back, to the step it left,
     * for UNDO_SECONDS after it, while it is the ticket's last change. Refused, with a note, for anyone else, after
     * UNDO_SECONDS, or once the ticket changed since; without a note for a step that is not in $steps any more; and as
     * every move is for a person with no team role or while a question is open. No other rule is checked: the Undo puts
     * the ticket back where a move the policy allowed found it.
     *
     * @param StepView       $target the step the move left
     * @param list<StepView> $steps  StepQueries::moveTargetsFor the ticket's team
     */
    public function checkUndo(TicketState $ticket, ActorInTeam $actor, TeamFacts $team, MoveToUndo $move, StepView $target, array $steps, \DateTimeImmutable $now): Decision;
}
