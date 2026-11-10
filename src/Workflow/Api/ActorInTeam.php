<?php

declare(strict_types=1);

namespace App\Workflow\Api;

use App\SharedKernel\Domain\UserId;

/**
 * The person asking for moves, as the ticket's team knows them. The caller fills it from Organization's ActorContext
 * (the team role in the ticket's team) and TeamQueries::jobOf, because Workflow's Api may only use SharedKernel types.
 * Org roles grant no move: an admin or a manager outside the team has no team role here.
 */
final readonly class ActorInTeam
{
    public const string TEAM_ROLE_LEAD = 'lead';
    public const string TEAM_ROLE_MEMBER = 'member';
    public const string TEAM_ROLE_VIEWER = 'viewer';
    public const string JOB_DEVELOPER = 'developer';
    public const string JOB_TESTER = 'tester';
    public const string JOB_OTHER = 'other';

    private const array TEAM_ROLES = [self::TEAM_ROLE_LEAD, self::TEAM_ROLE_MEMBER, self::TEAM_ROLE_VIEWER];
    private const array JOBS = [self::JOB_DEVELOPER, self::JOB_TESTER, self::JOB_OTHER];

    /**
     * @param string|null $teamRole lead, member or viewer: the person's team role in the ticket's team; null when they
     *                              have none there or the ticket has no team
     * @param string|null $job      developer, tester or other (Organization's MemberJob values): the person's job in the
     *                              ticket's team; null when they have none there
     *
     * @throws \InvalidArgumentException when the team role or the job is not one named above
     */
    public function __construct(public UserId $userId, public ?string $teamRole = null, public ?string $job = null)
    {
        if (null !== $teamRole && !\in_array($teamRole, self::TEAM_ROLES, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a team role.', $teamRole));
        }

        if (null !== $job && !\in_array($job, self::JOBS, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a job.', $job));
        }
    }
}
