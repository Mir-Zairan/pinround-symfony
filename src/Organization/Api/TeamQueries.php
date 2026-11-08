<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;

/**
 * An organization's teams, for other modules. Both reads run under row-level security, so they need an open
 * transaction whose tenant is the organization; under any other tenant, or none, they find nothing.
 */
interface TeamQueries
{
    /**
     * @return list<TeamId> every team of the organization, in id order
     */
    public function teamsOf(OrganizationId $organizationId): array;

    /**
     * One query; a team id that is unknown or belongs to another organization is not returned, and an empty $teamIds
     * runs no query.
     *
     * @param list<TeamId> $teamIds
     *
     * @return list<TeamSummary> the asked teams, ordered by name (case-insensitive), then by id
     */
    public function summariesOf(OrganizationId $organizationId, array $teamIds): array;
}
