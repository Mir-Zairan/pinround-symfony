<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\UI;

use App\SharedKernel\UI\RelativeTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RelativeTimeTest extends TestCase
{
    private const string NOW = '2026-03-15T10:30:00+00:00';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function timesSince(): iterable
    {
        yield 'the same second' => ['2026-03-15T10:30:00+00:00', 'now'];
        yield '59 s' => ['2026-03-15T10:29:01+00:00', 'now'];
        yield '60 s' => ['2026-03-15T10:29:00+00:00', '1 min ago'];
        yield '3599 s' => ['2026-03-15T09:30:01+00:00', '59 min ago'];
        yield '3600 s' => ['2026-03-15T09:30:00+00:00', '1 h ago'];
        yield '86399 s' => ['2026-03-14T10:30:01+00:00', '23 h ago'];
        yield '86400 s is the date' => ['2026-03-14T10:30:00+00:00', '14 Mar'];
        yield 'a second ahead of now' => ['2026-03-15T10:30:01+00:00', 'now'];
        yield 'a day ahead of now' => ['2026-03-16T10:30:00+00:00', 'now'];
        yield 'another year' => ['2025-12-31T10:30:00+00:00', '31 Dec 2025'];
    }

    #[Test]
    #[DataProvider('timesSince')]
    public function theTimeSinceIsNowForAMinuteThenMinutesThenHoursThenTheDate(string $at, string $expected): void
    {
        self::assertSame($expected, RelativeTime::sinceOf(new \DateTimeImmutable($at), new \DateTimeImmutable(self::NOW), new \DateTimeZone('UTC')));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function timesSinceInAViewerZone(): iterable
    {
        yield 'the day moves forward east of UTC' => ['2026-03-12T23:30:00+00:00', self::NOW, 'Europe/Berlin', '13 Mar'];
        yield 'the day moves back west of UTC' => ['2026-03-13T01:30:00+00:00', self::NOW, 'America/New_York', '12 Mar'];
        yield 'the year of the time is the viewer\'s' => ['2025-12-31T23:30:00+00:00', self::NOW, 'Asia/Tokyo', '1 Jan'];
        yield 'the year of now is the viewer\'s' => ['2026-12-30T10:00:00+00:00', '2027-01-01T00:30:00+00:00', 'America/New_York', '30 Dec'];
        yield 'an hour count ignores the zone' => ['2026-03-15T00:30:00+00:00', self::NOW, 'Pacific/Kiritimati', '10 h ago'];
    }

    #[Test]
    #[DataProvider('timesSinceInAViewerZone')]
    public function theDateIsPrintedInTheViewersTimeZone(string $at, string $now, string $zone, string $expected): void
    {
        self::assertSame($expected, RelativeTime::sinceOf(new \DateTimeImmutable($at), new \DateTimeImmutable($now), new \DateTimeZone($zone)));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function dates(): iterable
    {
        yield 'a minute ago is still a date' => ['2026-03-15T10:29:00+00:00', 'UTC', '15 Mar'];
        yield 'ahead of now is a date' => ['2026-03-16T10:30:00+00:00', 'UTC', '16 Mar'];
        yield 'another year' => ['2025-03-15T10:30:00+00:00', 'UTC', '15 Mar 2025'];
        yield 'the day moves forward east of UTC' => ['2026-03-14T23:30:00+00:00', 'Asia/Tokyo', '15 Mar'];
        yield 'the year moves forward east of UTC' => ['2025-12-31T23:30:00+00:00', 'Asia/Tokyo', '1 Jan'];
    }

    #[Test]
    #[DataProvider('dates')]
    public function theDateIsTheDayInTheViewersZoneWithTheYearOnlyWhenItIsNotNowsYear(string $at, string $zone, string $expected): void
    {
        self::assertSame($expected, RelativeTime::dateOf(new \DateTimeImmutable($at), new \DateTimeImmutable(self::NOW), new \DateTimeZone($zone)));
    }
}
