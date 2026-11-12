<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\Organization\Api\Event\MemberJoined;
use App\Organization\Api\Event\OrgRolesChanged;
use App\Organization\Api\Event\TeamCreated;
use App\Organization\Api\Event\TeamRoleChanged;
use App\Organization\Infrastructure\Cache\TeamPeopleCache;
use App\Organization\Infrastructure\Messenger\MessengerOrganizationEvents;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use App\Tests\Integration\Organization\OrganizationCommandsOnTheEventBus;
use App\Tests\Integration\Organization\OrganizationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

/**
 * The Organization events on the real event.bus, outbox and relay (messenger.yaml): a command's event is written to
 * the outbox in the command's transaction, and the relay moves every Organization event to async_notify.
 */
final class OrganizationEventRoutingTest extends KernelTestCase
{
    use RunsTheWorkerUntilIdle;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000007a1';
    private const string SLUG = 'acme-foods-routing';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS);
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
        $this->connection()->executeStatement('DELETE FROM messenger_failed');
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->rows()->removeAllOf(self::ACME_FOODS);
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
        parent::tearDown();
    }

    #[Test]
    public function aRolledBackCommandLeavesNoEventInTheOutbox(): void
    {
        $outboxRowsInsideTheTransaction = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): int {
            $this->commands()->setUpShop(self::ACME_FOODS, self::SLUG, self::now());

            return $this->outboxRowCount();
        });

        self::assertSame(6, $outboxRowsInsideTheTransaction);
        self::assertSame(0, $this->outboxRowCount());
        self::assertSame(0, $this->organizationRowCount());
    }

    #[Test]
    public function theRelaySendsEveryOrganizationEventToAsyncNotifyWithItsOrganization(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->commands()->setUpShop(self::ACME_FOODS, self::SLUG, self::now()));
        self::assertSame(6, $this->outboxRowCount());

        self::runTheWorkerUntilIdle('outbox');

        $relayed = $this->asyncNotify()->getSent();
        // The relay sorts by available_at alone, stored to the second. On a small table PostgreSQL scans the heap and a
        // row may sit in a slot an earlier, vacuumed row freed, so events of one second come in no fixed order.
        self::assertEqualsCanonicalizing(
            [MemberJoined::class, MemberJoined::class, MemberJoined::class, TeamCreated::class, TeamRoleChanged::class, OrgRolesChanged::class],
            array_map(static fn (Envelope $envelope): string => $envelope->getMessage()::class, $relayed),
        );
        foreach ($relayed as $envelope) {
            self::assertSame(self::ACME_FOODS, $envelope->last(TenantStamp::class)?->organizationId()->toString());
        }
        self::assertSame(0, $this->outboxRowCount());
        $failed = self::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(ListableReceiverInterface::class, $failed);
        self::assertSame([], iterator_to_array($failed->all(), false));
    }

    /**
     * Outside a transaction doctrine_transaction would commit the outbox row on its own, before the change it describes.
     */
    #[Test]
    public function publishingOutsideATransactionIsRefusedAndWritesNoOutboxRow(): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $events = new MessengerOrganizationEvents(self::getContainer()->get('event.bus'), $this->connection(), self::getContainer()->get(TeamPeopleCache::class));

        try {
            $events->publish(new MemberJoined(self::ACME_FOODS, OrganizationCommandsOnTheEventBus::OMAR, [], null, self::now()));
            self::fail('An event was published with no transaction open.');
        } catch (\LogicException $refused) {
            self::assertSame('An organization event is published in the transaction of its change; none is open.', $refused->getMessage());
        } finally {
            $tenantContext->clear();
        }

        self::assertSame(0, $this->outboxRowCount());
    }

    private function outboxRowCount(): int
    {
        $count = $this->connection()->fetchOne("SELECT count(*) FROM messenger_outbox WHERE queue_name = 'outbox'");
        self::assertIsInt($count);

        return $count;
    }

    private function organizationRowCount(): int
    {
        $count = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM org_organization'));
        self::assertIsInt($count);

        return $count;
    }

    private function asyncNotify(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
    }

    private function commands(): OrganizationCommandsOnTheEventBus
    {
        return new OrganizationCommandsOnTheEventBus($this->entityManager(), self::getContainer()->get('event.bus'));
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

    private function rows(): OrganizationRows
    {
        return new OrganizationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
