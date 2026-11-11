<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\Organization\Api\TeamQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;

/**
 * Checks, through Organization's TeamQueries, that a team which is to own a project is one of the organization's.
 */
final readonly class OrganizationTeams
{
    public function __construct(private TeamQueries $teamQueries)
    {
    }

    /**
     * @throws ProjectTeamNotFound when the team is not a team of the organization
     */
    public function assertTeamOf(OrganizationId $organizationId, TeamId $teamId): void
    {
        $isTeamOfTheOrganization = array_any(
            $this->teamQueries->teamsOf($organizationId),
            static fn (TeamId $candidate): bool => $candidate->equals($teamId),
        );
        if (!$isTeamOfTheOrganization) {
            throw new ProjectTeamNotFound('That team is not in this organization.');
        }
    }
}
