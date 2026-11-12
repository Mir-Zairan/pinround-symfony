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
     * The counts of each project the team owns, for one viewer of the transaction's tenant: a viewer with any role in
     * the team, or an admin, gets every ticket; anyone else gets only the tickets for everyone. That undercounts for a
     * viewer who may see a team-only ticket for another reason, such as its outside reporter, who may see more in the
     * list than in the rail; it never counts a ticket the viewer may not see. Three queries: the team's projects, the
     * viewer's roles (Ticketing's TicketVisibilities) and the counts.
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
