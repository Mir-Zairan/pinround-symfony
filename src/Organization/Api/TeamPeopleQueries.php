<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Domain\TeamId;

/**
 * A team's people with their names, for a page that shows them on every load. Kept in a cache per team and cleared
 * after the commit of any change of the team's people or of one of their names (Infrastructure\Cache\TeamPeopleCache);
 * a miss reads under row-level security, so it needs an open transaction whose tenant is the team's organization.
 */
interface TeamPeopleQueries
{
    /**
     * No query when cached; a miss costs two (the team's people, then their names). Viewers are left out, as they
     * cannot move tickets, and so is a person Identity does not know. Empty for a team that is unknown or belongs to
     * another organization.
     *
     * @return list<PossibleAssignee> the team's lead and members, in user id order
     */
    public function namedPossibleAssigneesOf(TeamId $teamId): array;
}
