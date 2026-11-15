<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Digest;

use App\Notification\Application\Digest\Digest;
use App\Notification\Application\Digest\DigestItem;
use App\Notification\Application\Notify\NotificationKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DigestTest extends TestCase
{
    private const string COVERED_UNTIL = '2027-03-20 09:00:00+00:00';

    #[Test]
    public function aDigestCoversItemsAtLeastAnHourOld(): void
    {
        self::assertEquals(
            new \DateTimeImmutable('2027-03-20 09:15:00+00:00'),
            Digest::coveredUntilAt(new \DateTimeImmutable('2027-03-20 10:15:00+00:00')),
        );
    }

    #[Test]
    public function theItemsPastTheListedOnesAreUnlisted(): void
    {
        $digest = new Digest([self::item(), self::item()], 5, new \DateTimeImmutable(self::COVERED_UNTIL));

        self::assertSame(3, $digest->unlistedCount());
        self::assertFalse($digest->isEmpty());
    }

    #[Test]
    public function aDigestWithNoItemIsEmpty(): void
    {
        $digest = new Digest([], 0, new \DateTimeImmutable(self::COVERED_UNTIL));

        self::assertTrue($digest->isEmpty());
        self::assertSame(0, $digest->unlistedCount());
    }

    #[Test]
    public function aDigestListsAtMostTwentyItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A digest lists at most 20 items and counts at least the listed ones.');

        new Digest(array_map(self::item(...), range(1, 21)), 21, new \DateTimeImmutable(self::COVERED_UNTIL));
    }

    #[Test]
    public function aDigestCountsAtLeastTheItemsItLists(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A digest lists at most 20 items and counts at least the listed ones.');

        new Digest([self::item(), self::item()], 1, new \DateTimeImmutable(self::COVERED_UNTIL));
    }

    private static function item(): DigestItem
    {
        return new DigestItem('Raj', NotificationKind::Question, 'SHOP-12', 'Checkout button is hidden', null, new \DateTimeImmutable('2027-03-20 08:00:00+00:00'));
    }
}
