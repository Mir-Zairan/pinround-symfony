<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use App\Tests\Fixtures\Messenger\InsertTenantProbeLabelLeavingTransactionOpen;
use App\Tests\Fixtures\Messenger\ListTenantProbeLabels;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

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
    public function withNoOrganizationSetAStampedMessageIsRefusedInsideAnOpenTransaction(): void
    {
        $this->connection->beginTransaction();

        try {
            $this->dispatchListing(self::ORG_A);
            self::fail('The message ran inside a transaction that never got its organization.');
        } catch (TenantSwitchRefused) {
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
