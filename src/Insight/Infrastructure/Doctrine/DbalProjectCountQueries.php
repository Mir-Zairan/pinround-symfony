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

/**
 * ProjectCountQueries on ins_project_counts; the contract is on {@see ProjectCountQueries}. The counts are read through
 * the primary key (organization_id, project_id, visibility).
 */
final readonly class DbalProjectCountQueries implements ProjectCountQueries
{
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
            throw new \LogicException('The project counts are read in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require();

        $projectIds = array_map(static fn (TeamProjectChoice $project): string => $project->id(), $this->projectQueries->projectChoicesOfTeams([$teamId]));
        if ([] === $projectIds) {
            return [];
        }
        $viewer = $this->ticketVisibilities->of($organizationId, $viewerId);
        $seesTeamOnly = $viewer->isAdmin() || array_any($viewer->teamIds(), static fn (TeamId $ownTeamId): bool => $ownTeamId->equals($teamId));

        // One array literal keeps the SQL text the same for any number of ids; a project id is a uuid, so needs no quoting.
        $rows = $this->connection->fetchAllAssociativeIndexed(
            'SELECT project_id, sum(open_count) AS open_count, sum(done_count) AS done_count FROM ins_project_counts'
            .' WHERE organization_id = CAST(? AS uuid) AND project_id = ANY(CAST(? AS uuid[]))'
            .($seesTeamOnly ? '' : " AND visibility = 'all'")
            .' GROUP BY project_id',
            [$organizationId->toString(), '{'.implode(',', $projectIds).'}'],
        );

        return array_map(static function (string $projectId) use ($rows, $withDone): ProjectCounts {
            $row = $rows[$projectId] ?? ['open_count' => 0, 'done_count' => 0];

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
