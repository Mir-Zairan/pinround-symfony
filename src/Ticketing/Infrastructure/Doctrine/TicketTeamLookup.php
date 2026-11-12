<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\Organization\Api\TeamQueries;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\StepQueries;
use App\Workflow\Api\StepView;

/**
 * What a ticket's turn needs from its team, read one way for the turn query and the ticket page: the core code of its
 * step among the team's steps, and the team's lead and people.
 */
final readonly class TicketTeamLookup
{
    public function __construct(
        private StepQueries $stepQueries,
        private TeamQueries $teamQueries,
    ) {
    }

    /**
     * @return StepView|null the step among the team's cached steps; null for no team or a step they no longer list
     */
    public function stepOf(string $stepId, ?TeamId $teamId): ?StepView
    {
        return null === $teamId ? null : array_find($this->stepQueries->stepsFor($teamId), static fn (StepView $step): bool => $step->id() === $stepId);
    }

    /**
     * @return array{UserId, list<UserId>, list<UserId>} the lead, the developers and the testers
     *
     * @throws \LogicException when the team is not a team of the organization
     */
    public function peopleOf(TeamId $teamId): array
    {
        $leadId = $this->teamQueries->leadOf($teamId) ?? throw new \LogicException('A ticket\'s team is not a team of its organization.');
        $peopleByJob = $this->teamQueries->peopleByJob($teamId);

        return [$leadId, $peopleByJob['developer'], $peopleByJob['tester']];
    }
}
