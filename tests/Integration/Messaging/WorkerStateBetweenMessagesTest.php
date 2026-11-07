<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Messenger\MessageBeingHandled;
use App\SharedKernel\Infrastructure\Messenger\MessageIdStamp;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use App\Tests\Fixtures\Messaging\RecordHandlerSighting;
use App\Tests\Fixtures\Messaging\RecordHandlerSightingHandler;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

/**
 * The state-leak test of P00.T7: one worker loop handles a message of organization A and then one of B, and the B
 * handler sees nothing of A (ADR-0005, ADR-0008). The loop runs without the worker's service reset, so the
 * middleware's own cleanup is what is proven.
 */
final class WorkerStateBetweenMessagesTest extends KernelTestCase
{
    use FillsTheTenantProbeTable;
    use RunsTheWorkerUntilIdle;

    public static function setUpBeforeClass(): void
    {
        self::recreateTheTenantProbeTableWithRowsOfAAndB();
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::dropTheTenantProbeTable();
        self::ensureKernelShutdown();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        // Rows a failed earlier run left; the test database only.
        self::getContainer()->get('doctrine.dbal.default_connection')->executeStatement('DELETE FROM messenger_failed');
    }

    #[Test]
    public function theMessageOfBSeesNothingOfTheMessageOfABeforeIt(): void
    {
        $this->queueSightingsOfAThenB(isAFailing: false);

        self::runTheWorkerUntilIdle('test_queue');

        $this->assertTheSightingsOfAThenB();
        $this->assertNothingLeftAfterTheLoop();
    }

    #[Test]
    public function theMessageOfBRunsCleanAfterTheHandlerOfAThrew(): void
    {
        $this->queueSightingsOfAThenB(isAFailing: true);

        self::runTheWorkerUntilIdle('test_queue');

        $this->assertTheSightingsOfAThenB();
        $this->assertNothingLeftAfterTheLoop();
        $failed = self::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(ListableReceiverInterface::class, $failed);
        $failedEnvelopes = iterator_to_array($failed->all(), false);
        self::assertCount(1, $failedEnvelopes);
        self::assertSame(self::ORG_A, $failedEnvelopes[0]->last(TenantStamp::class)?->organizationId()->toString());
        self::assertSame(UnrecoverableMessageHandlingException::class, $failedEnvelopes[0]->last(ErrorDetailsStamp::class)?->getExceptionClass());
    }

    private function queueSightingsOfAThenB(bool $isAFailing): void
    {
        $commandBus = self::getContainer()->get('command.bus');
        $commandBus->dispatch(new RecordHandlerSighting(isFailingAfterLoading: $isAFailing), [
            new TenantStamp(new OrganizationId(self::ORG_A)),
            new TransportNamesStamp(['test_queue']),
        ]);
        $commandBus->dispatch(new RecordHandlerSighting(), [
            new TenantStamp(new OrganizationId(self::ORG_B)),
            new TransportNamesStamp(['test_queue']),
        ]);
    }

    private function assertTheSightingsOfAThenB(): void
    {
        $testQueue = self::getContainer()->get('messenger.transport.test_queue');
        self::assertInstanceOf(InMemoryTransport::class, $testQueue);
        $queued = $testQueue->getSent();
        self::assertCount(2, $queued);
        $messageIdOfA = $queued[0]->last(MessageIdStamp::class)?->messageId();
        $messageIdOfB = $queued[1]->last(MessageIdStamp::class)?->messageId();
        self::assertIsString($messageIdOfA);
        self::assertIsString($messageIdOfB);
        self::assertNotSame($messageIdOfA, $messageIdOfB);

        // A's two probes stay loaded when its handler returns; only the middleware's cleanup can empty the map for B.
        self::assertSame([
            [
                'organizationId' => self::ORG_A,
                'labels' => ['a-first', 'a-second'],
                'messageId' => $messageIdOfA,
                'identityMapSizeAtStart' => 0,
                'identityMapSizeAfterLoading' => 2,
            ],
            [
                'organizationId' => self::ORG_B,
                'labels' => ['b-only'],
                'messageId' => $messageIdOfB,
                'identityMapSizeAtStart' => 0,
                'identityMapSizeAfterLoading' => 1,
            ],
        ], $this->sightingHandler()->sightings());
    }

    private function assertNothingLeftAfterTheLoop(): void
    {
        $container = self::getContainer();
        self::assertNull($container->get(TenantContext::class)->get());
        self::assertNull($container->get(MessageBeingHandled::class)->messageId());
        self::assertSame(0, $container->get('doctrine.orm.default_entity_manager')->getUnitOfWork()->size());
        self::assertFalse($container->get('doctrine.dbal.default_connection')->isTransactionActive());
    }

    private function sightingHandler(): RecordHandlerSightingHandler
    {
        $handler = self::getContainer()->get(RecordHandlerSightingHandler::class);
        self::assertInstanceOf(RecordHandlerSightingHandler::class, $handler);

        return $handler;
    }
}
