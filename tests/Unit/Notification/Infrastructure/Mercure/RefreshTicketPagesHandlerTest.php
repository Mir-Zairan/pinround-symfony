<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Mercure;

use App\Notification\Infrastructure\Mercure\RealtimeTopics;
use App\Notification\Infrastructure\Mercure\RefreshAttempt;
use App\Notification\Infrastructure\Mercure\RefreshTicketPages;
use App\Notification\Infrastructure\Mercure\RefreshTicketPagesHandler;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\Tests\Fixtures\Lock\ClockedLockStore;
use App\Tests\Fixtures\Messaging\RecordingMessageBus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * The refresh signal and its coalescing, on a lock store that expires by a clock fixed on 6 Nov 2026 and moved by hand.
 */
final class RefreshTicketPagesHandlerTest extends TestCase
{
    private const string ORGANIZATION_ID = '0197a3c0-0000-7000-8000-000000000a01';
    private const string TICKET_ID = '0197a3c0-0000-7000-8000-000000000b01';
    private const string OTHER_TICKET_ID = '0197a3c0-0000-7000-8000-000000000b02';

    private MockClock $clock;

    private LockFactory $lockFactory;

    private RecordingMessageBus $commandBus;

    /** @var list<array{atMilliseconds: int, update: Update}> */
    private array $published = [];

    private int $startMicroseconds;

    private RefreshTicketPagesHandler $handler;

    protected function setUp(): void
    {
        $this->clock = new MockClock(new \DateTimeImmutable('2026-11-06 12:00:00 UTC'));
        $this->startMicroseconds = (int) $this->clock->now()->format('Uu');
        $this->lockFactory = new LockFactory(new ClockedLockStore($this->clock));
        $this->commandBus = new RecordingMessageBus();
        $hub = new MockHub('http://app/.well-known/mercure', new StaticTokenProvider('publisher-token'), function (Update $update): string {
            $this->published[] = ['atMilliseconds' => $this->elapsedMilliseconds(), 'update' => $update];

            return 'urn:uuid:published';
        });
        $this->handler = new RefreshTicketPagesHandler($hub, $this->lockFactory, $this->commandBus);
    }

    #[Test]
    public function theSignalIsAPrivateRefreshOnTheTicketTopicWithNoContent(): void
    {
        ($this->handler)(new RefreshTicketPages(self::ORGANIZATION_ID, self::TICKET_ID, RefreshAttempt::Leading));

        self::assertCount(1, $this->published);
        $update = $this->published[0]['update'];
        self::assertSame(['https://pinround.app/o/'.self::ORGANIZATION_ID.'/t/'.self::TICKET_ID], $update->getTopics());
        self::assertSame(RealtimeTopics::ofTicket(new OrganizationId(self::ORGANIZATION_ID), new TicketId(self::TICKET_ID)), $update->getTopics()[0]);
        self::assertSame('{}', $update->getData());
        self::assertTrue($update->isPrivate());
        self::assertSame('refresh', $update->getType());
        self::assertNull($update->getId());
        self::assertSame([], $this->commandBus->dispatched());
    }

    #[Test]
    public function aBurstGivesOneLeadingAndOneTrailingRefresh(): void
    {
        for ($move = 0; $move < 10; ++$move) {
            ($this->handler)(new RefreshTicketPages(self::ORGANIZATION_ID, self::TICKET_ID, RefreshAttempt::Leading));
            $this->clock->sleep(0.1);
        }

        self::assertSame([0], array_column($this->published, 'atMilliseconds'));
        self::assertCount(1, $this->commandBus->dispatched(), 'one trailing try for the whole burst');
        $trailing = $this->commandBus->dispatched()[0];
        self::assertSame(2000, $trailing->last(DelayStamp::class)?->getDelay());
        $trailingRefresh = $trailing->getMessage();
        self::assertInstanceOf(RefreshTicketPages::class, $trailingRefresh);
        self::assertEquals(new RefreshTicketPages(self::ORGANIZATION_ID, self::TICKET_ID, RefreshAttempt::Trailing), $trailingRefresh);

        // The trailing try was queued with the second move, at 100 ms, and arrives 2 s later.
        $this->clock->sleep(1.1);
        ($this->handler)($trailingRefresh);

        self::assertSame([0, 2100], array_column($this->published, 'atMilliseconds'));
        self::assertCount(1, $this->commandBus->dispatched());
    }

