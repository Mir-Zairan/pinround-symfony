<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Domain\UserId;

/**
 * A lead or member of a team, who can be a ticket's assignee, with their display name, its first word, their job in the
 * team and whether they are the team's lead.
 */
final readonly class PossibleAssignee
{
    public function __construct(
        public UserId $userId,
        public string $firstName,
        public string $displayName,
        public MemberJob $job,
        public bool $isLead,
    ) {
    }
}
