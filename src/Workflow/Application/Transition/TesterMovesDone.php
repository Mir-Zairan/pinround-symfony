<?php

declare(strict_types=1);

namespace App\Workflow\Application\Transition;

use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\RuleNote;
use App\Workflow\Api\RuleSetView;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TicketState;
use App\Workflow\Domain\CoreStep;
use App\Workflow\Domain\Transition;

/**
 * The rule tester_moves_done: while it is on, only a team member with the job tester moves a ticket to Done. With it
 * off, the lead may pass a ticket too; that is the catalogue's who may, not this rule.
 *
 * A team without a tester gives the Testing turn to the lead (FR-WF-14), so there the rule blocks nobody: otherwise
 * the person whose turn it is could not pass the ticket.
 */
final readonly class TesterMovesDone implements Guard
{
    public function __construct(private MoveRights $moveRights)
    {
    }

    public function check(TicketState $ticket, Transition $transition, ActorInTeam $actor, RuleSetView $rules, TeamFacts $team): ?RuleNote
    {
        if (!$rules->testerMovesDone || CoreStep::Done !== $transition->to || [] === $team->testers || $this->moveRights->isTester($actor)) {
            return null;
        }

        return new RuleNote('Team rule: only the tester moves a ticket to Done.');
    }
}
