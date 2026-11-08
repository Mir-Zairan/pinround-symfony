<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use App\SharedKernel\Domain\UserId;

/**
 * A read-only copy of one member of a Team: changing a team role goes through the Team, which keeps one lead.
 */
final readonly class TeamMemberView
{
    public function __construct(public UserId $userId, public TeamRole $teamRole, public Job $job, public \DateTimeImmutable $joinedAt)
    {
    }
}
