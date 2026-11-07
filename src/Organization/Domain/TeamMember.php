<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TenantOwned;
use App\SharedKernel\Domain\UserId;

/**
 * A person's team role and job in one team. Part of the Team aggregate: only Team creates or changes one, so the
 * team always has exactly one lead.
 */
final class TeamMember implements TenantOwned
{
    private readonly string $organizationId;
    private readonly string $userId;

    /**
     * @internal built by Team only
     */
    public function __construct(private readonly Team $team, UserId $userId, private TeamRole $teamRole, private readonly Job $job, private readonly \DateTimeImmutable $createdAt)
    {
        $this->organizationId = $team->organizationId()->toString();
        $this->userId = $userId->toString();
    }

    public function organizationId(): OrganizationId
    {
        return new OrganizationId($this->organizationId);
    }

    public function teamId(): TeamId
    {
        return $this->team->id();
    }

    public function userId(): UserId
    {
        return new UserId($this->userId);
    }

    public function teamRole(): TeamRole
    {
        return $this->teamRole;
    }

    public function job(): Job
    {
        return $this->job;
    }

    /**
     * When the person joined the team.
     */
    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isLead(): bool
    {
        return TeamRole::Lead === $this->teamRole;
    }

    /**
     * @internal changed by Team only
     */
    public function changeTeamRole(TeamRole $teamRole): void
    {
        $this->teamRole = $teamRole;
    }
}
