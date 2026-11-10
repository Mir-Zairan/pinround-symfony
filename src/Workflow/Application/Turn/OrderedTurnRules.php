<?php

declare(strict_types=1);

namespace App\Workflow\Application\Turn;

use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\Turn;
use App\Workflow\Api\TurnRules;
use App\Workflow\Domain\CoreStep;
use App\Workflow\Domain\Group;

/**
 * The turn rules in the order of workflow-engine.md, "Whose turn"; the first match wins.
 *
 * A step's own reviewer or tester is used when the ticket has one; without one the choice is made here, so a ticket
 * in Code review or Testing always has someone's turn.
 */
final readonly class OrderedTurnRules implements TurnRules
{
    public function __construct(
        private ReviewerChoice $reviewerChoice,
        private TesterChoice $testerChoice,
    ) {
    }

    public function turnFor(TicketState $ticket, TeamFacts $team): Turn
    {
        $step = $this->coreStepOf($ticket);

        if (null !== $ticket->askedUserId) {
            return new Turn($ticket->askedUserId, null, NeedsYouKind::Answer);
        }

        if ($ticket->isCheckNeeded && null === $ticket->assigneeId && null !== $ticket->checkerId) {
            return new Turn($ticket->checkerId, null, NeedsYouKind::Check);
        }

        if (CoreStep::Done === $step || Group::Done->value === $ticket->groupCode) {
            return Turn::nobody();
        }

        if (CoreStep::Backlog === $step) {
            return new Turn($team->leadId, null, NeedsYouKind::Build);
        }

        if (CoreStep::Hold === $step) {
            return new Turn($team->leadId, null, NeedsYouKind::Decide);
        }

        if (CoreStep::Review === $step) {
            return $this->reviewTurn($ticket, $team);
        }

        if (CoreStep::Test === $step || Group::Check->value === $ticket->groupCode) {
            $tester = null === $ticket->testerId ? $this->testerChoice->choose($team)->userId : $ticket->testerId;

            return new Turn($tester, null, NeedsYouKind::Test);
        }

        if (CoreStep::Input === $step) {
            return $this->inputTurn($ticket, $team);
        }

        $person = $ticket->assigneeId ?? $team->leadId;
        $kind = CoreStep::Todo === $step && null === $ticket->assigneeId ? NeedsYouKind::Assign : NeedsYouKind::Build;

        return new Turn($person, null, $kind);
    }

    private function reviewTurn(TicketState $ticket, TeamFacts $team): Turn
    {
        if (null !== $ticket->reviewerId) {
            return new Turn($ticket->reviewerId, null, NeedsYouKind::Review);
        }

        $reviewer = $this->reviewerChoice->choose($ticket->assigneeId, $team);

        return new Turn($reviewer->userId, null, NeedsYouKind::Review, $reviewer->overrideNote);
    }

    private function inputTurn(TicketState $ticket, TeamFacts $team): Turn
    {
        if (null !== $ticket->waitingOnUserId) {
            return new Turn($ticket->waitingOnUserId, null, NeedsYouKind::Input);
        }

        if (null !== $ticket->waitingOnLabel) {
            return new Turn(null, $ticket->waitingOnLabel, NeedsYouKind::Input);
        }

        return new Turn($ticket->reporterId ?? $team->leadId, null, NeedsYouKind::Input);
    }

    private function coreStepOf(TicketState $ticket): ?CoreStep
    {
        if (null === $ticket->coreCode) {
            return null;
        }

        return CoreStep::tryFrom($ticket->coreCode)
            ?? throw new \InvalidArgumentException('A ticket in a core step must have one of the eight core codes.');
    }
}
