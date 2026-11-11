<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Application;

use App\Organization\Api\MemberJob;
use App\Organization\Api\TeamQueries;
use App\Organization\Api\TeamSummary;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;

/**
 * The teams of one organization; any other organization has none.
 */
final class FixedTeamQueries implements TeamQueries
{
    public int $teamsOfCalls = 0;

    /**
     * @param list<TeamId> $teamIds
     */
    public function __construct(private readonly OrganizationId $organizationId, private readonly array $teamIds)
    {
    }

    public function teamsOf(OrganizationId $organizationId): array
    {
        ++$this->teamsOfCalls;

        return $organizationId->equals($this->organizationId) ? $this->teamIds : [];
    }

    public function summariesOf(OrganizationId $organizationId, array $teamIds): array
    {
        throw new \LogicException('Only teamsOf is expected.');
    }

    public function allSummariesOf(OrganizationId $organizationId): array
    {
        throw new \LogicException('Only teamsOf is expected.');
    }

    public function countOf(OrganizationId $organizationId): int
    {
        throw new \LogicException('Only teamsOf is expected.');
    }

    public function bySlug(OrganizationId $organizationId, string $slug): ?TeamSummary
    {
        throw new \LogicException('Only teamsOf is expected.');
    }

    public function jobOf(TeamId $teamId, UserId $userId): ?MemberJob
    {
        throw new \LogicException('Only teamsOf is expected.');
    }

    public function leadOf(TeamId $teamId): ?UserId
    {
        throw new \LogicException('Only teamsOf is expected.');
    }

    public function peopleByJob(TeamId $teamId): array
    {
        throw new \LogicException('Only teamsOf is expected.');
    }
}
