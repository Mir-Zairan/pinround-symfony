<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\ProjectSummary;
use App\Ticketing\Application\OpenTicketCounts;
use App\Ticketing\Domain\ProjectId;
use Doctrine\DBAL\Connection;

/**
 * Both queries name organization_id as well as row-level security, and match team_id (the unlinked ones through
 * team_id IS NULL), so they read tkt_project_organization_id_team_id_idx.
 */
final readonly class DbalProjectQueries implements ProjectQueries
{
    public function __construct(
        private Connection $connection,
        private OpenTicketCounts $openTicketCounts,
        private TenantContext $tenantContext,
    ) {
    }

    public function projectsOf(TeamId $teamId): array
    {
        return $this->summaries($this->connection->fetchAllAssociative(
            'SELECT id, key, name FROM tkt_project WHERE organization_id = ? AND team_id = ? ORDER BY key',
            [$this->tenantContext->require()->toString(), $teamId->toString()],
        ));
    }

    public function unlinkedProjects(): array
    {
        return $this->summaries($this->connection->fetchAllAssociative(
            'SELECT id, key, name FROM tkt_project WHERE organization_id = ? AND team_id IS NULL ORDER BY key',
            [$this->tenantContext->require()->toString()],
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<ProjectSummary>
     */
    private function summaries(array $rows): array
    {
        if ([] === $rows) {
            return [];
        }

        $projectIds = array_map(static fn (array $row): ProjectId => new ProjectId(self::text($row['id'], 'id')), $rows);
        $openTicketCounts = $this->openTicketCounts->openTicketsIn(...$projectIds);

        return array_map(
            static fn (array $row, ProjectId $projectId): ProjectSummary => new ProjectSummary(
                $projectId->toString(),
                self::text($row['key'], 'key'),
                self::text($row['name'], 'name'),
                $openTicketCounts[$projectId->toString()] ?? throw new \LogicException('OpenTicketCounts gave no count for a project.'),
            ),
            $rows,
            $projectIds,
        );
    }

    private static function text(mixed $value, string $column): string
    {
        return \is_string($value) ? $value : throw new \LogicException(\sprintf('A project %s from the database is not a string.', $column));
    }
}
