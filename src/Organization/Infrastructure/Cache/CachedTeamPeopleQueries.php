<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Cache;

use App\Organization\Api\TeamPeopleQueries;
use App\Organization\Infrastructure\Doctrine\DbalTeamPeopleQueries;
use App\SharedKernel\Domain\TeamId;

/**
 * DbalTeamPeopleQueries through TeamPeopleCache.
 */
final readonly class CachedTeamPeopleQueries implements TeamPeopleQueries
{
    public function __construct(private DbalTeamPeopleQueries $teamPeopleQueries, private TeamPeopleCache $teamPeopleCache)
    {
    }

    public function namedPossibleAssigneesOf(TeamId $teamId): array
    {
        return $this->teamPeopleCache->remember($teamId, fn (): array => $this->teamPeopleQueries->namedPossibleAssigneesOf($teamId));
    }
}
