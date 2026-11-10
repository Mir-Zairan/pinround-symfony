<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Turn;

use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\NowNextContext;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TeamMemberLoad;
use App\Workflow\Api\TicketState;

final class NowNextFixtures
{
    public const string LENA = '0190a000-0000-7000-8000-00000000000a';
    public const string OMAR = '0190a000-0000-7000-8000-00000000000b';
    public const string MIA = '0190a000-0000-7000-8000-00000000000c';
    public const string BEN = '0190a000-0000-7000-8000-00000000000f';

    public static function user(string $id): UserId
    {
        return new UserId($id);
    }

    /**
     * @param array{assigneeId?: UserId, reviewerId?: UserId, testerId?: UserId, askedUserId?: UserId, pendingAssigneeId?: UserId, waitingOnUserId?: UserId, waitingOnLabel?: string, isCheckNeeded?: bool, checkerId?: UserId} $overrides
     */
    public static function ticket(?string $coreCode, string $groupCode, array $overrides = []): TicketState
    {
        return new TicketState(
            stepId: '0190a000-0000-7000-8000-0000000000aa',
            coreCode: $coreCode,
            groupCode: $groupCode,
            teamId: null,
            assigneeId: $overrides['assigneeId'] ?? null,
            reviewerId: $overrides['reviewerId'] ?? null,
            testerId: $overrides['testerId'] ?? null,
            askedUserId: $overrides['askedUserId'] ?? null,
            pendingAssigneeId: $overrides['pendingAssigneeId'] ?? null,
            waitingOnUserId: $overrides['waitingOnUserId'] ?? null,
            waitingOnLabel: $overrides['waitingOnLabel'] ?? null,
            isCheckNeeded: $overrides['isCheckNeeded'] ?? false,
            checkerId: $overrides['checkerId'] ?? null,
        );
    }

    /**
     * @param array<string, string>|null $names display names by user id; the default names the four people below
     * @param TeamFacts|null             $team  the default team: Lena leads, Lena and Omar develop, Mia tests
     */
    public static function context(?string $viewer = null, ?string $asker = null, bool $isReviewBeforeTest = true, string $stepName = 'Design check', ?array $names = null, ?TeamFacts $team = null): NowNextContext
    {
        return new NowNextContext(
            stepName: $stepName,
            firstNames: $names ?? [
                self::LENA => 'Lena Hart',
                self::OMAR => 'Omar',
                self::MIA => 'Mia',
                self::BEN => 'Ben',
            ],
            team: $team ?? new TeamFacts(new UserId(self::LENA), 'Lena', [new TeamMemberLoad(new UserId(self::LENA)), new TeamMemberLoad(new UserId(self::OMAR))], [new TeamMemberLoad(new UserId(self::MIA))]),
            isReviewBeforeTest: $isReviewBeforeTest,
            viewerId: null === $viewer ? null : new UserId($viewer),
            askerId: null === $asker ? null : new UserId($asker),
        );
    }
}
