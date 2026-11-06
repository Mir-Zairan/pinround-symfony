<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use App\Tests\Fixtures\Messaging\OutboxProbeRecorded;
use App\Tests\Fixtures\Messaging\SaveProbeNote;
use App\Tests\Fixtures\Messaging\SaveProbeNoteHandler;
use App\Tests\Fixtures\Messaging\UnreachableProbeRecorded;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

/**
 * The outbox of ADR-0009 on the real event bus, PostgreSQL tables and worker loop: an event is saved with the
 * transaction that records it, and the relay moves it to its async_* transport once, or keeps it until it lands in
 * failed. In the test env async_notify is in-memory (messenger.yaml, when@test routes the probe events).
 */
final class OutboxRelayTest extends KernelTestCase
{
    use RunsTheWorkerUntilIdle;

    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';
    private const string PROBE_ID = '0197a3c0-0000-7000-8000-000000000201';

    private Connection $connection;
    private MessageBusInterface $eventBus;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->connection = $container->get('doctrine.dbal.default_connection');
        $this->eventBus = $container->get('event.bus');

        // Rows a failed earlier run left; the test database only.
        $this->connection->executeStatement('DELETE FROM messenger_outbox');
        $this->connection->executeStatement('DELETE FROM messenger_failed');
    }

    #[Test]
    public function anEventRecordedInsideATransactionThatRollsBackIsNeverDelivered(): void
    {
        $this->connection->beginTransaction();
        $this->recordProbeEvent();
        // Inside the transaction the row is there, so the rollback below is what removes it.
        self::assertSame(1, $this->outboxRowCount());
        $this->connection->rollBack();

        self::assertSame(0, $this->outboxRowCount());
        self::runTheWorkerUntilIdle('outbox');
        self::assertSame([], $this->asyncNotify()->getSent());
        self::assertSame([], $this->failedEnvelopes());
    }

    #[Test]
    public function anEventRecordedInsideACommittedTransactionIsRelayedOnceWithItsStamps(): void
    {
        $this->connection->beginTransaction();
        $this->recordProbeEvent();
        $this->connection->commit();
        self::assertSame(1, $this->outboxRowCount());

        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('outbox');

        self::assertSame(0, $this->outboxRowCount());
        $relayed = $this->asyncNotify()->getSent();
        self::assertCount(1, $relayed);
        self::assertEquals(new OutboxProbeRecorded(self::PROBE_ID), $relayed[0]->getMessage());
        self::assertSame(self::ORG_A, $relayed[0]->last(TenantStamp::class)?->organizationId()->toString());
        self::assertSame('event.bus', $relayed[0]->last(BusNameStamp::class)?->getBusName());
        // Only async_notify's own id: the outbox row id and the outbox's ReceivedStamp stay behind.
        self::assertCount(1, $relayed[0]->all(TransportMessageIdStamp::class));
        self::assertSame([], $relayed[0]->all(ReceivedStamp::class));
        self::assertSame([], $this->failedEnvelopes());
    }

    #[Test]
    public function aRelayThatCannotReachValkeyKeepsTheEventUntilItLandsInFailed(): void
    {
        $this->eventBus->dispatch(new UnreachableProbeRecorded(self::PROBE_ID), [new TenantStamp(new OrganizationId(self::ORG_A))]);

        self::runTheWorkerUntilIdle('outbox');

        self::assertSame(0, $this->outboxRowCount());
        $failed = $this->failedEnvelopes();
        self::assertCount(1, $failed);
        self::assertEquals(new UnreachableProbeRecorded(self::PROBE_ID), $failed[0]->getMessage());
        // All 10 of the outbox's retries, then the 0 Messenger stamps on a message it moves to the failure transport.
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 0], self::retryCounts($failed[0]));
        self::assertTrue(is_a((string) $failed[0]->last(ErrorDetailsStamp::class)?->getExceptionClass(), TransportException::class, true));
        self::assertSame(self::ORG_A, $failed[0]->last(TenantStamp::class)?->organizationId()->toString());
    }

    #[Test]
    public function aMessageInTheOutboxWithoutARelayRouteGoesToFailedAtOnceAndIsNotHandled(): void
    {
        $this->eventBus->dispatch(new SaveProbeNote('no-route'), [new TransportNamesStamp(['outbox'])]);
        self::assertSame(1, $this->outboxRowCount());

        self::runTheWorkerUntilIdle('outbox');

        self::assertSame(0, $this->outboxRowCount());
        $failed = $this->failedEnvelopes();
        self::assertCount(1, $failed);
        self::assertSame([0], self::retryCounts($failed[0]));
        self::assertSame(UnrecoverableMessageHandlingException::class, $failed[0]->last(ErrorDetailsStamp::class)?->getExceptionClass());
        $handler = self::getContainer()->get(SaveProbeNoteHandler::class);
        self::assertInstanceOf(SaveProbeNoteHandler::class, $handler);
        self::assertSame(0, $handler->attemptCount());
    }

    private function recordProbeEvent(): void
    {
        $this->eventBus->dispatch(new OutboxProbeRecorded(self::PROBE_ID), [new TenantStamp(new OrganizationId(self::ORG_A))]);
    }

    /**
     * @return list<int>
     */
    private static function retryCounts(Envelope $envelope): array
    {
        return array_map(static fn (RedeliveryStamp $stamp): int => $stamp->getRetryCount(), $envelope->all(RedeliveryStamp::class));
    }

    private function outboxRowCount(): int
    {
        $count = $this->connection->fetchOne("SELECT count(*) FROM messenger_outbox WHERE queue_name = 'outbox'");
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @return list<Envelope>
     */
    private function failedEnvelopes(): array
    {
        $failed = self::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(ListableReceiverInterface::class, $failed);

        return iterator_to_array($failed->all(), false);
    }

    private function asyncNotify(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
