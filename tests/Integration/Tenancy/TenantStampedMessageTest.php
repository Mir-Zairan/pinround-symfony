<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use App\Tests\Fixtures\Messenger\InsertTenantProbeLabel;
use App\Tests\Fixtures\Messenger\InsertTenantProbeLabelLeavingTransactionOpen;
use App\Tests\Fixtures\Messenger\ListTenantProbeLabels;
use App\Tests\Fixtures\Messenger\QueueTenantProbeInsertAfterCurrentMessage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Proves TenantStampMiddleware on the real default bus: the handler's doctrine_transaction runs under the stamp's
 * organization (row-level security and TenantFilter), and nothing of it is left after the message (ADR-0005).
 */
final class TenantStampedMessageTest extends KernelTestCase
{
    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';
    private const string ORG_B = '0197a3c0-0000-7000-8000-00000000000b';

    private MessageBusInterface $bus;
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private TenantContext $tenantContext;

    public static function setUpBeforeClass(): void
    {
        self::probeTable()->recreate();
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::probeTable()->drop();
        self::ensureKernelShutdown();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        self::probeTable()->removeAllRows();

        $container = self::getContainer();
        $this->bus = $container->get(MessageBusInterface::class);
        $this->connection = $container->get('doctrine.dbal.default_connection');
        $this->entityManager = $container->get('doctrine.orm.default_entity_manager');
        $this->tenantContext = $container->get(TenantContext::class);

        $this->insertLabelsAs(self::ORG_A, 'a-first', 'a-second');
        $this->insertLabelsAs(self::ORG_B, 'b-only');
    }

    #[Test]
    public function eachStampedMessageSeesOnlyItsOrganizationAndLeavesNothingBehind(): void
    {
        self::assertSame(
            ['labels' => ['a-first', 'a-second'], 'orgSetting' => self::ORG_A],
            $this->dispatchListing(self::ORG_A),
        );
        $this->assertNothingLeftOfTheMessage();

        self::assertSame(
            ['labels' => ['b-only'], 'orgSetting' => self::ORG_B],
            $this->dispatchListing(self::ORG_B),
        );
        $this->assertNothingLeftOfTheMessage();
    }

    #[Test]
    public function aFailingStampedMessageLeavesNothingBehind(): void
    {
        try {
            $this->bus->dispatch(new ListTenantProbeLabels(isFailingAfterReading: true), [new TenantStamp(new OrganizationId(self::ORG_A))]);
            self::fail('The failing handler did not throw.');
        } catch (HandlerFailedException $failure) {
            self::assertSame('The handler failed after reading.', $failure->getPrevious()?->getMessage());
        }

        $this->assertNothingLeftOfTheMessage();
        self::assertSame(['labels' => ['b-only'], 'orgSetting' => self::ORG_B], $this->dispatchListing(self::ORG_B));
    }

    #[Test]
    public function aTransactionTheHandlerLeftOpenIsRolledBackAndReported(): void
    {
        try {
            $this->bus->dispatch(
                new InsertTenantProbeLabelLeavingTransactionOpen(self::ORG_A, 'a-lost'),
                [new TenantStamp(new OrganizationId(self::ORG_A))],
            );
            self::fail('The transaction left open was not reported.');
        } catch (\LogicException $failure) {
            self::assertStringContainsString('left a database transaction open', $failure->getMessage());
        }

        $this->assertNothingLeftOfTheMessage();
        self::assertSame(['labels' => ['a-first', 'a-second'], 'orgSetting' => self::ORG_A], $this->dispatchListing(self::ORG_A));
    }

    #[Test]
    public function withNoOrganizationSetInsideAnOpenTransactionAStampedMessageRunsWithoutATenantAndSeesNoRows(): void
    {
        $this->connection->beginTransaction();

        $envelope = $this->bus->dispatch(
            new ListTenantProbeLabels(isReadingWithSql: true),
            [new TenantStamp(new OrganizationId(self::ORG_A))],
        );

        self::assertSame(['labels' => [], 'orgSetting' => ''], $envelope->last(HandledStamp::class)?->getResult());
        self::assertNull($this->tenantContext->get());
        self::assertSame(1, $this->connection->getTransactionNestingLevel());
        $this->connection->rollBack();
    }

    #[Test]
    public function withNoOrganizationSetInsideAnOpenTransactionAStampedMessageFailsLoudlyOnAnOrmQuery(): void
    {
        $this->connection->beginTransaction();

        try {
            $this->dispatchListing(self::ORG_A);
            self::fail('The ORM query ran without a tenant.');
        } catch (HandlerFailedException $failure) {
            self::assertInstanceOf(TenantNotSet::class, $failure->getPrevious());
        }

        self::assertNull($this->tenantContext->get());
        self::assertSame(1, $this->connection->getTransactionNestingLevel());
        $this->connection->rollBack();
    }

