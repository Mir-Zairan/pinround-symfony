<?php

declare(strict_types=1);

namespace App\Tests\Unit\Insight\Application\MyReports;

use App\Insight\Api\MyReport;
use App\Insight\Application\MyReports\GroupedReports;
use App\Insight\Application\MyReports\ReportGroup;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The clock is fixed on 6 Nov 2026 12:00 UTC, not today, so a rule that read the real clock would group differently.
 */
final class GroupedReportsTest extends TestCase
{
    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000e853';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000e851';

    #[Test]
    public function aReportChangedExactlySevenDaysAgoStillMovesAndOneSecondEarlierIsStuck(): void
    {
        self::assertSame(
            [ReportGroup::Moving, ReportGroup::Stuck],
            [
                ReportGroup::of(self::report(1, '2026-10-30 12:00:00'), self::now()),
                ReportGroup::of(self::report(2, '2026-10-30 11:59:59'), self::now()),
            ],
        );
    }

    #[Test]
    public function anOpenQuestionToTheReporterNeedsThemHoweverOldAndDoneWinsOverEverything(): void
    {
        self::assertSame(
            [ReportGroup::NeedsYou, ReportGroup::Done, ReportGroup::Moving],
            [
                ReportGroup::of(self::report(1, '2026-01-01 00:00:00', isQuestionToReporter: true), self::now()),
                ReportGroup::of(self::report(2, '2026-11-06 11:00:00', closedAt: '2026-11-06 11:00:00'), self::now()),
                ReportGroup::of(self::report(3, '2026-11-06 11:00:00', turnUserId: self::LENA, turnKind: 'build'), self::now()),
            ],
        );
    }

    #[Test]
    public function stuckAndMovingShowFourThenFoldTheRestNeedsYouShowsAllAndDoneIsFolded(): void
    {
        $reports = [];
        foreach (range(1, 5) as $number) {
            $reports[] = self::report($number, '2026-10-01 09:00:00');
        }
        foreach (range(6, 9) as $number) {
            $reports[] = self::report($number, '2026-11-05 09:00:00');
        }
        foreach (range(10, 15) as $number) {
            $reports[] = self::report($number, '2026-01-01 09:00:00', isQuestionToReporter: true);
        }
        $reports[] = self::report(16, '2026-11-01 09:00:00', closedAt: '2026-11-01 09:00:00');
        $reports[] = self::report(17, '2026-11-02 09:00:00', closedAt: '2026-11-02 09:00:00');

        $grouped = GroupedReports::of($reports, self::now());

        self::assertSame(
            [
                'needs_you' => [[10, 11, 12, 13, 14, 15], []],
                'stuck' => [[1, 2, 3, 4], [5]],
                'moving' => [[6, 7, 8, 9], []],
                'done' => [[], [16, 17]],
            ],
            array_combine(
                array_map(static fn (ReportGroup $group): string => $group->value, ReportGroup::cases()),
                array_map(static fn (ReportGroup $group): array => [self::numbersOf($grouped->shownIn($group)), self::numbersOf($grouped->foldedIn($group))], ReportGroup::cases()),
            ),
        );
    }

    #[Test]
    public function noReportsGiveEmptyGroups(): void
    {
        $grouped = GroupedReports::of([], self::now());

        foreach (ReportGroup::cases() as $group) {
            self::assertSame([[], [], []], [$grouped->reportsIn($group), $grouped->shownIn($group), $grouped->foldedIn($group)]);
        }
    }

    /**
     * @param list<MyReport> $reports
     *
     * @return list<int>
     */
    private static function numbersOf(array $reports): array
    {
        return array_map(static fn (MyReport $report): int => (int) substr($report->key, \strlen('SHOP-')), $reports);
    }

    private static function report(int $number, string $updatedAt, ?string $closedAt = null, bool $isQuestionToReporter = false, ?string $turnUserId = null, ?string $turnKind = null): MyReport
    {
        return new MyReport(
            new TicketId(\sprintf('0197a3c0-0000-7000-8000-00000000f%03d', $number)),
            'SHOP-'.$number,
            'Cart total '.$number.' is wrong',
            '0197a3c0-0000-7000-8000-00000000a732',
            new \DateTimeImmutable('2026-01-01 00:00:00 UTC'),
            new \DateTimeImmutable($updatedAt.' UTC'),
            null === $closedAt ? null : new \DateTimeImmutable($closedAt.' UTC'),
            null === $closedAt ? 'doing' : null,
            $isQuestionToReporter ? new UserId(self::MIA) : (null === $turnUserId ? null : new UserId($turnUserId)),
            null,
            $isQuestionToReporter ? 'answer' : $turnKind,
            $isQuestionToReporter,
        );
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }
}
