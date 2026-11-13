<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Identity\Api\UserQueries;
use App\Organization\Api\MemberJob;
use App\Organization\Api\PossibleAssignee;
use App\Organization\Api\TeamPeopleQueries;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;

/**
 * The uncached read behind CachedTeamPeopleQueries: the team's people, then their names from Identity. The lead is the
 * team's lead_user_id, as in DbalTeamQueries::leadOf, not the member whose team role is Lead.
 */
final readonly class DbalTeamPeopleQueries implements TeamPeopleQueries
{
    public function __construct(private Connection $connection, private UserQueries $userQueries)
    {
    }

    public function namedPossibleAssigneesOf(TeamId $teamId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT member.user_id, member.job, member.user_id = team.lead_user_id AS is_lead'
            .' FROM org_team_member AS member'
            .' JOIN org_team AS team ON team.organization_id = member.organization_id AND team.id = member.team_id'
            ." WHERE member.team_id = ? AND member.team_role IN ('lead', 'member') ORDER BY member.user_id",
            [$teamId->toString()],
        );
        if ([] === $rows) {
            return [];
        }

        $userIds = array_map(
            static fn (array $row): UserId => \is_string($row['user_id']) ? new UserId($row['user_id']) : throw new \LogicException('A team member id from the database is not a uuid.'),
            $rows,
        );
        $names = $this->userQueries->byIds($userIds);
        $assignees = [];
        foreach ($rows as $index => $row) {
            $name = $names[$userIds[$index]->toString()] ?? null;
            if (null !== $name) {
                $assignees[] = new PossibleAssignee($userIds[$index], $name->firstName(), $name->displayName, self::memberJob($row['job']), self::isLead($row['is_lead']));
            }
        }

        return $assignees;
    }

    private static function memberJob(mixed $job): MemberJob
    {
        return (\is_string($job) ? MemberJob::tryFrom($job) : null) ?? throw new \LogicException('org_team_member holds an unknown job.');
    }

    private static function isLead(mixed $isLead): bool
    {
        return \is_bool($isLead) ? $isLead : throw new \LogicException('A team lead flag from the database is not a boolean.');
    }
}
