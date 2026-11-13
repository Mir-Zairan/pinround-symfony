<?php

declare(strict_types=1);

namespace App\Web\UI\Team;

use App\Insight\Api\WaitingTurnCounts;
use App\Organization\Api\ActorContext;
use App\Organization\Api\MemberJob;
use App\Organization\Api\PossibleAssignee;
use App\Organization\Api\TeamPeopleQueries;
use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Api\TicketVisibilities;
use App\Web\UI\TicketViewers;

/**
 * The People section of the Team page (FR-ORG-07, FR-ORG-08; prototype "Team: people and workload"): the team's lead
 * and members, each with the open tickets of that team that wait on them, counted only as the viewer may see them
 * (ADR-0013). Viewers are left out, as TeamPeopleQueries leaves them out: it is never their turn. The same reads for
 * any team size: the team people cache, the viewer's visibility and one grouped count.
 */
final readonly class TeamWorkloads
{
    /** The page shows this many people, then "Show all N". */
    public const int SHOWN_AT_FIRST = 6;

    public function __construct(
        private TeamPeopleQueries $teamPeopleQueries,
        private TicketVisibilities $ticketVisibilities,
        private WaitingTurnCounts $waitingTurnCounts,
    ) {
    }

    /**
     * Needs the open transaction of the request, under the viewer's organization as tenant.
     *
     * @throws \LogicException when the count leaves out a person it was asked about
     */
    public function workloadOf(TeamId $teamId, ActorContext $viewer): TeamWorkload
    {
        $people = $this->teamPeopleQueries->namedPossibleAssigneesOf($teamId);
        if ([] === $people) {
            return new TeamWorkload([], 1);
        }

        $visibility = $this->ticketVisibilities->of($viewer->organizationId(), $viewer->userId());
        $counts = $this->waitingTurnCounts->waitingCountsOf(
            TicketViewers::of($visibility),
            array_map(static fn (PossibleAssignee $person) => $person->userId, $people),
            $teamId,
            null,
        );

        $rows = [];
        foreach ($people as $person) {
            $personId = $person->userId->toString();
            $count = $counts[$personId] ?? throw new \LogicException('The waiting counts leave out a person of the team.');
            $rows[] = [
                'personId' => $personId,
                'workload' => new PersonWorkload(
                    $person->displayName,
                    mb_strtolower($person->displayName, 'UTF-8'),
                    self::jobLineOf($person),
                    $person->userId->equals($viewer->userId()),
                    $count->tickets,
                    WorkloadLevel::of($count->tickets),
                ),
            ];
        }
        usort($rows, static fn (array $one, array $other): int => [$other['workload']->waitingTickets, $one['workload']->searchText, $one['personId']]
            <=> [$one['workload']->waitingTickets, $other['workload']->searchText, $other['personId']]);
        $workloads = array_map(static fn (array $row): PersonWorkload => $row['workload'], $rows);

        return new TeamWorkload($workloads, max(1, $workloads[0]->waitingTickets));
    }

    private static function jobLineOf(PossibleAssignee $person): string
    {
        if ($person->isLead) {
            return match ($person->job) {
                MemberJob::Developer => 'Team lead · developer',
                MemberJob::Tester => 'Team lead · tester',
                MemberJob::Other => 'Team lead',
            };
        }

        return match ($person->job) {
            MemberJob::Developer => 'Developer',
            MemberJob::Tester => 'Tester',
            MemberJob::Other => 'Member',
        };
    }
}
