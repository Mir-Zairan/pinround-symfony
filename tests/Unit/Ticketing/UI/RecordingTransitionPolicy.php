<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI;

use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\Availability;
use App\Workflow\Api\Decision;
use App\Workflow\Api\MoveChoices;
use App\Workflow\Api\RuleSetView;
use App\Workflow\Api\StepView;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\TransitionPolicy;

/**
 * The real policy, keeping the person each question was asked for, so a test sees the team role and job it was given.
 */
final class RecordingTransitionPolicy implements TransitionPolicy
{
    /** @var list<ActorInTeam> */
    public array $askedActors = [];

    public function __construct(private readonly TransitionPolicy $policy)
    {
    }

    public function available(TicketState $ticket, ActorInTeam $actor, RuleSetView $rules, TeamFacts $team): Availability
    {
        $this->askedActors[] = $actor;

        return $this->policy->available($ticket, $actor, $rules, $team);
    }

    public function check(TicketState $ticket, ActorInTeam $actor, RuleSetView $rules, TeamFacts $team, string $verb): Decision
    {
        $this->askedActors[] = $actor;

        return $this->policy->check($ticket, $actor, $rules, $team, $verb);
    }

    public function moveChoices(TicketState $ticket, ActorInTeam $actor, array $steps): MoveChoices
    {
        $this->askedActors[] = $actor;

        return $this->policy->moveChoices($ticket, $actor, $steps);
    }

    public function checkMoveTo(TicketState $ticket, ActorInTeam $actor, RuleSetView $rules, StepView $target, array $steps): Decision
    {
        $this->askedActors[] = $actor;

        return $this->policy->checkMoveTo($ticket, $actor, $rules, $target, $steps);
    }
}
