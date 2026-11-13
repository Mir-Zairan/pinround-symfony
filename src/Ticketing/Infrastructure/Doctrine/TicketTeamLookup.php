<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\Organization\Api\MemberJob;
use App\Organization\Api\PossibleAssignee;
use App\Organization\Api\TeamPeopleQueries;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\StepQueries;
use App\Workflow\Api\StepView;

/**
 * What a ticket's turn needs from its team, read one way for the turn query and the ticket page: the core code of its
 * step among the team's steps, and the team's lead and people from the team people cache.
 */
final readonly class TicketTeamLookup
{
    public function __construct(
        private StepQueries $stepQueries,
        private TeamPeopleQueries $teamPeopleQueries,
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
     * No query when the team's people are cached. A developer or tester Identity does not know is left out. The cache
     * is shared, so a caller that saves the answer can save people a SetTeamRole has just changed (the window is in
     * TicketTurnQueries::factsOf).
     *
     * @return array{UserId, string, list<UserId>, list<UserId>} the lead, their display name, the developers and the
     *                                                           testers, each in user id order
     *
     * @throws \LogicException when the team has no lead or member Identity knows, or is not a team of the organization;
     *                         or Identity does not know its lead
     */
    public function peopleOf(TeamId $teamId): array
    {
        $people = $this->teamPeopleQueries->namedPossibleAssigneesOf($teamId);
        if ([] === $people) {
            throw new \LogicException('A ticket\'s team has no lead or member Identity knows, or is not a team of its organization.');
        }
        $lead = array_find($people, static fn (PossibleAssignee $person): bool => $person->isLead)
            ?? throw new \LogicException('The lead of a ticket\'s team is no user.');

        return [$lead->userId, $lead->displayName, self::userIdsWithJob($people, MemberJob::Developer), self::userIdsWithJob($people, MemberJob::Tester)];
    }

    /**
     * @param list<PossibleAssignee> $people
     *
     * @return list<UserId>
     */
    private static function userIdsWithJob(array $people, MemberJob $job): array
    {
        return array_values(array_map(
            static fn (PossibleAssignee $person): UserId => $person->userId,
            array_filter($people, static fn (PossibleAssignee $person): bool => $person->job === $job),
        ));
    }
}
