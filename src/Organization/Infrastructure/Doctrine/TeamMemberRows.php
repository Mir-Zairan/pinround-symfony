<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Api\MemberJob;
use App\SharedKernel\Domain\UserId;

/**
 * How Organization's reads pick and map org_team_member rows, so every read of a team's people names the same people.
 */
final class TeamMemberRows
{
    /**
     * Ends a WHERE on org_team_member: the leads and members, in user id order. Viewers are left out, as they cannot
     * move tickets. Unqualified, so a query that joins org_team (which has neither column) can use it too.
     */
    public const string LEADS_AND_MEMBERS_IN_USER_ID_ORDER = "team_role IN ('lead', 'member') ORDER BY user_id";

    /**
     * @throws \LogicException when the stored job is not one of MemberJob's values
     */
    public static function jobOf(mixed $job): MemberJob
    {
        return (\is_string($job) ? MemberJob::tryFrom($job) : null) ?? throw new \LogicException('org_team_member holds an unknown job.');
    }

    /**
     * @throws \LogicException when the stored user id is not a string
     */
    public static function userIdOf(mixed $userId): UserId
    {
        return \is_string($userId) ? new UserId($userId) : throw new \LogicException('A team member id from the database is not a uuid.');
    }
}
