<?php

declare(strict_types=1);

namespace App\Insight\Infrastructure\Doctrine;

use App\Insight\Api\ProjectCountQueries;
use App\Insight\Api\ProjectCounts;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\TeamProjectChoice;
use App\Ticketing\Api\TicketVisibilities;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * ProjectCountQueries on ins_project_counts; the contract is on {@see ProjectCountQueries}. The counts are read through
 * the primary key (organization_id, project_id, team_id, visibility).
 */
final readonly class DbalProjectCountQueries implements ProjectCountQueries
{
    private const string NO_TRANSACTION = 'The project counts are read in a transaction under the organization as tenant; none is open.';

    public function __construct(
        private ProjectQueries $projectQueries,
        private TicketVisibilities $ticketVisibilities,
        private Connection $connection,
        private TenantContext $tenantContext,
    ) {
    }

    public function countsOfTeam(TeamId $teamId, UserId $viewerId, bool $withDone): array
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException(self::NO_TRANSACTION);
        }
        $organizationId = $this->tenantContext->require();

        $projectIds = array_map(static fn (TeamProjectChoice $project): string => $project->id(), $this->projectQueries->projectChoicesOfTeams([$teamId]));
        if ([] === $projectIds) {
            return [];
        }
        $viewer = $this->ticketVisibilities->of($organizationId, $viewerId);

        return $this->countsOfProjects($projectIds, $viewer->isAdmin(), $viewer->teamIds(), $withDone);
    }

    public function countsOfProjects(array $projectIds, bool $viewerIsAdmin, array $viewerTeamIds, bool $withDone): array
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException(self::NO_TRANSACTION);
        }
        foreach ($projectIds as $projectId) {
            if (!Uuid::isValid($projectId)) {
                throw new \InvalidArgumentException('A project id is not a uuid.');
            }
        }
        if ([] === $projectIds) {
            return [];
        }
        $organizationId = $this->tenantContext->require();

        // team_id, not the project's team: a ticket keeps its team when its project moves. Uuid array literals need no quoting.
        $rows = $this->connection->fetchAllAssociativeIndexed(
            'SELECT project_id, sum(open_count) AS open_count, sum(done_count) AS done_count FROM ins_project_counts'
            .' WHERE organization_id = CAST(? AS uuid) AND project_id = ANY(CAST(? AS uuid[]))'
            ." AND (visibility = 'all' OR CAST(? AS boolean) OR team_id = ANY(CAST(? AS uuid[])))"
            .' GROUP BY project_id',
            [
                $organizationId->toString(),
                '{'.implode(',', $projectIds).'}',
                $viewerIsAdmin ? 'true' : 'false',
                '{'.implode(',', array_map(static fn (TeamId $ownTeamId): string => $ownTeamId->toString(), $viewerTeamIds)).'}',
            ],
        );

        return array_map(static function (string $projectId) use ($rows, $withDone): ProjectCounts {
            $row = $rows[strtolower($projectId)] ?? ['open_count' => 0, 'done_count' => 0];

            return new ProjectCounts($projectId, self::countOf($row['open_count']), $withDone ? self::countOf($row['done_count']) : null);
        }, $projectIds);
    }

    /**
     * PostgreSQL's sum of an int column is a bigint, which pdo_pgsql may hand over as a string.
     */
    private static function countOf(mixed $sum): int
    {
        if (\is_int($sum)) {
            return $sum;
        }
        if (\is_string($sum) && ctype_digit($sum)) {
            return (int) $sum;
        }

        throw new \LogicException('A project count from the database is not a whole number.');
    }
}
