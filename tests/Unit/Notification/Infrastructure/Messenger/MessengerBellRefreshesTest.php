<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Messenger;

use App\Notification\Application\Realtime\RefreshBell;
use App\Notification\Infrastructure\Messenger\MessengerBellRefreshes;
use App\Tests\Fixtures\Messaging\RecordingMessageBus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

final class MessengerBellRefreshesTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000007a01';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000007a12';
    private const string NOW = '2027-03-15 10:00:00 UTC';

    private RecordingMessageBus $commandBus;
    private MessengerBellRefreshes $bellRefreshes;

    protected function setUp(): void
    {
        $this->commandBus = new RecordingMessageBus();
        $this->bellRefreshes = new MessengerBellRefreshes($this->commandBus, new MockClock(new \DateTimeImmutable(self::NOW)));
    }

    #[Test]
    public function aRefreshOfANotificationThatIsNotHeldIsDispatchedAfterTheCurrentBus(): void
    {
        $refresh = new RefreshBell(self::ACME_FOODS, self::OMAR, 'Lena answered your question.');

        $this->bellRefreshes->sendOnceCommitted($refresh, null);

        self::assertCount(1, $this->commandBus->dispatched());
        $envelope = $this->commandBus->dispatched()[0];
        self::assertSame($refresh, $envelope->getMessage());
        self::assertNotNull($envelope->last(DispatchAfterCurrentBusStamp::class), 'sent only once the handled message commits');
    }

    #[Test]
    public function aRefreshDeliveredNowOrEarlierIsDispatched(): void
    {
        $this->bellRefreshes->sendOnceCommitted(new RefreshBell(self::ACME_FOODS, self::OMAR, null), new \DateTimeImmutable(self::NOW));
        $this->bellRefreshes->sendOnceCommitted(new RefreshBell(self::ACME_FOODS, self::OMAR, null), new \DateTimeImmutable('2027-03-15 09:59:59 UTC'));

        self::assertCount(2, $this->commandBus->dispatched());
    }

    #[Test]
    public function aRefreshOfANotificationHeldPastNowIsNotDispatched(): void
    {
        $this->bellRefreshes->sendOnceCommitted(new RefreshBell(self::ACME_FOODS, self::OMAR, null), new \DateTimeImmutable('2027-03-15 10:00:01 UTC'));

        self::assertSame([], $this->commandBus->dispatched());
    }
}
