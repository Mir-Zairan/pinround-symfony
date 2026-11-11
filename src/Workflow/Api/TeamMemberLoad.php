<?php

declare(strict_types=1);

namespace App\Workflow\Api;

use App\SharedKernel\Domain\UserId;

/**
 * One team member and how much they carry now, for the reviewer and tester choice.
 */
final readonly class TeamMemberLoad
{
    /**
     * @param int $openReviews      tickets in Code review waiting for this person; counts for developers
     * @param int $ticketsInTesting tickets in Testing waiting for this person; counts for testers
     */
    public function __construct(
        public UserId $userId,
        public int $openReviews = 0,
        public int $ticketsInTesting = 0,
    ) {
    }
}
