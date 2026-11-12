<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Identity\Api\UserQueries;
use App\Organization\Api\PossibleAssignee;
use App\Organization\Api\TeamPeopleQueries;
use App\SharedKernel\Domain\TeamId;

/**
 * The uncached read behind CachedTeamPeopleQueries: the team's people, then their names from Identity.
 */
final readonly class DbalTeamPeopleQueries implements TeamPeopleQueries
{
    public function __construct(private DbalTeamQueries $teamQueries, private UserQueries $userQueries)
    {
    }

    public function namedPossibleAssigneesOf(TeamId $teamId): array
    {
        $userIds = $this->teamQueries->possibleAssigneesOf([$teamId])[$teamId->toString()] ?? [];
        $names = $this->userQueries->byIds($userIds);
        $assignees = [];
        foreach ($userIds as $userId) {
            $name = $names[$userId->toString()] ?? null;
            if (null !== $name) {
                $assignees[] = new PossibleAssignee($userId, $name->firstName());
            }
        }

        return $assignees;
    }
}
