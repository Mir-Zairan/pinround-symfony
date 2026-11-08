<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use App\SharedKernel\Domain\UserId;

/**
 * One person's team role before and after a change in a Team, for the caller to publish.
 */
final readonly class TeamRoleChange
{
    public function __construct(public UserId $userId, public TeamRole $from, public TeamRole $to)
    {
    }
}
