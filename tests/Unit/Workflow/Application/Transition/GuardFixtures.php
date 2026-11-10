<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Transition;

use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\RuleSetView;
use App\Workflow\Api\TicketState;

/**
 * The Shop team of Acme Foods: Lena leads it, Omar develops, Mia tests, and a viewer reads along. Raj is a manager and
 * Sara an admin, both without a role in the team; another person is a member of another team only.
 */
final class GuardFixtures
{
    public const string LENA = '0190a000-0000-7000-8000-00000000000a';
    public const string OMAR = '0190a000-0000-7000-8000-00000000000b';
    public const string MIA = '0190a000-0000-7000-8000-00000000000c';
    public const string RAJ = '0190a000-0000-7000-8000-00000000000d';
    public const string SARA = '0190a000-0000-7000-8000-00000000000e';
    public const string VIEWER = '0190a000-0000-7000-8000-00000000001a';
    public const string OTHER_TEAM_MEMBER = '0190a000-0000-7000-8000-00000000001b';

    private const string TEAM = '0190a000-0000-7000-8000-0000000000f2';
    private const string STEP = '0190a000-0000-7000-8000-0000000000f3';

    private const array NAMES = [self::LENA => 'Lena', self::OMAR => 'Omar', self::MIA => 'Mia', self::RAJ => 'Raj', self::SARA => 'Sara'];

    public static function lena(string $job = 'developer'): ActorInTeam
    {
        return new ActorInTeam(new UserId(self::LENA), 'lead', $job);
    }

    public static function omar(string $job = 'developer'): ActorInTeam
    {
        return new ActorInTeam(new UserId(self::OMAR), 'member', $job);
    }

    public static function mia(): ActorInTeam
    {
        return new ActorInTeam(new UserId(self::MIA), 'member', 'tester');
    }

    public static function raj(): ActorInTeam
    {
        return new ActorInTeam(new UserId(self::RAJ));
    }

    public static function sara(): ActorInTeam
    {
        return new ActorInTeam(new UserId(self::SARA));
    }

    /**
     * With the job tester, so the job alone does not make anyone a mover.
     */
    public static function viewer(): ActorInTeam
    {
        return new ActorInTeam(new UserId(self::VIEWER), 'viewer', 'tester');
    }

    public static function otherTeamMember(): ActorInTeam
    {
        return new ActorInTeam(new UserId(self::OTHER_TEAM_MEMBER));
    }

    public static function nameOf(UserId $userId): string
    {
        return self::NAMES[$userId->toString()] ?? throw new \LogicException('No name for '.$userId->toString());
    }

    public static function rules(bool $testerMovesDone = true, bool $noSelfReview = true, bool $reviewBeforeTest = true): RuleSetView
    {
        return new RuleSetView($testerMovesDone, $noSelfReview, $reviewBeforeTest);
    }

    public static function ticket(
        ?string $coreCode,
        string $groupCode,
        ?string $assignee = null,
        ?string $reviewer = null,
        ?string $tester = null,
        ?string $asked = null,
        ?string $checker = null,
        bool $hasTeam = true,
    ): TicketState {
        return new TicketState(
            stepId: self::STEP,
            coreCode: $coreCode,
            groupCode: $groupCode,
            teamId: $hasTeam ? new TeamId(self::TEAM) : null,
            assigneeId: self::userIdOrNull($assignee),
            reviewerId: self::userIdOrNull($reviewer),
            testerId: self::userIdOrNull($tester),
            askedUserId: self::userIdOrNull($asked),
            isCheckNeeded: null !== $checker,
            checkerId: self::userIdOrNull($checker),
        );
    }

    private static function userIdOrNull(?string $userId): ?UserId
    {
        return null === $userId ? null : new UserId($userId);
    }
}
