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
 * Which rounds the round page offers the move-on verbs on (ADR-0048): an open shared review or draft round after the
 * report.
 */
final class RoundControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string, string, bool}>
     */
    public static function rounds(): iterable
    {
        yield 'an open review round' => [2, 'review', 'open', true];
        yield 'an open shared draft, round 1' => [1, 'draft', 'open', true];
        yield 'a closed review round' => [2, 'review', 'closed', false];
        yield 'a closed shared draft' => [1, 'draft', 'closed', false];
        yield 'the report, round 0' => [0, 'report', 'open', false];
        yield 'a draft numbered 0' => [0, 'draft', 'open', false];
        yield 'an open checklist round' => [1, 'checklist', 'open', false];
    }

    #[Test]
    #[DataProvider('rounds')]
    public function onlyAnOpenHandOffRoundWaitsForAMoveOn(int $number, string $kind, string $state, bool $isOpenHandOff): void
    {
        $round = new ReviewRoundView('0190a000-0000-7000-8000-0000000000a1', $number, $kind, new UserId('0190a000-0000-7000-8000-0000000000b2'), new \DateTimeImmutable('2026-11-05 10:00:00 UTC'), $state, []);

        self::assertSame($isOpenHandOff, RoundController::isOpenHandOffRound($round));
    }
}
