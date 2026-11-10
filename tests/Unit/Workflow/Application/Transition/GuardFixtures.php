<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Transition;

use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\RuleSetView;
use App\Workflow\Api\TicketState;

/**
 * The Shop team of Acme Foods: Lena leads it, Omar develops, Mia tests; Raj is a manager outside the team.
 */
final class GuardFixtures
{
    public const string LENA = '0190a000-0000-7000-8000-00000000000a';
    public const string OMAR = '0190a000-0000-7000-8000-00000000000b';
    public const string MIA = '0190a000-0000-7000-8000-00000000000c';
    public const string RAJ = '0190a000-0000-7000-8000-00000000000d';

    private const string ORGANIZATION = '0190a000-0000-7000-8000-0000000000f1';
    private const string TEAM = '0190a000-0000-7000-8000-0000000000f2';
    private const string STEP = '0190a000-0000-7000-8000-0000000000f3';

    private const array NAMES = [self::LENA => 'Lena', self::OMAR => 'Omar', self::MIA => 'Mia', self::RAJ => 'Raj'];

    public static function lena(): ActorContext
    {
        return new ActorContext(new UserId(self::LENA), self::organizationId(), new Roles(false, false, [self::teamId()], [], []));
    }

    public static function omar(): ActorContext
    {
        return new ActorContext(new UserId(self::OMAR), self::organizationId(), new Roles(false, false, [], [self::teamId()], []));
    }

    public static function mia(): ActorContext
    {
        return new ActorContext(new UserId(self::MIA), self::organizationId(), new Roles(false, false, [], [self::teamId()], []));
    }

    public static function raj(): ActorContext
    {
        return new ActorContext(new UserId(self::RAJ), self::organizationId(), new Roles(false, true, [], [], []));
    }

    public static function nameOf(UserId $userId): string
    {
        return self::NAMES[$userId->toString()] ?? throw new \LogicException('No name for '.$userId->toString());
    }

    public static function rules(bool $testerMovesDone = true, bool $noSelfReview = true, bool $reviewBeforeTest = true): RuleSetView
    {
        return new RuleSetView($testerMovesDone, $noSelfReview, $reviewBeforeTest);
    }

    public static function ticket(string $coreCode, string $groupCode, ?string $assignee = null, ?string $reviewer = null, ?string $tester = null, ?string $asked = null): TicketState
    {
        return new TicketState(
            stepId: self::STEP,
            coreCode: $coreCode,
            groupCode: $groupCode,
            teamId: self::teamId(),
            assigneeId: self::userIdOrNull($assignee),
            reviewerId: self::userIdOrNull($reviewer),
            testerId: self::userIdOrNull($tester),
            askedUserId: self::userIdOrNull($asked),
        );
    }

    private static function userIdOrNull(?string $userId): ?UserId
    {
        return null === $userId ? null : new UserId($userId);
    }

    private static function organizationId(): OrganizationId
    {
        return new OrganizationId(self::ORGANIZATION);
    }

    private static function teamId(): TeamId
    {
        return new TeamId(self::TEAM);
    }
}
