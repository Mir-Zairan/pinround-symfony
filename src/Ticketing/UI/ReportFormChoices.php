<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Organization\Api\ActorContext;
use App\Organization\Api\MemberJob;
use App\Organization\Api\PossibleAssignee;
use App\Organization\Api\TeamPeopleQueries;
use App\Organization\Api\TeamQueries;
use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Api\AssigneeTicketCounts;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\TeamProjectChoice;
use App\Ticketing\Api\TicketVisibilities;

/**
 * The choices of Report a problem's "Where it goes" (report-a-problem.md): every team that owns a project, the
 * reporter's own teams first; for an own team, "Give it to" up to four people who are not testers, those with the fewest
 * open tickets first. Reads under row-level security: one query for the teams, one for the projects, one for the
 * counts, and the cached team people (no query on a hit) of the reporter's own teams only.
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
                $others[] = new ReportTeamChoice($summary->id(), $summary->name(), false, null, [], $projects);
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
        $everyone = [];
        foreach ($ownTeams as [$summary]) {
            // Cached per team (CachedTeamPeopleQueries), so this costs no query on a hit; a reporter is in few teams.
            $peopleByTeam[$summary->id()->toString()] = $this->teamPeopleQueries->namedPossibleAssigneesOf($summary->id());
            foreach ($peopleByTeam[$summary->id()->toString()] as $person) {
                $everyone[$person->userId->toString()] = $person->userId;
            }
        }
        $counts = $this->assigneeTicketCounts->openTicketsAssignedTo($this->ticketVisibilities->of($actor->organizationId(), $actor->userId()), array_values($everyone), null);

        $choices = [];
        foreach ($ownTeams as [$summary, $projects]) {
            $people = $peopleByTeam[$summary->id()->toString()];
            $lead = array_values(array_filter($people, static fn (PossibleAssignee $person): bool => $person->isLead))[0] ?? null;
            $candidates = array_values(array_filter($people, static fn (PossibleAssignee $person): bool => MemberJob::Tester !== $person->job));
            usort($candidates, static fn (PossibleAssignee $first, PossibleAssignee $second): int => [$counts[$first->userId->toString()] ?? 0, $first->displayName, $first->userId->toString()] <=> [$counts[$second->userId->toString()] ?? 0, $second->displayName, $second->userId->toString()]);
            $giveTo = array_map(
                static fn (PossibleAssignee $person): array => ['id' => $person->userId->toString(), 'label' => $person->userId->equals($actor->userId()) ? $person->firstName.' (you)' : $person->firstName],
                \array_slice($candidates, 0, self::MAX_GIVE_TO),
            );
            $choices[] = new ReportTeamChoice($summary->id(), $summary->name(), true, $lead?->firstName, $giveTo, $projects);
        }

        return $choices;
    }
}
