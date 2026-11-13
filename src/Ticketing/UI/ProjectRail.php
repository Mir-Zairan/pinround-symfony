<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Insight\Api\ProjectCountQueries;
use App\Insight\Api\ProjectCounts;
use App\Organization\Api\ActorContext;
use App\Organization\Api\TeamQueries;
use App\Organization\Api\TeamSummary;
use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Api\ProjectChoice;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\TeamProjectChoice;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The tickets page's project rail (tickets-list-and-whose-turn.md, "Project rail"): one team's name, "All projects"
 * with the sum of the team's counts, each project the team owns with its count, "Manage projects", and a switch among
 * the viewer's own teams when they are in two or more. Three reads at most, none for a viewer with no team on "All projects": the team names
 * (TeamQueries::summariesOf), the team's projects (ProjectQueries::projectChoicesOfTeams) and their counts from
 * ins_project_counts with the viewer's roles of this request (ProjectCountQueries::countsOfProjects), none of them
 * per project.
 */
final readonly class ProjectRail
{
    public const string ALL_PROJECTS = 'All projects';
    public const string MANAGE_PROJECTS = 'Manage projects';
    public const string SWITCH_TEAM = 'Switch team';
    public const string OPEN_TICKET = 'open ticket';
    public const string OPEN_TICKETS = 'open tickets';
    public const string TICKET = 'ticket';
    public const string TICKETS = 'tickets';

    public function __construct(
        private TeamQueries $teamQueries,
        private ProjectQueries $projectQueries,
        private ProjectCountQueries $projectCountQueries,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param TeamId|null           $teamId    the team TicketListController::railTeamOf chose, one the viewer may see
     *                                         (TeamPermission::VIEW); null shows "All projects" alone
     * @param array<string, string> $keptQuery the list's other filters (person, done, search), kept by every rail link
     *
     * @return array{teamName: string|null, manageUrl: string|null, allProjectsUrl: string, isAllProjectsPressed: bool, allProjectsCount: array{number: int, label: string}|null, projects: list<array{key: string, name: string, count: array{number: int, label: string}, url: string, isPressed: bool}>, teamSwitch: list<array{name: string, url: string, isCurrent: bool}>}
     */
    public function viewOf(ActorContext $viewer, ?TeamId $teamId, ?ProjectChoice $pickedProject, bool $showsDone, array $keptQuery): array
    {
        $roles = $viewer->roles();
        $isViewerInTeam = null !== $teamId && $roles->isInTeam($teamId);
        $rail = [
            'teamName' => null,
            'manageUrl' => null,
            // A team the viewer is in stays on "All projects"; another team's rail came from its project alone.
            'allProjectsUrl' => $this->listUrl([TicketListContext::TEAM_QUERY => $isViewerInTeam ? $teamId->toString() : null, ...$keptQuery]),
            'isAllProjectsPressed' => null === $pickedProject,
            'allProjectsCount' => null,
            'projects' => [],
            'teamSwitch' => [],
        ];
        if (null === $teamId) {
            return $rail;
        }

        $teamsById = [];
        foreach ($this->teamQueries->summariesOf($viewer->organizationId(), self::uniqueTeamIds([...$roles->teamIds(), $teamId])) as $team) {
            $teamsById[$team->id()->toString()] = $team;
        }
        $team = $teamsById[$teamId->toString()] ?? null;
        if (null === $team) {
            return $rail;
        }
        $rail['teamName'] = $team->name();
        // The viewer may see the team (the caller's TeamPermission::VIEW), which is all its Team page asks.
        $rail['manageUrl'] = $this->urlGenerator->generate(TeamProjectsController::TEAM_PAGE_ROUTE, ['slug' => $team->slug()]);

        $projects = $this->projectQueries->projectChoicesOfTeams([$teamId]);
        $countsByProjectId = [];
        foreach ($this->projectCountQueries->countsOfProjects(array_map(static fn (TeamProjectChoice $project): string => $project->id(), $projects), $roles->isAdmin(), $roles->teamIds(), $showsDone) as $counts) {
            $countsByProjectId[$counts->projectId] = $counts;
        }
        $countOfEachProject = array_map(static fn (TeamProjectChoice $project): int => self::countOf($countsByProjectId[$project->id()] ?? null), $projects);
        $rail['allProjectsCount'] = self::countViewOf(array_sum($countOfEachProject), $showsDone);
        $rail['projects'] = array_map(fn (TeamProjectChoice $project, int $count): array => [
            'key' => $project->key(),
            'name' => $project->name(),
            'count' => self::countViewOf($count, $showsDone),
            'url' => $this->listUrl([TicketListContext::PROJECT_QUERY => $project->key(), ...$keptQuery]),
            'isPressed' => $pickedProject?->id() === $project->id(),
        ], $projects, $countOfEachProject);

        $ownTeams = array_values(array_filter($teamsById, static fn (TeamSummary $ownTeam): bool => $roles->isInTeam($ownTeam->id())));
        if (\count($ownTeams) > 1) {
            $rail['teamSwitch'] = array_map(fn (TeamSummary $ownTeam): array => [
                'name' => $ownTeam->name(),
                'url' => $this->listUrl([
                    TicketListContext::TEAM_QUERY => $ownTeam->id()->toString(),
                    TicketListContext::DONE_QUERY => $keptQuery[TicketListContext::DONE_QUERY] ?? null,
                    TicketListContext::SEARCH_QUERY => $keptQuery[TicketListContext::SEARCH_QUERY] ?? null,
                ]),
                'isCurrent' => $ownTeam->id()->equals($teamId),
            ], $ownTeams);
        }

        return $rail;
    }

    /**
     * Done tickets count only with "Show done", when the read gave their number.
     */
    private static function countOf(?ProjectCounts $counts): int
    {
        return null === $counts ? 0 : $counts->openCount + ($counts->doneCount ?? 0);
    }

    /**
     * The number as shown, and what it counts for a screen reader: open tickets, or all of them with "Show done".
     *
     * @return array{number: int, label: string}
     */
    private static function countViewOf(int $count, bool $showsDone): array
    {
        $isOne = 1 === $count;

        return ['number' => $count, 'label' => $showsDone ? ($isOne ? self::TICKET : self::TICKETS) : ($isOne ? self::OPEN_TICKET : self::OPEN_TICKETS)];
    }

    /**
     * @param list<TeamId> $teamIds
     *
     * @return list<TeamId>
     */
    private static function uniqueTeamIds(array $teamIds): array
    {
        $unique = [];
        foreach ($teamIds as $teamId) {
            $unique[$teamId->toString()] = $teamId;
        }

        return array_values($unique);
    }

    /**
     * @param array<string, string|null> $query null drops a value
     */
    private function listUrl(array $query): string
    {
        return $this->urlGenerator->generate(TicketListController::ROUTE, array_filter($query, static fn (?string $value): bool => null !== $value));
    }
}