    #[Test]
    public function withTheSameOrganizationSetAStampedMessageRunsAndTheOrganizationIsKept(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));

        self::assertSame(['labels' => ['a-first', 'a-second'], 'orgSetting' => self::ORG_A], $this->dispatchListing(self::ORG_A));
        self::assertSame(self::ORG_A, $this->tenantContext->get()?->toString());
    }

    #[Test]
    public function withAnotherOrganizationSetAStampedMessageIsRefused(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));

        $this->expectException(MessageForAnotherTenantRefused::class);

        $this->dispatchListing(self::ORG_B);
    }

    #[Test]
    public function aHandlerCannotDispatchAMessageForAnotherOrganizationToRunAfterItself(): void
    {
        try {
            $this->bus->dispatch(
                new QueueTenantProbeInsertAfterCurrentMessage(self::ORG_B, 'b-smuggled'),
                [new TenantStamp(new OrganizationId(self::ORG_A))],
            );
            self::fail('The handler dispatched a message for another organization.');
        } catch (HandlerFailedException $failure) {
            self::assertInstanceOf(MessageForAnotherTenantRefused::class, $failure->getPrevious());
        }

        $this->assertNothingLeftOfTheMessage();
        self::assertSame(['labels' => ['b-only'], 'orgSetting' => self::ORG_B], $this->dispatchListing(self::ORG_B));
    }

    #[Test]
    public function aHandlerCanDispatchAMessageForItsOwnOrganizationToRunAfterItself(): void
    {
        $this->bus->dispatch(
            new QueueTenantProbeInsertAfterCurrentMessage(self::ORG_A, 'a-later'),
            [new TenantStamp(new OrganizationId(self::ORG_A))],
        );

        $this->assertNothingLeftOfTheMessage();
        self::assertSame(['labels' => ['a-first', 'a-later', 'a-second'], 'orgSetting' => self::ORG_A], $this->dispatchListing(self::ORG_A));
    }

    #[Test]
    public function withAnOrganizationSetAMessageForAnotherOneIsNotQueued(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));

        try {
            $this->bus->dispatch(new InsertTenantProbeLabel(self::ORG_B, 'b-queued'), [
                new TenantStamp(new OrganizationId(self::ORG_B)),
                new TransportNamesStamp(['test_queue']),
            ]);
            self::fail('The message for another organization was queued.');
        } catch (MessageForAnotherTenantRefused) {
        }

        self::assertSame([], $this->testQueue()->getSent());
        self::assertSame(self::ORG_A, $this->tenantContext->get()?->toString());
    }

    #[Test]
    public function aQueuedMessageRunsUnderItsOrganizationWhenReceived(): void
    {
        $this->bus->dispatch(new InsertTenantProbeLabel(self::ORG_A, 'a-queued'), [
            new TenantStamp(new OrganizationId(self::ORG_A)),
            new TransportNamesStamp(['test_queue']),
        ]);
        $this->assertNothingLeftOfTheMessage();
        self::assertSame(['labels' => ['a-first', 'a-second'], 'orgSetting' => self::ORG_A], $this->dispatchListing(self::ORG_A));

        $received = iterator_to_array($this->testQueue()->get(), false);
        self::assertCount(1, $received);
        // What the worker does with a received message; the in-memory transport serialized it on the way.
        $this->bus->dispatch($received[0]->with(new ReceivedStamp('test_queue')));

        $this->assertNothingLeftOfTheMessage();
        self::assertSame(['labels' => ['a-first', 'a-queued', 'a-second'], 'orgSetting' => self::ORG_A], $this->dispatchListing(self::ORG_A));
    }

    private static function probeTable(): TenantProbeTable
    {
        return new TenantProbeTable(self::getContainer()->get('doctrine.dbal.migrator_connection'));
    }

    /**
     * @return array<mixed> what ListTenantProbeLabelsHandler saw
     */
    private function dispatchListing(string $organizationId): array
    {
        $envelope = $this->bus->dispatch(new ListTenantProbeLabels(), [new TenantStamp(new OrganizationId($organizationId))]);

        $listing = $envelope->last(HandledStamp::class)?->getResult();
        self::assertIsArray($listing);

        return $listing;
    }

    private function testQueue(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.test_queue');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function assertNothingLeftOfTheMessage(): void
    {
        self::assertNull($this->tenantContext->get());
        self::assertSame(0, $this->entityManager->getUnitOfWork()->size());
        self::assertFalse($this->connection->isTransactionActive());
    }

    private function insertLabelsAs(string $organizationId, string ...$labels): void
    {
        $this->tenantContext->set(new OrganizationId($organizationId));
        $this->connection->transactional(static function (Connection $connection) use ($organizationId, $labels): void {
            foreach ($labels as $label) {
                $connection->executeStatement(
                    'INSERT INTO zz_tenant_probe (organization_id, label) VALUES (?, ?)',
                    [$organizationId, $label],
                );
            }
        });
        $this->tenantContext->clear();
    }
}
