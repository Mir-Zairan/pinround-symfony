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
final readonly class FixedTeamQueries implements TeamQueries
{
    /**
     * @param list<TeamId> $teamIds
     */
    public function __construct(private OrganizationId $organizationId, private array $teamIds)
    {
    }

    public function teamsOf(OrganizationId $organizationId): array
    {
        return $organizationId->equals($this->organizationId) ? $this->teamIds : [];
    }

    public function summariesOf(OrganizationId $organizationId, array $teamIds): array
    {
        throw new \LogicException('CreateProject reads no team summaries.');
    }

    public function allSummariesOf(OrganizationId $organizationId): array
    {
        throw new \LogicException('CreateProject reads no team summaries.');
    }

    public function countOf(OrganizationId $organizationId): int
    {
        throw new \LogicException('CreateProject counts no teams.');
    }

    public function bySlug(OrganizationId $organizationId, string $slug): ?TeamSummary
    {
        throw new \LogicException('CreateProject reads no team summaries.');
    }

    public function jobOf(TeamId $teamId, UserId $userId): ?MemberJob
    {
        throw new \LogicException('CreateProject reads no jobs.');
    }
}
