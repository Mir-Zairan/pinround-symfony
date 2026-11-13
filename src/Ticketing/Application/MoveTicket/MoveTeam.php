<?php

declare(strict_types=1);

namespace App\Ticketing\Application\MoveTicket;

use App\Organization\Api\PossibleAssignee;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\MoveRefused;
use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\MoveTarget;
use App\Workflow\Api\StepView;

/**
 * The steps and people of the moved ticket's team, read once per move, so the snapshots before and after, the actor's
 * team role and the target step all come from one read of each cache.
 */
final readonly class MoveTeam
{
    /**
     * @param list<StepView>         $steps  StepQueries::stepsFor of the team
     * @param list<PossibleAssignee> $people TeamPeopleQueries::namedPossibleAssigneesOf of the team
     */
    public function __construct(
        public array $steps,
        public array $people,
    ) {
    }

    /**
     * A core target is the organization's step of that code; a step target, such as the step the ticket came from, must
     * still be one of the team's steps, as a removed step takes no new moves.
     *
     * @throws MoveRefused when the team has no such step
     */
    public function targetStepOf(MoveTarget $target): StepView
    {
        return array_find(
            $this->steps,
            static fn (StepView $step): bool => $target->isCoreStep() ? $step->coreCode() === $target->coreCode : $step->id() === $target->stepId,
        ) ?? throw new MoveRefused(null);
    }

    /**
     * @param string $stepId a step uuid in any letter case
     *
     * @throws MoveRefused when the step is not one of the team's
     */
    public function stepOfId(string $stepId): StepView
    {
        return array_find($this->steps, static fn (StepView $step): bool => strtolower($stepId) === $step->id())
            ?? throw new MoveRefused(null);
    }

    /**
     * Whether the person is the team's lead or a member, who may be given its tickets; viewers are not listed.
     */
    public function hasPossibleAssignee(UserId $userId): bool
    {
        return array_any($this->people, static fn (PossibleAssignee $person): bool => $person->userId->equals($userId));
    }

    /**
     * Org roles grant no move, and viewers are not listed, so both get no team role.
     */
    public function actorInTeam(UserId $actor): ActorInTeam
    {
        $person = array_find($this->people, static fn (PossibleAssignee $person): bool => $person->userId->equals($actor));
        if (null === $person) {
            return new ActorInTeam($actor);
        }

        return new ActorInTeam($actor, $person->isLead ? ActorInTeam::TEAM_ROLE_LEAD : ActorInTeam::TEAM_ROLE_MEMBER, $person->job->value);
    }
}
