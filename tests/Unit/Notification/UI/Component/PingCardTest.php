<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\UI\Component;

use App\Notification\Api\BellGroup;
use App\Notification\UI\Component\PingCard;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The words on one bell card, read on 20 March 2027 at 10:00 UTC.
 */
final class PingCardTest extends TestCase
{
    private const string NOW = '2027-03-20 10:00:00+00:00';

    /**
     * @return iterable<string, array{positive-int, bool, string, string}> size, all pings, newest kind, label
     */
    public static function groupsAndLabels(): iterable
    {
        yield 'one ping' => [1, true, 'ping', 'pinged you'];
        yield 'one question' => [1, false, 'question', 'asked you'];
        yield 'one answer' => [1, false, 'answer', 'answered you'];
        yield 'one reply' => [1, false, 'reply', 'replied to you'];
        yield 'one assignment' => [1, false, 'assigned', 'gave you a ticket'];
        yield 'one shared round' => [1, false, 'round_shared', 'shared a round'];
        yield 'one duplicate close' => [1, false, 'closed_duplicate', 'closed your report as a duplicate'];
        yield 'one of a kind with no phrase' => [1, false, 'your_turn', 'notified you'];
        yield 'two pings' => [2, true, 'ping', '2 pings'];
        yield 'twelve pings' => [12, true, 'ping', '12 pings'];
        yield 'a ping and a question, the newest a ping' => [2, false, 'ping', '2 notifications'];
        yield 'three mixed, the newest a question' => [3, false, 'question', '3 notifications'];
    }

    /**
     * @param positive-int $size
     */
    #[Test]
    #[DataProvider('groupsAndLabels')]
    public function theLabelSaysWhatOneDidOrCountsTheGroup(int $size, bool $isAllPings, string $newestKind, string $expectedLabel): void
    {
        self::assertSame($expectedLabel, $this->cardOf(self::groupOf('Lena Lead', $size, $isAllPings, $newestKind, self::NOW))->label());
    }

    /**
     * @return iterable<string, array{string, string}> when the newest came, in UTC, and the age the card shows
     */
    public static function timesAndAges(): iterable
    {
        yield 'this second' => ['2027-03-20 10:00:00', 'now'];
        yield 'a second ahead of now' => ['2027-03-20 10:00:01', 'now'];
        yield '59 seconds ago' => ['2027-03-20 09:59:01', 'now'];
        yield '60 seconds ago' => ['2027-03-20 09:59:00', '1 min ago'];
        yield '3599 seconds ago' => ['2027-03-20 09:00:01', '59 min ago'];
        yield '3600 seconds ago' => ['2027-03-20 09:00:00', '1 h ago'];
        yield '86399 seconds ago' => ['2027-03-19 10:00:01', '23 h ago'];
        yield '86400 seconds ago' => ['2027-03-19 10:00:00', '1 day ago'];
        yield 'just under two days ago' => ['2027-03-18 10:00:01', '1 day ago'];
        yield 'two days ago' => ['2027-03-18 10:00:00', '2 days ago'];
        yield 'nine days ago' => ['2027-03-11 09:30:00', '9 days ago'];
    }

    #[Test]
    #[DataProvider('timesAndAges')]
    public function theAgeCountsWholeMinutesHoursOrDaysSinceTheNewest(string $latestAtInUtc, string $expectedAge): void
    {
        self::assertSame($expectedAge, $this->cardOf(self::groupOf('Lena', 1, true, 'ping', $latestAtInUtc.'+00:00'))->age());
    }

    #[Test]
    public function theAgeComparesInstantsNotWallClockTimes(): void
    {
        $latestAtInBerlin = new \DateTimeImmutable('2027-03-20 10:30:00+01:00');

        self::assertSame('30 min ago', PingCard::ageOf($latestAtInBerlin, new \DateTimeImmutable(self::NOW)));
    }

    /**
     * @return iterable<string, array{string, string}> the sender's name in the snapshot and the first name the card shows
     */
    public static function namesAndFirstNames(): iterable
    {
        yield 'a full name' => ['Lena Lead', 'Lena'];
        yield 'one name' => ['Omar', 'Omar'];
        yield 'spaces around it' => ['  Mia Tester ', 'Mia'];
        yield 'a multibyte name' => ['Rāj Manager', 'Rāj'];
        yield 'a blank name stays as it is' => ['   ', '   '];
        yield 'an empty name' => ['', ''];
    }

    #[Test]
    #[DataProvider('namesAndFirstNames')]
    public function theCardShowsTheSendersFirstName(string $senderName, string $expectedFirstName): void
    {
        self::assertSame($expectedFirstName, $this->cardOf(self::groupOf($senderName, 1, true, 'ping', self::NOW))->senderFirstName());
    }

    private function cardOf(BellGroup $group): PingCard
    {
        $card = new PingCard(new MockClock(self::NOW));
        $card->group = $group;

        return $card;
    }

    /**
     * @param positive-int $size
     */
    private static function groupOf(string $senderName, int $size, bool $isAllPings, string $newestKind, string $latestAt): BellGroup
    {
        return new BellGroup(
            new UserId('0197a3c0-0000-7000-8000-0000000b3801'),
            $senderName,
            new TicketId('0197a3c0-0000-7000-8000-0000000b3811'),
            'SHOP-1',
            $size,
            $isAllPings,
            $newestKind,
            'ping' === $newestKind ? 'Can you look at the checkout?' : null,
            new \DateTimeImmutable($latestAt),
            true,
        );
    }
}
