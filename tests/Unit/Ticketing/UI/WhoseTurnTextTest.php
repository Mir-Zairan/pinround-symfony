<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI;

use App\Ticketing\UI\WhoseTurnText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WhoseTurnTextTest extends TestCase
{
    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string KOLKATA_NOW = '2026-11-06 09:00:00+05:30';

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function waits(): iterable
    {
        yield 'a wait that starts later' => ['2026-11-06 13:00:00 UTC', 0];
        yield 'under a day' => ['2026-11-05 12:00:01 UTC', 0];
        yield 'exactly a day' => ['2026-11-05 12:00:00 UTC', 1];
        yield 'a day and 23 hours' => ['2026-11-04 13:00:00 UTC', 1];
        yield 'in another time zone' => ['2026-10-27 13:00:00+01:00', 10];
    }

    #[Test]
    #[DataProvider('waits')]
    public function aWaitCountsWholeDays(string $since, int $waitDays): void
    {
        self::assertSame($waitDays, WhoseTurnText::waitDaysOf(new \DateTimeImmutable($since), new \DateTimeImmutable(self::NOW)));
    }

    /**
     * NOW is 09:00 on 6 Nov in Kolkata (UTC+05:30); a wait under a day says "today" only when it began on that date there.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function waitWords(): iterable
    {
        yield 'came at 00:30 today' => ['2026-11-06 00:30:00+05:30', 'today'];
        yield 'came at 22:00 yesterday, eleven hours ago' => ['2026-11-05 22:00:00+05:30', 'since yesterday'];
        yield 'came after now' => ['2026-11-06 10:00:00+05:30', 'today'];
        yield 'one day' => ['2026-11-05 08:00:00+05:30', '1 day'];
        yield 'two days' => ['2026-11-04 08:00:00+05:30', '2 days'];
        yield 'forty-one days' => ['2026-09-26 08:00:00+05:30', '41 days'];
    }

    #[Test]
    #[DataProvider('waitWords')]
    public function aWaitReadsTodayOnTheViewersCalendarDayElseSinceYesterdayOrDays(string $since, string $wait): void
    {
        self::assertSame($wait, WhoseTurnText::waitOf(new \DateTimeImmutable($since), new \DateTimeImmutable(self::KOLKATA_NOW), new \DateTimeZone('Asia/Kolkata')));
    }

    /**
     * The same instant, 22:00 yesterday in Kolkata, is 16:30 on 5 Nov in UTC, also yesterday, but 01:30 on 6 Nov in
     * Tokyo: the day is the viewer's, not the server's.
     */
    #[Test]
    public function todayIsTheViewersCalendarDayNotTheServers(): void
    {
        $since = new \DateTimeImmutable('2026-11-05 22:00:00+05:30');
        $now = new \DateTimeImmutable(self::KOLKATA_NOW);

        self::assertSame('since yesterday', WhoseTurnText::waitOf($since, $now, new \DateTimeZone('UTC')));
        self::assertSame('today', WhoseTurnText::waitOf($since, $now, new \DateTimeZone('Asia/Tokyo')));
    }

    /**
     * @return iterable<string, array{int, string, string, bool, string}>
     */
    public static function sentences(): iterable
    {
        yield 'many tickets' => [6, 'Mia', '2026-10-25 08:00:00+05:30', false, '6 tickets wait on Mia · the oldest for 12 days'];
        yield 'outside the team' => [3, 'Customer', '2026-11-05 08:00:00+05:30', true, '3 tickets wait on Customer · the oldest for 1 day · outside the team'];
        yield 'one ticket' => [1, 'you', '2026-11-04 08:00:00+05:30', false, '1 ticket waits on you · for 2 days'];
        yield 'one ticket of today' => [1, 'Omar', '2026-11-06 00:30:00+05:30', false, '1 ticket waits on Omar · since today'];
        yield 'many of today' => [2, 'Omar', '2026-11-06 00:30:00+05:30', false, '2 tickets wait on Omar · the oldest since today'];
        yield 'one ticket of 22:00 yesterday' => [1, 'Omar', '2026-11-05 22:00:00+05:30', false, '1 ticket waits on Omar · since yesterday'];
        yield 'many of 22:00 yesterday' => [2, 'Omar', '2026-11-05 22:00:00+05:30', false, '2 tickets wait on Omar · the oldest since yesterday'];
        yield 'thousands' => [1200, 'Omar', '2026-08-28 08:00:00+05:30', false, '1,200 tickets wait on Omar · the oldest for 70 days'];
    }

    #[Test]
    #[DataProvider('sentences')]
    public function theSentenceSaysHowManyWaitOnWhomAndForHowLong(int $ticketCount, string $waitsOn, string $oldestWaitingSince, bool $isOutsideTheTeam, string $sentence): void
    {
        self::assertSame($sentence, WhoseTurnText::sentenceOf($ticketCount, $waitsOn, new \DateTimeImmutable($oldestWaitingSince), new \DateTimeImmutable(self::KOLKATA_NOW), new \DateTimeZone('Asia/Kolkata'), $isOutsideTheTeam, false));
    }

    #[Test]
    public function theMeterIsAmberAfterTenDaysAndRedAfterThirty(): void
    {
        self::assertSame(
            ['calm', 'calm', 'busy', 'busy', 'too-much'],
            array_map(WhoseTurnText::meterLevelOf(...), [0, 10, 11, 30, 31]),
        );
        self::assertSame([false, true], [WhoseTurnText::isTooLong(30), WhoseTurnText::isTooLong(31)]);
    }

    #[Test]
    public function theTotalLineCountsTicketsGroupedByWhoActsNext(): void
    {
        self::assertSame('0 tickets · grouped by who acts next', WhoseTurnText::totalLineOf(0));
        self::assertSame('1 ticket · grouped by who acts next', WhoseTurnText::totalLineOf(1));
        self::assertSame('1,200 tickets · grouped by who acts next', WhoseTurnText::totalLineOf(1200));
    }

    #[Test]
    public function aCappedTotalLineSaysThereAreMoreThanItCounts(): void
    {
        self::assertSame('1,000+ tickets · grouped by who acts next', WhoseTurnText::totalLineOf(1000, true));
        self::assertSame('1+ tickets · grouped by who acts next', WhoseTurnText::totalLineOf(1, true));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function cappedSentences(): iterable
    {
        yield 'many tickets' => [6, '6+ tickets wait on Mia · the oldest for 12 days'];
        yield 'one ticket' => [1, '1+ tickets wait on Mia · the oldest for 12 days'];
    }

    #[Test]
    #[DataProvider('cappedSentences')]
    public function aCappedSearchSaysTheCardMayHoldMore(int $ticketCount, string $sentence): void
    {
        self::assertSame($sentence, WhoseTurnText::sentenceOf($ticketCount, 'Mia', new \DateTimeImmutable('2026-10-25 08:00:00+05:30'), new \DateTimeImmutable(self::KOLKATA_NOW), new \DateTimeZone('Asia/Kolkata'), false, true));
    }

    #[Test]
    public function noMatchesAsksToClearTheSearchAndNamesNoPerson(): void
    {
        self::assertStringEndsWith('Clear the search.', WhoseTurnText::NO_MATCHES);
        self::assertStringNotContainsString('person', WhoseTurnText::NO_MATCHES);
    }
}
