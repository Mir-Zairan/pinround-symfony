<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Domain\UserId;

/**
 * A lead or member of a team, who can be a ticket's assignee, with the first word of their display name.
 */
final readonly class PossibleAssignee
{
    public function __construct(public UserId $userId, public string $firstName)
    {
    }
}
