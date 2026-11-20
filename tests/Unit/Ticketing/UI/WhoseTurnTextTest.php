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

    #[Test]
    public function aWaitReadsTodayOneDayOrDays(): void
    {
        self::assertSame(['today', '1 day', '2 days', '41 days'], array_map(WhoseTurnText::waitOf(...), [0, 1, 2, 41]));
    }

    /**
     * @return iterable<string, array{int, string, int, bool, string}>
     */
    public static function sentences(): iterable
    {
        yield 'many tickets' => [6, 'Mia', 12, false, '6 tickets wait on Mia · the oldest for 12 days'];
        yield 'outside the team' => [3, 'Customer', 1, true, '3 tickets wait on Customer · the oldest for 1 day · outside the team'];
        yield 'one ticket' => [1, 'you', 2, false, '1 ticket waits on you · for 2 days'];
        yield 'one ticket of today' => [1, 'Omar', 0, false, '1 ticket waits on Omar · since today'];
        yield 'many of today' => [2, 'Omar', 0, false, '2 tickets wait on Omar · the oldest since today'];
        yield 'thousands' => [1200, 'Omar', 70, false, '1,200 tickets wait on Omar · the oldest for 70 days'];
    }

    #[Test]
    #[DataProvider('sentences')]
    public function theSentenceSaysHowManyWaitOnWhomAndForHowLong(int $ticketCount, string $waitsOn, int $oldestWaitDays, bool $isOutsideTheTeam, string $sentence): void
    {
        self::assertSame($sentence, WhoseTurnText::sentenceOf($ticketCount, $waitsOn, $oldestWaitDays, $isOutsideTheTeam, false));
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
        self::assertSame($sentence, WhoseTurnText::sentenceOf($ticketCount, 'Mia', 12, false, true));
    }

    #[Test]
    public function noMatchesAsksToClearTheSearchAndNamesNoPerson(): void
    {
        self::assertStringEndsWith('Clear the search.', WhoseTurnText::NO_MATCHES);
        self::assertStringNotContainsString('person', WhoseTurnText::NO_MATCHES);
    }
}
