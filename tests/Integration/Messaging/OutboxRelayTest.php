<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use App\Tests\Fixtures\Messaging\OutageProbeRecorded;
use App\Tests\Fixtures\Messaging\OutboxProbeRecorded;
use App\Tests\Fixtures\Messaging\OutboxProbeRecordedHandler;
use App\Tests\Fixtures\Messaging\SaveProbeNote;
use App\Tests\Fixtures\Messaging\SaveProbeNoteHandler;
use App\Tests\Fixtures\Messaging\UnreachableProbeRecorded;
use App\Tests\Fixtures\Messaging\ValkeyWithOutageTransport;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * The outbox of ADR-0009 on the real event bus, PostgreSQL tables and worker loop: an event is saved with the
 * transaction that records it, and the relay moves it to its async_* transport once without handling it, or keeps it
 * until it lands in failed, from where a retry relays it. In the test env async_notify is in-memory and stands in for
 * a reachable Valkey (messenger.yaml, when@test routes the probe events).
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
        self::assertSame(0, $this->probeHandler()->callCount());
    }

    #[Test]
    public function aRelayedEventIsHandledOnceByTheWorkerOfItsTransport(): void
    {
        $this->recordProbeEvent();
        self::runTheWorkerUntilIdle('outbox');
        self::assertSame(0, $this->probeHandler()->callCount());

        self::runTheWorkerUntilIdle('async_notify');
        self::runTheWorkerUntilIdle('async_notify');

        self::assertSame(1, $this->probeHandler()->callCount());
        self::assertSame([], $this->failedEnvelopes());
    }

    /**
     * The operator's way back after Valkey was down: the event lands in failed through the outbox worker's own retries,
     * and once Valkey is back messenger:failed:retry relays it to its target as if it came from the outbox, without
     * handling it.
     */
    #[Test]
    public function anEventRetriedFromFailedOnceValkeyIsBackIsRelayedToItsTransportAndNotHandled(): void
    {
        $this->eventBus->dispatch(new OutageProbeRecorded(self::PROBE_ID), [new TenantStamp(new OrganizationId(self::ORG_A))]);
        self::runTheWorkerUntilIdle('outbox');
        $failed = $this->failedEnvelopes();
        self::assertCount(1, $failed);
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 0], self::retryCounts($failed[0]));
        self::assertSame([], $this->valkeyWithOutage()->getSent());
        $failedMessageId = $failed[0]->last(TransportMessageIdStamp::class)?->getId();
        self::assertIsInt($failedMessageId);

        $this->valkeyWithOutage()->bringBack();
        $retry = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('messenger:failed:retry'));
        $retry->execute(['id' => [(string) $failedMessageId], '--force' => true], ['interactive' => false]);

        $retry->assertCommandIsSuccessful();
        $relayed = $this->valkeyWithOutage()->getSent();
        self::assertCount(1, $relayed);
        self::assertEquals(new OutageProbeRecorded(self::PROBE_ID), $relayed[0]->getMessage());
        self::assertSame(self::ORG_A, $relayed[0]->last(TenantStamp::class)?->organizationId()->toString());
        self::assertSame([], $relayed[0]->all(SentToFailureTransportStamp::class));
        self::assertSame([], $relayed[0]->all(ErrorDetailsStamp::class));
        self::assertSame([], $relayed[0]->all(RedeliveryStamp::class));
        self::assertSame([], $this->failedEnvelopes());
        self::assertSame(0, $this->outboxRowCount());
        self::assertSame(0, $this->probeHandler()->callCount());
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
        return iterator_to_array($this->failedTransport()->all(), false);
    }

    private function failedTransport(): ListableReceiverInterface&SenderInterface
    {
        $failed = self::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(ListableReceiverInterface::class, $failed);

        return $failed;
    }

    private function probeHandler(): OutboxProbeRecordedHandler
    {
        $handler = self::getContainer()->get(OutboxProbeRecordedHandler::class);
        self::assertInstanceOf(OutboxProbeRecordedHandler::class, $handler);

        return $handler;
    }

    private function asyncNotify(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function valkeyWithOutage(): ValkeyWithOutageTransport
    {
        $transport = self::getContainer()->get('messenger.transport.valkey_with_outage');
        self::assertInstanceOf(ValkeyWithOutageTransport::class, $transport);

        return $transport;
    }
}
