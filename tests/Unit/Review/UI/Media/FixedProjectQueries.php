<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\Media;

use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\NextTicketKey;
use App\Ticketing\Api\ProjectChoice;
use App\Ticketing\Api\ProjectQueries;

/**
 * projectByKey as DbalProjectQueries answers it, over a fixed list of one organization's projects: a key outside
 * tkt_project_key_check's pattern finds nothing, and the match is exact, so a lowercase key finds nothing either. The
 * other reads are not modelled and throw.
 */
final readonly class FixedProjectQueries implements ProjectQueries
{
    /**
     * @param list<ProjectChoice> $projects
     */
    public function __construct(private array $projects)
    {
    }

    public function projectByKey(string $key): ?ProjectChoice
    {
        if (1 !== preg_match('/^[A-Z][A-Z0-9]{1,9}\z/', $key)) {
            return null;
        }
        foreach ($this->projects as $project) {
            if ($project->key() === $key) {
                return $project;
            }
        }

        return null;
    }

    public function projectsOf(TeamId $teamId, UserId $viewerId): array
    {
        throw new \LogicException('FixedProjectQueries answers only projectByKey.');
    }

    public function unlinkedProjectChoices(int $limit): array
    {
        throw new \LogicException('FixedProjectQueries answers only projectByKey.');
    }

    public function projectChoicesOfTeams(array $teamIds): array
    {
        throw new \LogicException('FixedProjectQueries answers only projectByKey.');
    }

    public function nextTicketKeyOf(string $projectId): ?NextTicketKey
    {
        throw new \LogicException('FixedProjectQueries answers only projectByKey.');
    }
}
