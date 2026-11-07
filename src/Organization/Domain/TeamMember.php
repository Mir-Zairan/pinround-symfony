<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use App\SharedKernel\Domain\TenantOwned;
use App\SharedKernel\Domain\UserId;

/**
 * A person's team role and job in one team. Part of the Team aggregate: only Team creates or changes one, and it
 * hands out TeamMemberView copies, so the team always has exactly one lead.
 *
 * @internal used by Team and its mapping only
 */
final class TeamMember implements TenantOwned
{
    // The key (organization_id, team_id, user_id). The first two are written through the team association, so the
    // row's foreign key (organization_id, team_id) always names a team of the same organization.
    private readonly string $organizationId;
    private readonly string $teamId;
    private readonly string $userId;

    public function __construct(private readonly Team $team, UserId $userId, private TeamRole $teamRole, private readonly Job $job, private readonly \DateTimeImmutable $createdAt)
    {
        $this->organizationId = $team->organizationId()->toString();
        $this->teamId = $team->id()->toString();
        $this->userId = $userId->toString();
    }

    public function isOf(UserId $userId): bool
    {
        return $this->userId === $userId->toString();
    }

    public function isLead(): bool
    {
        return TeamRole::Lead === $this->teamRole;
    }

    public function teamRole(): TeamRole
    {
        return $this->teamRole;
    }

    public function changeTeamRole(TeamRole $teamRole): void
    {
        $this->teamRole = $teamRole;
    }

    public function view(): TeamMemberView
    {
        return new TeamMemberView(new UserId($this->userId), $this->teamRole, $this->job, $this->createdAt);
    }
}
