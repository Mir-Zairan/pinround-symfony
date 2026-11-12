<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\ProjectChoice;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\ProjectSummary;
use App\Ticketing\Api\TeamProjectChoice;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Application\OpenTicketCounts;
use App\Ticketing\Domain\ProjectId;
use Doctrine\DBAL\Connection;

/**
 * Every query names organization_id as well as row-level security, and matches team_id (the unlinked ones through
 * team_id IS NULL), so they read tkt_project_organization_id_team_id_idx.
 */
final readonly class DbalProjectQueries implements ProjectQueries
{
    private const string PROJECT_KEY = '/^[A-Z][A-Z0-9]{1,9}\z/';

    public function __construct(
        private Connection $connection,
        private OpenTicketCounts $openTicketCounts,
        private TicketVisibilities $ticketVisibilities,
        private TenantContext $tenantContext,
    ) {
    }

    public function projectsOf(TeamId $teamId, UserId $viewerId): array
    {
        $organizationId = $this->tenantContext->require();

        return $this->summaries($organizationId, $viewerId, $this->connection->fetchAllAssociative(
            'SELECT id, key, name FROM tkt_project WHERE organization_id = ? AND team_id = ? ORDER BY key',
            [$organizationId->toString(), $teamId->toString()],
        ));
    }

    public function unlinkedProjectChoices(int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('A picker shows at least one project.');
        }

        return array_map(
            static fn (array $row): ProjectChoice => new ProjectChoice(self::text($row['id'], 'id'), self::text($row['key'], 'key'), self::text($row['name'], 'name')),
            $this->connection->fetchAllAssociative(
                'SELECT id, key, name FROM tkt_project WHERE organization_id = ? AND team_id IS NULL ORDER BY key LIMIT ?',
                [$this->tenantContext->require()->toString(), $limit],
            ),
        );
    }

    public function projectByKey(string $key): ?ProjectChoice
    {
        $organizationId = $this->tenantContext->require();
        // tkt_project_key_check's pattern: a key that cannot exist costs no query.
        if (1 !== preg_match(self::PROJECT_KEY, $key)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT id, key, name, team_id FROM tkt_project WHERE organization_id = ? AND key = ?',
            [$organizationId->toString(), $key],
        );
        if (false === $row) {
            return null;
        }

        return new ProjectChoice(
            self::text($row['id'], 'id'),
            self::text($row['key'], 'key'),
            self::text($row['name'], 'name'),
            null === $row['team_id'] ? null : new TeamId(self::text($row['team_id'], 'team_id')),
        );
    }

    public function projectChoicesOfTeams(array $teamIds): array
    {
        if ([] === $teamIds) {
            return [];
        }
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Projects of teams are read in a transaction under the organization as tenant; none is open.');
        }

        // One array literal keeps the SQL text the same for any number of ids; a TeamId is a uuid, so needs no quoting.
        return array_map(
            static fn (array $row): TeamProjectChoice => new TeamProjectChoice(
                self::text($row['id'], 'id'),
                self::text($row['key'], 'key'),
                self::text($row['name'], 'name'),
                new TeamId(self::text($row['team_id'], 'team_id')),
            ),
            $this->connection->fetchAllAssociative(
                'SELECT id, key, name, team_id FROM tkt_project WHERE organization_id = ? AND team_id = ANY(?::uuid[]) ORDER BY key',
                [
                    $this->tenantContext->require()->toString(),
                    '{'.implode(',', array_map(static fn (TeamId $teamId): string => $teamId->toString(), $teamIds)).'}',
                ],
            ),
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<ProjectSummary>
     */
    private function summaries(OrganizationId $organizationId, UserId $viewerId, array $rows): array
    {
        if ([] === $rows) {
            return [];
        }

        $projectIds = array_map(static fn (array $row): ProjectId => new ProjectId(self::text($row['id'], 'id')), $rows);
        $openTicketCounts = $this->openTicketCounts->openTicketsVisibleTo($this->ticketVisibilities->of($organizationId, $viewerId), ...$projectIds);

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
