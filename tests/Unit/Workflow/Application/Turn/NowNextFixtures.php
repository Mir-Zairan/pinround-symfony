<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Turn;

use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\NowNextContext;
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

    public static function context(?string $viewer = null, ?string $asker = null, bool $isReviewBeforeTest = true, string $stepName = 'Design check'): NowNextContext
    {
        return new NowNextContext(
            stepName: $stepName,
            firstNames: [
                self::LENA => 'Lena Hart',
                self::OMAR => 'Omar',
                self::MIA => 'Mia',
                self::BEN => 'Ben',
            ],
            viewerId: null === $viewer ? null : new UserId($viewer),
            askerId: null === $asker ? null : new UserId($asker),
            isReviewBeforeTest: $isReviewBeforeTest,
        );
    }
}
