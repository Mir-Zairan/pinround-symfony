<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Api\TeamQueries;
use App\Organization\Api\TeamSummary;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use Doctrine\DBAL\Connection;

final readonly class DbalTeamQueries implements TeamQueries
{
    public function __construct(private Connection $connection)
    {
    }

    public function teamsOf(OrganizationId $organizationId): array
    {
        return array_map(
            self::teamId(...),
            $this->connection->fetchFirstColumn('SELECT id FROM org_team WHERE organization_id = ? ORDER BY id', [$organizationId->toString()]),
        );
    }

    public function summariesOf(OrganizationId $organizationId, array $teamIds): array
    {
        if ([] === $teamIds) {
            return [];
        }

        // One array literal parameter keeps the SQL text the same for any number of ids; a TeamId is always a uuid, so
        // it needs no quoting inside the literal.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name FROM org_team WHERE organization_id = ? AND id = ANY(?::uuid[]) ORDER BY lower(name), id',
            [$organizationId->toString(), '{'.implode(',', array_map(static fn (TeamId $teamId): string => $teamId->toString(), $teamIds)).'}'],
        );

        return self::summaries($rows);
    }

    public function allSummariesOf(OrganizationId $organizationId): array
    {
        return self::summaries($this->connection->fetchAllAssociative(
            'SELECT id, name FROM org_team WHERE organization_id = ? ORDER BY lower(name), id',
            [$organizationId->toString()],
        ));
    }

    public function countOf(OrganizationId $organizationId): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM org_team WHERE organization_id = ?', [$organizationId->toString()]);

        return \is_int($count) ? $count : throw new \LogicException('A team count from the database is not an integer.');
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<TeamSummary>
     */
    private static function summaries(array $rows): array
    {
        return array_map(
            static fn (array $row): TeamSummary => new TeamSummary(
                self::teamId($row['id']),
                \is_string($row['name']) ? $row['name'] : throw new \LogicException('A team name from the database is not a string.'),
            ),
            $rows,
        );
    }

    private static function teamId(mixed $teamId): TeamId
    {
        if (!\is_string($teamId)) {
            throw new \LogicException('A team id from the database is not a uuid.');
        }

        return new TeamId($teamId);
    }
}
