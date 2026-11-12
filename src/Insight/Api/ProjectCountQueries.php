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
}