    #[Test]
    public function tenMovesASecondPublishAtMostOncePerWindowAndTheLastMoveIsPublished(): void
    {
        /** @var list<array{dueAtMilliseconds: int, envelope: Envelope}> $queued */
        $queued = [];
        $seenDispatches = 0;
        for ($now = 0; $now <= 12_000; $now += 50) {
            self::assertSame($now, $this->elapsedMilliseconds());
            foreach ($queued as $index => $delayed) {
                if ($delayed['dueAtMilliseconds'] === $now) {
                    unset($queued[$index]);
                    $message = $delayed['envelope']->getMessage();
                    self::assertInstanceOf(RefreshTicketPages::class, $message);
                    ($this->handler)($message);
                }
            }
            if ($now < 5000 && 0 === $now % 100) {
                ($this->handler)(new RefreshTicketPages(self::ORGANIZATION_ID, self::TICKET_ID, RefreshAttempt::Leading));
            }
            foreach (\array_slice($this->commandBus->dispatched(), $seenDispatches) as $envelope) {
                $queued[] = ['dueAtMilliseconds' => $now + ($envelope->last(DelayStamp::class)?->getDelay() ?? 0), 'envelope' => $envelope];
            }
            $seenDispatches = \count($this->commandBus->dispatched());
            $this->clock->sleep(0.05);
        }

        $publishedAt = array_column($this->published, 'atMilliseconds');
        self::assertNotEmpty($publishedAt);
        self::assertSame([], $queued, 'every queued try ran');
        self::assertSame(0, $publishedAt[0]);
        for ($index = 1; $index < \count($publishedAt); ++$index) {
            self::assertGreaterThanOrEqual(2000, $publishedAt[$index] - $publishedAt[$index - 1], 'two refreshes in one window: '.implode(', ', $publishedAt));
        }
        self::assertGreaterThan(4900, end($publishedAt), 'the last move, at 4900 ms, is published after it happened');
        self::assertLessThanOrEqual(6, \count($publishedAt));
    }

    #[Test]
    public function aChangeAfterANewerWindowOpenedIsPublishedOneWindowLater(): void
    {
        $this->refreshAsAChangeArrives();
        $this->clock->sleep(0.1);
        $this->refreshAsAChangeArrives();
        $trailing = $this->commandBus->dispatched()[0]->getMessage();
        $this->clock->sleep(1.9);
        $this->refreshAsAChangeArrives();
        // At 2050 ms the window from 2000 ms is held and the trailing try from 100 ms still waits, so this change has
        // no try of its own.
        $this->clock->sleep(0.05);
        $this->refreshAsAChangeArrives();
        self::assertCount(1, $this->commandBus->dispatched());
        $this->clock->sleep(0.05);
        self::assertInstanceOf(RefreshTicketPages::class, $trailing);
        ($this->handler)($trailing);
        self::assertCount(2, $this->commandBus->dispatched());
        $lastTrailing = $this->commandBus->dispatched()[1]->getMessage();
        self::assertInstanceOf(RefreshTicketPages::class, $lastTrailing);
        $this->clock->sleep(2.0);
        ($this->handler)($lastTrailing);

        self::assertSame([0, 2000, 4100], array_column($this->published, 'atMilliseconds'));
    }

