<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\SharedKernel\Application\NoMessageBeingHandled;
use App\SharedKernel\Application\OnceOnlyHandling;
use App\SharedKernel\Infrastructure\Messenger\MessageIdStamp;
use App\Tests\Fixtures\Messaging\RecordProbeEffect;
use App\Tests\Fixtures\Messaging\RecordProbeEffectHandler;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * ADR-0009's idempotent async handler on the real command bus, worker loop and processed_message table: a message
 * delivered twice runs the effect once, two messages run it twice, and an effect that fails rolls back with its
 * processed_message row so the retry runs it. test_queue serializes each message, as a real queue does.
 */
final class OnceOnlyHandlingTest extends KernelTestCase
{
    use RunsTheWorkerUntilIdle;

    private const string PROBE_ID = 'probe-1';

    private Connection $migrator;

    protected function setUp(): void
    {
        // pinround_app cannot create tables, so the migrator makes the handler's effect table for this class only.
        $this->migrator = self::getContainer()->get('doctrine.migrations.dependency_factory')->getConnection();
        $this->migrator->executeStatement('CREATE TABLE IF NOT EXISTS messaging_probe_effect (probe_id TEXT NOT NULL)');
        $this->migrator->executeStatement('GRANT SELECT, INSERT ON messaging_probe_effect TO pinround_app');
        $this->migrator->executeStatement('DELETE FROM messaging_probe_effect');
        $this->migrator->executeStatement('DELETE FROM processed_message WHERE handler = ?', [RecordProbeEffectHandler::HANDLER_NAME]);
    }

    protected function tearDown(): void
    {
        $this->migrator->executeStatement('DROP TABLE IF EXISTS messaging_probe_effect');
        $this->migrator->executeStatement('DELETE FROM processed_message WHERE handler = ?', [RecordProbeEffectHandler::HANDLER_NAME]);
        parent::tearDown();
    }

    #[Test]
    public function deliveringTheSameMessageTwiceRunsTheHandlersEffectOnce(): void
    {
        $this->sendProbeToTheQueue();
        $queue = $this->testQueue();
        $queue->send($queue->getSent()[0]);

        self::runTheWorkerUntilIdle('test_queue');

        $deliveries = $queue->getSent();
        self::assertCount(2, $deliveries);
        $messageId = self::messageIdOf($deliveries[0]);
        self::assertSame($messageId, self::messageIdOf($deliveries[1]));
        self::assertSame(2, $this->probeHandler()->callCount());
        self::assertSame(1, $this->effectCount());
        self::assertSame(1, $this->processedRowCount([$messageId]));
    }

    #[Test]
    public function twoMessagesWithTheSameContentRunTheEffectTwice(): void
    {
        $this->sendProbeToTheQueue();
        $this->sendProbeToTheQueue();

        self::runTheWorkerUntilIdle('test_queue');

        $messageIds = array_values(array_map(self::messageIdOf(...), $this->testQueue()->getSent()));
        self::assertCount(2, array_unique($messageIds));
        self::assertSame(2, $this->probeHandler()->callCount());
        self::assertSame(2, $this->effectCount());
        self::assertSame(2, $this->processedRowCount($messageIds));
    }

    /**
     * Had the processed_message row stayed, the retry would skip the effect (0 rows); had the effect row stayed, the
     * retry would write a second one.
     */
    #[Test]
    public function anEffectThatFailsAfterWritingRollsBackWithItsRecordAndTheRetryRunsIt(): void
    {
        $this->probeHandler()->failNextEffectAfterWriting();
        $this->sendProbeToTheQueue();

        self::runTheWorkerUntilIdle('test_queue');

        $deliveries = $this->testQueue()->getSent();
        self::assertCount(2, $deliveries, 'The failed delivery is retried once.');
        self::assertSame(self::messageIdOf($deliveries[0]), self::messageIdOf($deliveries[1]));
        self::assertSame(2, $this->probeHandler()->callCount());
        self::assertSame(1, $this->effectCount());
        self::assertSame(1, $this->processedRowCount([self::messageIdOf($deliveries[0])]));
    }

    #[Test]
    public function runningOnceOutsideTheHandlingOfAMessageIsRefused(): void
    {
        $onceOnlyHandling = self::getContainer()->get(OnceOnlyHandling::class);
        $hasEffectRun = false;

        try {
            $onceOnlyHandling->runOnce(RecordProbeEffectHandler::HANDLER_NAME, static function () use (&$hasEffectRun): void {
                $hasEffectRun = true;
            });
            self::fail('runOnce ran without a message being handled.');
        } catch (NoMessageBeingHandled) {
        }

        self::assertFalse($hasEffectRun);
        self::assertSame(0, $this->rowCount('SELECT count(*) FROM processed_message WHERE handler = ?', [RecordProbeEffectHandler::HANDLER_NAME]));
    }

    #[Test]
    public function anEmptyHandlerNameIsRefused(): void
    {
        $onceOnlyHandling = self::getContainer()->get(OnceOnlyHandling::class);

        $this->expectException(\InvalidArgumentException::class);
        $onceOnlyHandling->runOnce('', static function (): void {
        });
    }

    private function sendProbeToTheQueue(): void
    {
        self::getContainer()->get('command.bus')->dispatch(new RecordProbeEffect(self::PROBE_ID), [new TransportNamesStamp(['test_queue'])]);
    }

    private static function messageIdOf(Envelope $envelope): string
    {
        $stamp = $envelope->last(MessageIdStamp::class);
        self::assertInstanceOf(MessageIdStamp::class, $stamp);

        return $stamp->messageId();
    }

    private function effectCount(): int
    {
        return $this->rowCount('SELECT count(*) FROM messaging_probe_effect WHERE probe_id = ?', [self::PROBE_ID]);
    }

    /**
     * @param list<string> $messageIds
     */
    private function processedRowCount(array $messageIds): int
    {
        return $this->rowCount(
            'SELECT count(*) FROM processed_message WHERE handler = ? AND message_id = ANY (?::uuid[])',
            [RecordProbeEffectHandler::HANDLER_NAME, '{'.implode(',', $messageIds).'}'],
        );
    }

    /**
     * @param list<string> $parameters
     */
    private function rowCount(string $countQuery, array $parameters): int
    {
        $count = $this->migrator->fetchOne($countQuery, $parameters);
        self::assertIsInt($count);

        return $count;
    }

    private function probeHandler(): RecordProbeEffectHandler
    {
        $handler = self::getContainer()->get(RecordProbeEffectHandler::class);
        self::assertInstanceOf(RecordProbeEffectHandler::class, $handler);

        return $handler;
    }

    private function testQueue(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.test_queue');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
