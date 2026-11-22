<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Organization\Api\ActorContext;
use App\Organization\Api\PossibleAssignee;
use App\Organization\Api\TeamPeopleQueries;
use App\Organization\Api\TeamQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\AssigneeTicketCounts;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\ReportGiveTo;
use App\Ticketing\Api\TeamProjectChoice;
use App\Ticketing\Api\TicketVisibilities;

/**
 * The choices of Report a problem's "Where it goes" (report-a-problem.md): every team that owns a project, the
 * reporter's own teams first; for an own team, "Give it to" up to four people who are not testers, those with the fewest
 * open tickets first. Reads under row-level security: one query for the teams, one for the projects, one for the
 * counts, and the cached team people (no query on a hit) of the reporter's own teams only. A report draft's page and
 * Send offer the same choices through ReportWhereItGoesRules.
 */
final readonly class ReportFormChoices
{
    public const int MAX_GIVE_TO = 4;

    public function __construct(
        private TeamQueries $teamQueries,
        private ProjectQueries $projectQueries,
        private TeamPeopleQueries $teamPeopleQueries,
        private AssigneeTicketCounts $assigneeTicketCounts,
        private TicketVisibilities $ticketVisibilities,
    ) {
    }

    /**
     * @return list<ReportTeamChoice> own teams first, then by name; a team with no project is left out, as it takes no
     *                                ticket
     */
    public function teamsFor(ActorContext $actor): array
    {
        $summaries = $this->teamQueries->allSummariesOf($actor->organizationId());
        $projectsByTeam = [];
        foreach ($this->projectQueries->projectChoicesOfTeams(array_map(static fn ($summary): TeamId => $summary->id(), $summaries)) as $project) {
            $projectsByTeam[$project->teamId()->toString()][] = $project;
        }
        $own = [];
        $others = [];
        foreach ($summaries as $summary) {
            $projects = $projectsByTeam[$summary->id()->toString()] ?? [];
            if ([] === $projects) {
                continue;
            }
            if ($actor->roles()->isInTeam($summary->id())) {
                $own[] = [$summary, $projects];
            } else {
                $others[] = new ReportTeamChoice($summary->id(), $summary->name(), null, $projects);
            }
        }

        return [...$this->withPeople($actor, $own), ...$others];
    }

    /**
     * @param list<array{\App\Organization\Api\TeamSummary, non-empty-list<TeamProjectChoice>}> $ownTeams
     *
     * @return list<ReportTeamChoice>
     */
    private function withPeople(ActorContext $actor, array $ownTeams): array
    {
        if ([] === $ownTeams) {
            return [];
        }
        $peopleByTeam = [];
        foreach ($ownTeams as [$summary]) {
            // Cached per team (CachedTeamPeopleQueries), so this costs no query on a hit; a reporter is in few teams.
            $peopleByTeam[$summary->id()->toString()] = $this->teamPeopleQueries->namedPossibleAssigneesOf($summary->id());
        }
        $counts = $this->openTicketCountsOf($actor->organizationId(), $actor->userId(), array_merge(...array_values($peopleByTeam)));

        $choices = [];
        foreach ($ownTeams as [$summary, $projects]) {
            $giveTo = self::giveToAmong($actor->userId(), $peopleByTeam[$summary->id()->toString()], $counts);
            $choices[] = new ReportTeamChoice($summary->id(), $summary->name(), $giveTo, $projects);
        }

        return $choices;
    }

    /**
     * One query for every given person, each counted once.
     *
     * @param list<PossibleAssignee> $people
     *
     * @return array<string, int> by user id
     */
    private function openTicketCountsOf(OrganizationId $organizationId, UserId $reporter, array $people): array
    {
        $everyone = [];
        foreach ($people as $person) {
            $everyone[$person->userId->toString()] = $person->userId;
        }

        return $this->assigneeTicketCounts->openTicketsAssignedTo($this->ticketVisibilities->of($organizationId, $reporter), array_values($everyone), null);
    }

    /**
     * @param list<PossibleAssignee> $people one team's
     * @param array<string, int>     $counts open tickets by user id
     */
    private static function giveToAmong(UserId $reporter, array $people, array $counts): ReportGiveTo
    {
        $candidates = ReportTeamPeople::giveToPeopleOf($people);
        usort($candidates, static fn (PossibleAssignee $first, PossibleAssignee $second): int => [$counts[$first->userId->toString()] ?? 0, $first->displayName, $first->userId->toString()] <=> [$counts[$second->userId->toString()] ?? 0, $second->displayName, $second->userId->toString()]);
        $offered = array_map(
            static fn (PossibleAssignee $person): array => ['id' => $person->userId->toString(), 'label' => $person->userId->equals($reporter) ? $person->firstName.' (you)' : $person->firstName],
            \array_slice($candidates, 0, self::MAX_GIVE_TO),
        );

        return new ReportGiveTo(ReportTeamPeople::leadOf($people)?->firstName, $offered);
    }
}
