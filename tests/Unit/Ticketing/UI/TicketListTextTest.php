<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketListGroup;
use App\Ticketing\Api\TicketListRow;
use App\Ticketing\Api\TicketListStep;
use App\Ticketing\UI\TicketListText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TicketListTextTest extends TestCase
{
    private const string LENA = '0190a000-0000-7000-8000-00000000000a';
    private const string OMAR = '0190a000-0000-7000-8000-00000000000b';
    private const string STEP = '0190a000-0000-7000-8000-0000000000c1';

    /**
     * @return iterable<string, array{int, bool, string}>
     */
    public static function counts(): iterable
    {
        yield 'small' => [41, false, '41'];
        yield 'thousands separator' => [1000, false, '1,000'];
        yield 'capped' => [1000, true, '1,000+'];
    }

    #[Test]
    #[DataProvider('counts')]
    public function aCountHasAThousandsSeparatorAndAPlusWhenCapped(int $ticketCount, bool $isCountCapped, string $expected): void
    {
        self::assertSame($expected, TicketListText::countOf($ticketCount, $isCountCapped));
        self::assertSame($expected, TicketListText::stepCountOf(self::stepOf($ticketCount, $isCountCapped)));
    }

    /**
     * @return iterable<string, array{list<TicketListGroup>, string}>
     */
    public static function totalLines(): iterable
    {
        yield 'the shown steps summed' => [
            [new TicketListGroup('wait', 'Waiting', 'Someone must answer first', [self::stepOf(30, false)]), new TicketListGroup('todo', 'To do', 'Not started', [self::stepOf(10, false), self::stepOf(1, false)])],
            '41 tickets · grouped by step',
        ];
        yield 'one ticket' => [[new TicketListGroup('todo', 'To do', 'Not started', [self::stepOf(1, false)])], '1 ticket · grouped by step'];
        yield 'none' => [[], '0 tickets · grouped by step'];
        yield 'one capped step' => [
            [new TicketListGroup('todo', 'To do', 'Not started', [self::stepOf(1000, true), self::stepOf(5, false)])],
            '1,005+ tickets · grouped by step',
        ];
    }

    /**
     * @param list<TicketListGroup> $groups
     */
    #[Test]
    #[DataProvider('totalLines')]
    public function theTotalLineSumsTheStepsAndSaysTicketForOne(array $groups, string $expected): void
    {
        self::assertSame($expected, TicketListText::totalLineOf($groups));
    }

    /**
     * @return iterable<string, array{string|null, string|null, string|null, bool, string}>
     */
    public static function metas(): iterable
    {
        yield 'type, release, the viewer\'s turn' => ['2.4', self::LENA, 'Lena', false, 'Bug · Release 2.4 · Turn: you'];
        yield 'another person\'s turn by first name' => ['2.4', self::OMAR, 'Omar', false, 'Bug · Release 2.4 · Turn: Omar'];
        yield 'no release' => [null, self::OMAR, 'Omar', false, 'Bug · Turn: Omar'];
        yield 'empty release' => ['', self::OMAR, 'Omar', false, 'Bug · Turn: Omar'];
        yield 'nobody has the turn' => ['2.4', null, null, false, 'Bug · Release 2.4'];
        yield 'the turn of a person with no name' => ['2.4', self::OMAR, '', false, 'Bug · Release 2.4'];
        yield 'done has no turn' => ['2.4', self::LENA, 'Lena', true, 'Bug · Release 2.4'];
    }

    #[Test]
    #[DataProvider('metas')]
    public function theMetaLineIsTypeReleaseThenTurnLeavingOutWhatIsNotThere(?string $release, ?string $turnUserId, ?string $turnUserFirstName, bool $isDone, string $expected): void
    {
        $row = self::rowOf('bug', $release, 'major', null === $turnUserId ? null : new UserId($turnUserId), $turnUserFirstName);

        self::assertSame($expected, TicketListText::metaOf($row, new UserId(self::LENA), $isDone));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function priorities(): iterable
    {
        yield 'blocker' => ['blocker', true];
        yield 'critical' => ['critical', true];
        yield 'major' => ['major', false];
        yield 'minor' => ['minor', false];
    }

    #[Test]
    #[DataProvider('priorities')]
    public function onlyBlockerAndCriticalAreUrgent(string $priority, bool $isUrgent): void
    {
        $row = self::rowOf('bug', null, $priority, null, null);

        self::assertSame($isUrgent, TicketListText::isUrgent($row));
        self::assertSame(ucfirst($priority), TicketListText::priorityOf($row));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function updatedTimes(): iterable
    {
        yield 'just now' => ['2026-03-15T10:29:01+00:00', 'now'];
        yield 'ahead of now' => ['2026-03-15T10:31:00+00:00', 'now'];
        yield 'a minute' => ['2026-03-15T10:29:00+00:00', '1 min ago'];
        yield 'minutes' => ['2026-03-15T09:30:01+00:00', '59 min ago'];
        yield 'an hour' => ['2026-03-15T09:30:00+00:00', '1 h ago'];
        yield 'hours' => ['2026-03-14T10:30:01+00:00', '23 h ago'];
        yield 'a day, as the UTC date' => ['2026-03-13T23:30:00-02:00', '14 Mar'];
        yield 'another year' => ['2025-12-31T10:30:00+00:00', '31 Dec 2025'];
    }

    #[Test]
    #[DataProvider('updatedTimes')]
    public function theUpdatedTimeIsRelativeForADayThenTheDate(string $updatedAt, string $expected): void
    {
        $now = new \DateTimeImmutable('2026-03-15T10:30:00+00:00');

        self::assertSame($expected, TicketListText::updatedOf(new \DateTimeImmutable($updatedAt), $now, new \DateTimeZone('UTC')));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function updatedTimesInAViewerZone(): iterable
    {
        yield 'the date moves forward east of UTC' => ['2026-03-12T23:30:00+00:00', '2026-03-15T10:30:00+00:00', 'Europe/Berlin', '13 Mar'];
        yield 'the date moves back west of UTC' => ['2026-03-13T01:30:00+00:00', '2026-03-15T10:30:00+00:00', 'America/New_York', '12 Mar'];
        yield 'the year of an update is the viewer\'s' => ['2025-12-31T23:30:00+00:00', '2026-03-15T10:30:00+00:00', 'Asia/Tokyo', '1 Jan'];
        yield 'the year of now is the viewer\'s' => ['2026-12-30T10:00:00+00:00', '2027-01-01T00:30:00+00:00', 'America/New_York', '30 Dec'];
    }

    #[Test]
    #[DataProvider('updatedTimesInAViewerZone')]
    public function theUpdatedDateIsPrintedInTheViewersTimeZone(string $updatedAt, string $now, string $zone, string $expected): void
    {
        self::assertSame($expected, TicketListText::updatedOf(new \DateTimeImmutable($updatedAt), new \DateTimeImmutable($now), new \DateTimeZone($zone)));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function dates(): iterable
    {
        yield 'a minute ago is still a date' => ['2026-03-15T10:29:00+00:00', 'UTC', '15 Mar'];
        yield 'this year' => ['2026-01-05T10:00:00+00:00', 'UTC', '5 Jan'];
        yield 'another year' => ['2025-11-05T10:00:00+00:00', 'UTC', '5 Nov 2025'];
        yield 'the viewer\'s zone moves the day' => ['2026-03-12T23:30:00+00:00', 'Europe/Berlin', '13 Mar'];
        yield 'the viewer\'s zone moves the year' => ['2025-12-31T23:30:00+00:00', 'Asia/Tokyo', '1 Jan'];
    }

    #[Test]
    #[DataProvider('dates')]
    public function aDateIsDayAndMonthInTheViewersZoneWithTheYearOnlyWhenItIsNotThisOne(string $at, string $zone, string $expected): void
    {
        $now = new \DateTimeImmutable('2026-03-15T10:30:00+00:00');

        self::assertSame($expected, TicketListText::dateOf(new \DateTimeImmutable($at), $now, new \DateTimeZone($zone)));
    }

    private static function stepOf(int $ticketCount, bool $isCountCapped): TicketListStep
    {
        return new TicketListStep(self::STEP, 'To do', false, $ticketCount, $isCountCapped, [], null);
    }

    private static function rowOf(string $type, ?string $release, string $priority, ?UserId $turnUserId, ?string $turnUserFirstName): TicketListRow
    {
        return new TicketListRow(
            new TicketId('0190a000-0000-7000-8000-0000000000d1'),
            'SHOP-1',
            'Checkout button does nothing',
            $type,
            $release,
            $priority,
            null,
            null,
            $turnUserId,
            $turnUserFirstName,
            new \DateTimeImmutable('2026-03-15T10:00:00+00:00'),
            self::STEP,
        );
    }
}
