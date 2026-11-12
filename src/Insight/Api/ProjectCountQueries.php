<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;

/**
 * The project rail's counts, read from ins_project_counts and never counted from tickets on page load.
 */
interface ProjectCountQueries
{
    /**
     * The counts of each project the team owns, for one viewer of the transaction's tenant: every ticket for everyone,
     * and a team-only ticket only when the viewer has a role in that ticket's own team (which may differ from the
     * project's team after the project moved) or is an admin. That undercounts for a viewer who may see a team-only
     * ticket for another reason, such as its outside reporter, who may see more in the list than in the rail; it never
     * counts a ticket the viewer may not see. A ticket with no team is counted nowhere. Three queries: the team's
     * projects, the viewer's roles (Ticketing's TicketVisibilities) and the counts.
     *
     * @param bool $withDone whether to read the done counts too, for "Show done"
     *
     * @return list<ProjectCounts> one per project of the team, by project key; zeros for a project with no ticket;
     *                             empty when the team owns none or is unknown or of another organization
     *
     * @throws \LogicException when no transaction is open, before any query
     */
    public function countsOfTeam(TeamId $teamId, UserId $viewerId, bool $withDone): array;

    /**
     * The counts of the given projects as countsOfTeam counts them, for a caller that already holds the viewer's roles
     * and the projects: one query, none when $projectIds is empty.
     *
     * @param list<string> $projectIds    project uuids; one of another organization, or unknown, counts zero
     * @param bool         $viewerIsAdmin whether the viewer is an admin of the transaction's tenant, who counts every ticket
     * @param list<TeamId> $viewerTeamIds the teams the viewer has any role in, whose team-only tickets they count
     * @param bool         $withDone      whether to read the done counts too, for "Show done"
     *
     * @return list<ProjectCounts> one per asked project, in the asked order
     *
     * @throws \InvalidArgumentException when a project id is not a uuid, before any query
     * @throws \LogicException           when no transaction is open, before any query
     */
    public function countsOfProjects(array $projectIds, bool $viewerIsAdmin, array $viewerTeamIds, bool $withDone): array;
}
