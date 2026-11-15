<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Mercure;

use App\Conversation\Api\Event\CommentPosted;
use App\Notification\Api\Event\PingCleared;
use App\Notification\Api\Event\PingClearedReason;
use App\Notification\Api\Event\PingSent;
use App\Notification\Infrastructure\Mercure\QueueTicketPagesRefreshHandler;
use App\Notification\Infrastructure\Mercure\RefreshAttempt;
use App\Notification\Infrastructure\Mercure\RefreshTicketPages;
use App\Tests\Fixtures\Messaging\RecordingMessageBus;
use App\Ticketing\Api\Event\TicketMoved;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class QueueTicketPagesRefreshHandlerTest extends TestCase
{
    private const string ORGANIZATION_ID = '0197a3c0-0000-7000-8000-000000000a01';
    private const string TICKET_ID = '0197a3c0-0000-7000-8000-000000000b01';
    private const string ACTOR_ID = '0197a3c0-0000-7000-8000-000000000c01';
    private const string OTHER_USER_ID = '0197a3c0-0000-7000-8000-000000000c02';
    private const string ITEM_ID = '0197a3c0-0000-7000-8000-000000000d01';

    private RecordingMessageBus $commandBus;

    private QueueTicketPagesRefreshHandler $handler;

    protected function setUp(): void
    {
        $this->commandBus = new RecordingMessageBus();
        $this->handler = new QueueTicketPagesRefreshHandler($this->commandBus);
    }

    #[Test]
    public function aTicketMoveQueuesALeadingRefreshOfIdsOnly(): void
    {
        $this->handler->onTicketEvent(new TicketMoved(self::ORGANIZATION_ID, self::TICKET_ID, 'SHOP-12', self::ITEM_ID, self::OTHER_USER_ID, 'start_work', self::ACTOR_ID, new \DateTimeImmutable('2026-11-06 12:00:00 UTC')));

        $this->assertOneLeadingRefreshWasQueued();
        self::assertStringNotContainsString('SHOP-12', serialize($this->commandBus->dispatched()[0]->getMessage()));
    }

    #[Test]
    public function aConversationEventQueuesALeadingRefresh(): void
    {
        $this->handler->onConversationEvent(new CommentPosted(self::ORGANIZATION_ID, self::TICKET_ID, self::ITEM_ID, self::ACTOR_ID, new \DateTimeImmutable('2026-11-06 12:00:00 UTC')));

        $this->assertOneLeadingRefreshWasQueued();
    }

    #[Test]
    public function aPingEventQueuesALeadingRefresh(): void
    {
        $this->handler->onPingEvent(new PingSent(self::ORGANIZATION_ID, self::TICKET_ID, self::ITEM_ID, self::ACTOR_ID, self::OTHER_USER_ID, self::ACTOR_ID, new \DateTimeImmutable('2026-11-06 12:00:00 UTC')));
        $this->handler->onPingEvent(new PingCleared(self::ORGANIZATION_ID, self::TICKET_ID, self::ITEM_ID, self::ACTOR_ID, self::OTHER_USER_ID, PingClearedReason::Done, self::OTHER_USER_ID, new \DateTimeImmutable('2026-11-06 12:00:00 UTC')));

        self::assertCount(2, $this->commandBus->dispatched());
        foreach ($this->commandBus->dispatched() as $envelope) {
            self::assertEquals(new RefreshTicketPages(self::ORGANIZATION_ID, self::TICKET_ID, RefreshAttempt::Leading), $envelope->getMessage());
        }
    }

    private function assertOneLeadingRefreshWasQueued(): void
    {
        self::assertCount(1, $this->commandBus->dispatched());
        self::assertEquals(new RefreshTicketPages(self::ORGANIZATION_ID, self::TICKET_ID, RefreshAttempt::Leading), $this->commandBus->dispatched()[0]->getMessage());
        self::assertNull($this->commandBus->dispatched()[0]->last(DelayStamp::class), 'the leading try is not delayed');
    }
}
