<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Turn;

use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\ReleasePlacement;
use App\Workflow\Application\Turn\WeightedNeedsYouScore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WeightedNeedsYouScoreTest extends TestCase
{
    /**
     * @return iterable<string, array{NeedsYouKind, int}>
     */
    public static function kindBases(): iterable
    {
        yield 'answer' => [NeedsYouKind::Answer, 100];
        yield 'check' => [NeedsYouKind::Check, 70];
        yield 'review' => [NeedsYouKind::Review, 30];
        yield 'test' => [NeedsYouKind::Test, 30];
        yield 'assign' => [NeedsYouKind::Assign, 25];
        yield 'input' => [NeedsYouKind::Input, 20];
        yield 'decide' => [NeedsYouKind::Decide, 15];
        yield 'build' => [NeedsYouKind::Build, 10];
    }

    #[Test]
    #[DataProvider('kindBases')]
    public function eachKindHasItsBase(NeedsYouKind $kind, int $expected): void
    {
        self::assertSame($expected, new WeightedNeedsYouScore()->baseScore($kind, null, ReleasePlacement::NoRelease, false));
    }

    /**
     * @return iterable<string, array{string|null, int}>
     */
    public static function priorityWeights(): iterable
    {
        yield 'blocker' => ['blocker', 50];
        yield 'critical' => ['critical', 40];
        yield 'major' => ['major', 20];
        yield 'normal' => ['normal', 8];
        yield 'minor' => ['minor', 3];
        yield 'none' => [null, 0];
    }

    #[Test]
    #[DataProvider('priorityWeights')]
    public function eachPriorityAddsItsWeight(?string $priority, int $expected): void
    {
        self::assertSame(10 + $expected, new WeightedNeedsYouScore()->baseScore(NeedsYouKind::Build, $priority, ReleasePlacement::NoRelease, false));
    }

    #[Test]
    public function aRiskCheckAddsFortyToTheCheckBase(): void
    {
        $score = new WeightedNeedsYouScore();

        self::assertSame(110, $score->baseScore(NeedsYouKind::Check, null, ReleasePlacement::NoRelease, true));
        self::assertSame(70, $score->baseScore(NeedsYouKind::Check, null, ReleasePlacement::NoRelease, false));
    }

    #[Test]
    public function theRiskFlagOnAnyOtherKindAddsNothing(): void
    {
        self::assertSame(100, new WeightedNeedsYouScore()->baseScore(NeedsYouKind::Answer, null, ReleasePlacement::NoRelease, true));
    }

    #[Test]
    public function releasePointsDependOnWhichReleaseTheTicketIsIn(): void
    {
        $score = new WeightedNeedsYouScore();

        self::assertSame(40, $score->baseScore(NeedsYouKind::Build, null, ReleasePlacement::InCurrentRelease, false));
        self::assertSame(20, $score->baseScore(NeedsYouKind::Build, null, ReleasePlacement::InOtherRelease, false));
        self::assertSame(10, $score->baseScore(NeedsYouKind::Build, null, ReleasePlacement::NoRelease, false));
    }

    #[Test]
    public function kindPriorityAndReleaseAreSummed(): void
    {
        self::assertSame(100 + 40 + 30, new WeightedNeedsYouScore()->baseScore(NeedsYouKind::Answer, 'critical', ReleasePlacement::InCurrentRelease, false));
    }

    #[Test]
    public function anUnknownPriorityIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WeightedNeedsYouScore()->baseScore(NeedsYouKind::Build, 'urgent', ReleasePlacement::NoRelease, false);
    }
}
