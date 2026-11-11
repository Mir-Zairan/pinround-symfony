<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Api\MemberJob;
use App\Organization\Api\TeamQueries;
use App\Organization\Api\TeamSummary;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
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
            'SELECT id, name, slug FROM org_team WHERE organization_id = ? AND id = ANY(?::uuid[]) ORDER BY lower(name), id',
            [$organizationId->toString(), '{'.implode(',', array_map(static fn (TeamId $teamId): string => $teamId->toString(), $teamIds)).'}'],
        );

        return self::summaries($rows);
    }

    public function allSummariesOf(OrganizationId $organizationId): array
    {
        return self::summaries($this->connection->fetchAllAssociative(
            'SELECT id, name, slug FROM org_team WHERE organization_id = ? ORDER BY lower(name), id',
            [$organizationId->toString()],
        ));
    }

    public function countOf(OrganizationId $organizationId): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM org_team WHERE organization_id = ?', [$organizationId->toString()]);

        return \is_int($count) ? $count : throw new \LogicException('A team count from the database is not an integer.');
    }

    public function bySlug(OrganizationId $organizationId, string $slug): ?TeamSummary
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, name, slug FROM org_team WHERE organization_id = ? AND slug = ?',
            [$organizationId->toString(), $slug],
        );

        return false === $row ? null : self::summary($row);
    }

    /**
     * The key is (organization_id, team_id, user_id); row-level security supplies the organization, so a team of
     * another organization finds no row.
     */
    public function jobOf(TeamId $teamId, UserId $userId): ?MemberJob
    {
        $job = $this->connection->fetchOne(
            'SELECT job FROM org_team_member WHERE team_id = ? AND user_id = ?',
            [$teamId->toString(), $userId->toString()],
        );
        if (false === $job) {
            return null;
        }

        return self::memberJob($job);
    }

    /**
     * lead_user_id repeats the member with the team role Lead (see Domain\Team), so one row of org_team answers.
     */
    public function leadOf(TeamId $teamId): ?UserId
    {
        $leadId = $this->connection->fetchOne('SELECT lead_user_id FROM org_team WHERE id = ?', [$teamId->toString()]);
        if (false === $leadId) {
            return null;
        }

        return \is_string($leadId) ? new UserId($leadId) : throw new \LogicException('A team lead id from the database is not a uuid.');
    }

    public function peopleByJob(TeamId $teamId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT user_id, job FROM org_team_member WHERE team_id = ? AND team_role IN ('lead', 'member') ORDER BY user_id",
            [$teamId->toString()],
        );
        $people = [MemberJob::Developer->value => [], MemberJob::Tester->value => [], MemberJob::Other->value => []];
        foreach ($rows as $row) {
            $userId = \is_string($row['user_id']) ? new UserId($row['user_id']) : throw new \LogicException('A team member id from the database is not a uuid.');
            $people[self::memberJob($row['job'])->value][] = $userId;
        }

        return $people;
    }

    private static function memberJob(mixed $job): MemberJob
    {
        return (\is_string($job) ? MemberJob::tryFrom($job) : null) ?? throw new \LogicException('org_team_member holds an unknown job.');
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<TeamSummary>
     */
    private static function summaries(array $rows): array
    {
        return array_map(self::summary(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function summary(array $row): TeamSummary
    {
        return new TeamSummary(
            self::teamId($row['id']),
            \is_string($row['name']) ? $row['name'] : throw new \LogicException('A team name from the database is not a string.'),
            \is_string($row['slug']) ? $row['slug'] : throw new \LogicException('A team slug from the database is not a string.'),
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
