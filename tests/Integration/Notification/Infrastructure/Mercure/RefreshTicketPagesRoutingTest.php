<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Mercure;

use App\Notification\Infrastructure\Mercure\RealtimeTopics;
use App\Notification\Infrastructure\Mercure\RefreshAttempt;
use App\Notification\Infrastructure\Mercure\RefreshTicketPages;
use App\Notification\Infrastructure\Mercure\RefreshTicketPagesHandler;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\Event\TicketMoved;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Two moves of one ticket through the real event.bus, outbox, relay, async_notify routing and window locks on the
 * state Valkey of the tests: worker-notify publishes one refresh and leaves one Trailing try waiting until the window
 * of that refresh ends, read from the window lock's PTTL. The clock is fixed on 15 Mar 2027, so the in-memory
 * async_notify keeps the delayed try waiting whatever the wall clock says.
 */
final class RefreshTicketPagesRoutingTest extends KernelTestCase
{
    use RunsTheWorkerUntilIdle;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000007a01';
    private const string SHOP_12 = '0197a3c0-0000-7000-8000-000000007b12';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000007c02';
    private const string TO_DO_STEP = '0197a3c0-0000-7000-8000-000000007d01';
    private const string DOING_STEP = '0197a3c0-0000-7000-8000-000000007d02';
    private const string NOW = '2027-03-15 10:00:00 UTC';

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        self::getContainer()->set('mercure.hub.default.traceable', new MockHub(
            'https://localhost/.well-known/mercure',
            new StaticTokenProvider('publisher-token'),
            function (Update $update): string {
                $this->published[] = $update;

                return 'urn:uuid:published';
            },
        ));
        $this->forgetWindows();
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
    }

    protected function tearDown(): void
    {
        $this->forgetWindows();
        self::getContainer()->get('doctrine')->resetManager();
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
        parent::tearDown();
    }

    #[Test]
    public function twoRelayedMovesPublishOneRefreshAndLeaveOneTrailingTryWaitingOnAsyncNotify(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $eventBus = self::getContainer()->get('event.bus');
            $eventBus->dispatch($this->shop12Moved(self::TO_DO_STEP, self::DOING_STEP));
            $eventBus->dispatch($this->shop12Moved(self::DOING_STEP, self::TO_DO_STEP));
        });

        self::runTheWorkerUntilIdle('outbox');
        self::assertSame([TicketMoved::class, TicketMoved::class], $this->classesOf($this->asyncNotify()->getSent()));
        self::runTheWorkerUntilIdle('async_notify');

        self::assertCount(1, $this->published);
        self::assertSame([RealtimeTopics::ofTicket(new OrganizationId(self::ACME_FOODS), new TicketId(self::SHOP_12))], $this->published[0]->getTopics());
        self::assertSame([RefreshTicketPagesHandler::SIGNAL_DATA, true, RefreshTicketPagesHandler::SIGNAL_TYPE], [$this->published[0]->getData(), $this->published[0]->isPrivate(), $this->published[0]->getType()]);

        $sentRefreshes = array_values(array_filter($this->asyncNotify()->getSent(), static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof RefreshTicketPages));
        self::assertSame([RefreshAttempt::Leading, RefreshAttempt::Leading, RefreshAttempt::Trailing], array_map(self::attemptOf(...), $sentRefreshes));
        $handled = array_map(self::attemptOf(...), array_values(array_filter($this->asyncNotify()->getAcknowledged(), static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof RefreshTicketPages)));
        self::assertSame([RefreshAttempt::Leading, RefreshAttempt::Leading], $handled);
        self::assertSame([], $this->asyncNotify()->getRejected());

        $waiting = $sentRefreshes[2];
        $delayMilliseconds = $waiting->last(DelayStamp::class)?->getDelay() ?? self::fail('The Trailing try has no delay.');
        $windowMillisecondsLeftNow = self::getContainer()->get('state_valkey.connection')->pttl('realtime-refresh:'.self::SHOP_12);
        self::assertGreaterThan(0, $windowMillisecondsLeftNow, 'The window of the published refresh is still open.');
        self::assertGreaterThanOrEqual($windowMillisecondsLeftNow + RefreshTicketPagesHandler::WINDOW_END_MARGIN_MILLISECONDS, $delayMilliseconds, 'The Trailing try waits until the window ends.');
        self::assertLessThanOrEqual(RefreshTicketPagesHandler::WINDOW_MILLISECONDS + RefreshTicketPagesHandler::WINDOW_END_MARGIN_MILLISECONDS, $delayMilliseconds);
        self::assertSame([], [...$this->asyncNotify()->get()], 'The Trailing try is not due before its window ends.');
    }

    private function shop12Moved(string $fromStepId, string $toStepId): TicketMoved
    {
        return new TicketMoved(self::ACME_FOODS, self::SHOP_12, 'SHOP-12', $fromStepId, $toStepId, 'start_work', self::OMAR, new \DateTimeImmutable(self::NOW));
    }

    private static function attemptOf(Envelope $envelope): RefreshAttempt
    {
        $refresh = $envelope->getMessage();
        self::assertInstanceOf(RefreshTicketPages::class, $refresh);

        return $refresh->attempt;
    }

    /**
     * @param iterable<Envelope> $envelopes
     *
     * @return list<class-string>
     */
    private function classesOf(iterable $envelopes): array
    {
        $classes = [];
        foreach ($envelopes as $envelope) {
            $classes[] = $envelope->getMessage()::class;
        }

        return $classes;
    }

    /** The lock keys RefreshTicketPagesHandler takes on the state Valkey, which outlive the kernel between tests. */
    private function forgetWindows(): void
    {
        self::getContainer()->get('state_valkey.connection')->del('realtime-refresh:'.self::SHOP_12, 'realtime-refresh-trailing:'.self::SHOP_12);
    }

    private function asyncNotify(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }
}
