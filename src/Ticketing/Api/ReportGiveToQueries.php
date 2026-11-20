<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;

/**
 * The "Give it to" choice of Report a problem, for another module's page that sends a report (a report draft's Send).
 */
interface ReportGiveToQueries
{
    /**
     * Reads under the caller's tenant: the reporter's roles, the team's cached people and their open ticket counts as
     * the reporter may see them.
     *
     * @return ReportGiveTo|null null when $reporter has no role in the team, as only someone in it gives a report to a
     *                           person; the report then goes to the team
     */
    public function giveToOf(OrganizationId $organizationId, UserId $reporter, TeamId $teamId): ?ReportGiveTo;
}
