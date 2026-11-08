<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Api\TeamQueries;
use App\Organization\Api\TeamSummary;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

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

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name FROM org_team WHERE organization_id = ? AND id IN (?) ORDER BY lower(name), id',
            [$organizationId->toString(), array_map(static fn (TeamId $teamId): string => $teamId->toString(), $teamIds)],
            [ParameterType::STRING, ArrayParameterType::STRING],
        );

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