    #[Test]
    public function aTrailingTryPublishesNothingWhileTheWindowIsHeldAndWaitsOneMoreWindow(): void
    {
        $window = $this->lockFactory->createLock('realtime-refresh:'.self::TICKET_ID, 2.0, autoRelease: false);
        self::assertTrue($window->acquire());

        ($this->handler)(new RefreshTicketPages(self::ORGANIZATION_ID, self::TICKET_ID, RefreshAttempt::Trailing));

        self::assertSame([], $this->published);
        self::assertTrue($window->isAcquired(), 'the window stays with its holder');
        self::assertCount(1, $this->commandBus->dispatched());
        self::assertEquals(new RefreshTicketPages(self::ORGANIZATION_ID, self::TICKET_ID, RefreshAttempt::LastTrailing), $this->commandBus->dispatched()[0]->getMessage());
        self::assertSame(2000, $this->commandBus->dispatched()[0]->last(DelayStamp::class)?->getDelay());
    }

    #[Test]
    public function aLastTrailingTryPublishesNothingWhileTheWindowIsHeldAndQueuesNothing(): void
    {
        $window = $this->lockFactory->createLock('realtime-refresh:'.self::TICKET_ID, 2.0, autoRelease: false);
        self::assertTrue($window->acquire());

        ($this->handler)(new RefreshTicketPages(self::ORGANIZATION_ID, self::TICKET_ID, RefreshAttempt::LastTrailing));

        self::assertSame([], $this->published);
        self::assertTrue($window->isAcquired());
        self::assertSame([], $this->commandBus->dispatched());
    }

    #[Test]
    public function aLastTrailingTryPublishesOnceTheWindowHasEnded(): void
    {
        $window = $this->lockFactory->createLock('realtime-refresh:'.self::TICKET_ID, 2.0, autoRelease: false);
        self::assertTrue($window->acquire());
        $this->clock->sleep(2.0);

        ($this->handler)(new RefreshTicketPages(self::ORGANIZATION_ID, self::TICKET_ID, RefreshAttempt::LastTrailing));

        self::assertSame([2000], array_column($this->published, 'atMilliseconds'));
    }

    #[Test]
    public function aLeadingTryWhileATrailingOneWaitsQueuesNoSecond(): void
    {
        ($this->handler)(new RefreshTicketPages(self::ORGANIZATION_ID, self::TICKET_ID, RefreshAttempt::Leading));
        ($this->handler)(new RefreshTicketPages(self::ORGANIZATION_ID, self::TICKET_ID, RefreshAttempt::Leading));
        $this->clock->sleep(1.9);
        ($this->handler)(new RefreshTicketPages(self::ORGANIZATION_ID, self::TICKET_ID, RefreshAttempt::Leading));

        self::assertCount(1, $this->published);
        self::assertCount(1, $this->commandBus->dispatched());
    }

    #[Test]
    public function eachTicketHasItsOwnWindow(): void
    {
        ($this->handler)(new RefreshTicketPages(self::ORGANIZATION_ID, self::TICKET_ID, RefreshAttempt::Leading));
        ($this->handler)(new RefreshTicketPages(self::ORGANIZATION_ID, self::OTHER_TICKET_ID, RefreshAttempt::Leading));

        self::assertSame(
            [[RealtimeTopics::ofTicket(new OrganizationId(self::ORGANIZATION_ID), new TicketId(self::TICKET_ID))], [RealtimeTopics::ofTicket(new OrganizationId(self::ORGANIZATION_ID), new TicketId(self::OTHER_TICKET_ID))]],
            array_map(static fn (array $published): array => $published['update']->getTopics(), $this->published),
        );
        self::assertSame([], $this->commandBus->dispatched());
    }

    private function refreshAsAChangeArrives(): void
    {
        ($this->handler)(new RefreshTicketPages(self::ORGANIZATION_ID, self::TICKET_ID, RefreshAttempt::Leading));
    }

    private function elapsedMilliseconds(): int
    {
        return intdiv((int) $this->clock->now()->format('Uu') - $this->startMicroseconds, 1000);
    }
}
