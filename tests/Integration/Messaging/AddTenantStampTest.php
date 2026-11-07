<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use App\Tests\Fixtures\Messaging\DispatchSightingAfterCurrentMessage;
use App\Tests\Fixtures\Messaging\OutboxProbeRecorded;
use App\Tests\Fixtures\Messaging\RecordHandlerSighting;
use App\Tests\Fixtures\Messaging\RecordHandlerSightingHandler;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * AddTenantStampMiddleware on the real buses: a message dispatched while an organization is set carries it to
 * wherever it is handled, through a queue, the outbox relay or dispatch_after_current_bus (ADR-0005).
 */
final class AddTenantStampTest extends KernelTestCase
{
    use FillsTheTenantProbeTable;
    use RunsTheWorkerUntilIdle;

    private const string PROBE_ID = '0197a3c0-0000-7000-8000-000000000401';

    private MessageBusInterface $commandBus;
    private TenantContext $tenantContext;

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
        $container = self::getContainer();
        $this->commandBus = $container->get('command.bus');
        $this->tenantContext = $container->get(TenantContext::class);

        // Rows a failed earlier run left; the test database only.
        $container->get('doctrine.dbal.default_connection')->executeStatement('DELETE FROM messenger_outbox');
    }

    #[Test]
    public function aMessageDispatchedWhileAnOrganizationIsSetIsQueuedAndReceivedWithIt(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));
        $this->commandBus->dispatch(new RecordHandlerSighting(), [new TransportNamesStamp(['test_queue'])]);
        $this->tenantContext->clear();

        // serialize=true: what comes back went through the transport serializer.
        $received = iterator_to_array($this->testQueue()->get(), false);
        self::assertCount(1, $received);
        self::assertSame([self::ORG_A], self::stampedOrganizationIds($received[0]));
    }

    #[Test]
    public function aMessageDispatchedWithNoOrganizationSetIsQueuedWithoutATenantStamp(): void
    {
        $this->commandBus->dispatch(new RecordHandlerSighting(), [new TransportNamesStamp(['test_queue'])]);

        $received = iterator_to_array($this->testQueue()->get(), false);
        self::assertCount(1, $received);
        self::assertSame([], self::stampedOrganizationIds($received[0]));
    }

    #[Test]
    public function aMessageThatAlreadyHasATenantStampIsNotStampedAgain(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));
        $this->commandBus->dispatch(new RecordHandlerSighting(), [
            new TenantStamp(new OrganizationId(self::ORG_A)),
            new TransportNamesStamp(['test_queue']),
        ]);
        $this->tenantContext->clear();

        self::assertSame([self::ORG_A], self::stampedOrganizationIds($this->testQueue()->getSent()[0]));
    }

    /**
     * A received message runs under what it was sent with; the stamp is the dispatcher's, not the receiver's.
     */
    #[Test]
    public function aReceivedMessageWithoutATenantStampIsNotStamped(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));

        $handled = $this->commandBus->dispatch(new Envelope(new RecordHandlerSighting(), [new ReceivedStamp('test_queue')]));
        // Unstamped, the handler's entities stay loaded under the organization set, as in a request.
        self::getContainer()->get('doctrine.orm.default_entity_manager')->clear();
        $this->tenantContext->clear();

        self::assertSame([], self::stampedOrganizationIds($handled));
        self::assertCount(1, $this->sightingHandler()->sightings());
    }

    /**
     * dispatch_after_current_bus releases the message after TenantStampMiddleware cleared the handler's organization,
     * so without a stamp added before it the message would run with none.
     */
    #[Test]
    public function aMessageAHandlerHoldsBackUntilItEndsRunsUnderTheHandlersOrganization(): void
    {
        $this->commandBus->dispatch(new DispatchSightingAfterCurrentMessage(), [new TenantStamp(new OrganizationId(self::ORG_A))]);

        $sightings = $this->sightingHandler()->sightings();
        self::assertCount(1, $sightings);
        self::assertSame(self::ORG_A, $sightings[0]['organizationId']);
        self::assertSame(['a-first', 'a-second'], $sightings[0]['labels']);
        self::assertNull($this->tenantContext->get());
    }

    #[Test]
    public function anEventDispatchedWhileAnOrganizationIsSetReachesItsAsyncTransportWithItThroughTheRelay(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));
        self::getContainer()->get('event.bus')->dispatch(new OutboxProbeRecorded(self::PROBE_ID));
        $this->tenantContext->clear();

        self::runTheWorkerUntilIdle('outbox');

        $asyncNotify = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $asyncNotify);
        $received = iterator_to_array($asyncNotify->get(), false);
        self::assertCount(1, $received);
        self::assertEquals(new OutboxProbeRecorded(self::PROBE_ID), $received[0]->getMessage());
        self::assertSame([self::ORG_A], self::stampedOrganizationIds($received[0]));
    }

    /**
     * @return list<string>
     */
    private static function stampedOrganizationIds(Envelope $envelope): array
    {
        return array_map(
            static fn (TenantStamp $stamp): string => $stamp->organizationId()->toString(),
            $envelope->all(TenantStamp::class),
        );
    }

    private function testQueue(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.test_queue');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function sightingHandler(): RecordHandlerSightingHandler
    {
        $handler = self::getContainer()->get(RecordHandlerSightingHandler::class);
        self::assertInstanceOf(RecordHandlerSightingHandler::class, $handler);

        return $handler;
    }
}
