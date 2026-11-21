<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\Controller;

use App\Review\Api\ReviewRoundView;
use App\Review\UI\Controller\RoundController;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which rounds the round page offers its viewer the move-on verbs on (ADR-0048): an open shared review or draft round
 * after the report that their move would close. SHOP-1 is Omar's; Omar owns every round here.
 */
final class RoundControllerTest extends TestCase
{
    private const string OMAR = '0190a000-0000-7000-8000-0000000000b2';
    private const string MIA = '0190a000-0000-7000-8000-0000000000b3';

    /**
     * @return iterable<string, array{int, string, string, bool, string, bool}> the round's number, kind and state, whether
     *                                                                          Omar shared it in a move, the viewer, and
     *                                                                          whether they get the verbs
     */
    public static function roundsAndViewers(): iterable
    {
        yield 'Mia on an open review round' => [2, 'review', 'open', false, self::MIA, true];
        yield 'Mia on an open draft Omar shared now' => [1, 'draft', 'open', false, self::MIA, true];
        yield 'Omar on an open draft he shared at hand-off' => [1, 'draft', 'open', true, self::OMAR, true];

        yield 'Omar on an open draft he shared now' => [1, 'draft', 'open', false, self::OMAR, false];
        yield 'Omar on his open review round' => [2, 'review', 'open', false, self::OMAR, false];
        yield 'Mia on a closed review round' => [2, 'review', 'closed', false, self::MIA, false];
        yield 'Mia on a closed shared draft' => [1, 'draft', 'closed', true, self::MIA, false];
        yield 'Mia on the report, round 0' => [0, 'report', 'open', false, self::MIA, false];
        yield 'Mia on an open checklist round' => [1, 'checklist', 'open', false, self::MIA, false];
    }

    #[Test]
    #[DataProvider('roundsAndViewers')]
    public function onlyAMoveOnThatClosesAnOpenHandOffRoundIsOffered(int $number, string $kind, string $state, bool $isSharedInAMove, string $viewerId, bool $isOffered): void
    {
        $round = new ReviewRoundView('0190a000-0000-7000-8000-0000000000a1', $number, $kind, new UserId(self::OMAR), new \DateTimeImmutable('2026-11-05 10:00:00 UTC'), $state, [], $isSharedInAMove);

        self::assertSame($isOffered, RoundController::isClosedByMoveOnOf(new UserId($viewerId), $round, new UserId(self::OMAR)));
    }
}
