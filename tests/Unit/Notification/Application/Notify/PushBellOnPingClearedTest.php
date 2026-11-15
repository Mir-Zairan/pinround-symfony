<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Notify;

use App\Notification\Api\Event\PingCleared;
use App\Notification\Api\Event\PingClearedReason;
use App\Notification\Application\Notify\PushBellOnPingCleared;
use App\Notification\Application\Realtime\RefreshBell;
use App\Tests\Unit\Notification\Application\Realtime\BellRefreshesDouble;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PushBellOnPingClearedTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000a01';
    private const string SHOP_1 = '0197a3c0-0000-7000-8000-000000000b01';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000c01';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000c02';
    private const string PING = '0197a3c0-0000-7000-8000-000000000d01';

    #[Test]
    public function aPingClearedEitherWayRefreshesTheBellOfThePersonPingedWithNoToastAtOnce(): void
    {
        $bellRefreshes = new BellRefreshesDouble();
        $handler = new PushBellOnPingCleared($bellRefreshes);

        $handler(new PingCleared(self::ACME_FOODS, self::SHOP_1, self::PING, self::LENA, self::OMAR, PingClearedReason::Done, self::OMAR, new \DateTimeImmutable('2027-03-17 12:00:00 UTC')));
        $handler(new PingCleared(self::ACME_FOODS, self::SHOP_1, self::PING, self::LENA, self::OMAR, PingClearedReason::Acted, self::OMAR, new \DateTimeImmutable('2027-03-17 12:05:00 UTC')));

        self::assertEquals([
            [new RefreshBell(self::ACME_FOODS, self::OMAR, null), null],
            [new RefreshBell(self::ACME_FOODS, self::OMAR, null), null],
        ], $bellRefreshes->sent);
    }
}
