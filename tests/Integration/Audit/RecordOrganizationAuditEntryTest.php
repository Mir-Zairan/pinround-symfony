<?php

declare(strict_types=1);

namespace App\Tests\Integration\Audit;

use App\Audit\Application\RecordOrganizationAuditEntry;
use App\SharedKernel\Application\TenantContext;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use App\Tests\Integration\Organization\OrganizationCommandsOnTheEventBus;
use App\Tests\Integration\Organization\OrganizationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The audit consumer of the Organization events on the real outbox, relay, worker and aud_event table: each event
 * writes exactly one row under its organization, with only the allow-listed data keys, and a redelivered event writes
 * none.
 */
final class RecordOrganizationAuditEntryTest extends KernelTestCase
{
    use RunsTheWorkerUntilIdle;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000007b1';
    private const string SLUG = 'acme-foods-audit';
    // Inside the months the migrations created aud_event partitions for (2026-11 to 2027-12).
    private const string NOW = '2027-03-15 10:00:00 UTC';
    // Earlier than the worker's clock, as when the relay or a retry from failed delivers the event late.
    private const string OCCURRED_AT = '2027-03-14 08:30:00 UTC';

    protected function setUp(): void
    {
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $this->rows()->removeAllOf(self::ACME_FOODS);
        $this->migrator()->executeStatement('TRUNCATE aud_event');
        $this->migrator()->executeStatement('DELETE FROM processed_message WHERE handler = ?', [RecordOrganizationAuditEntry::HANDLER_NAME]);
        $this->migrator()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->rows()->removeAllOf(self::ACME_FOODS);
        $this->migrator()->executeStatement('TRUNCATE aud_event');
        $this->migrator()->executeStatement('DELETE FROM processed_message WHERE handler = ?', [RecordOrganizationAuditEntry::HANDLER_NAME]);
        parent::tearDown();
    }

    #[Test]
    public function eachOrganizationEventWritesOneAuditRowWithOnlyItsAllowListedData(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->commands()->setUpShop(self::ACME_FOODS, self::SLUG, new \DateTimeImmutable(self::OCCURRED_AT)));

        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');

        $rows = $this->auditRows();
        self::assertSame(
            [
                ['system', null, 'member.joined', 'user', OrganizationCommandsOnTheEventBus::SARA],
                ['user', OrganizationCommandsOnTheEventBus::SARA, 'member.joined', 'user', OrganizationCommandsOnTheEventBus::LENA],
                ['user', OrganizationCommandsOnTheEventBus::SARA, 'member.joined', 'user', OrganizationCommandsOnTheEventBus::OMAR],
                ['user', OrganizationCommandsOnTheEventBus::SARA, 'team.created', 'team', $rows[3]['target_id']],
                ['user', OrganizationCommandsOnTheEventBus::SARA, 'team_role.changed', 'user', OrganizationCommandsOnTheEventBus::OMAR],
                ['user', OrganizationCommandsOnTheEventBus::SARA, 'org_roles.changed', 'user', OrganizationCommandsOnTheEventBus::LENA],
            ],
            array_map(static fn (array $row): array => [$row['actor_kind'], $row['actor_user_id'], $row['action'], $row['target_type'], $row['target_id']], $rows),
        );
        // jsonb keeps its own key order, so the data is compared by key.
        self::assertEquals(
            [
                ['org_roles' => ['admin']],
                ['org_roles' => ['admin']],
                ['org_roles' => []],
                ['lead_user_id' => OrganizationCommandsOnTheEventBus::LENA],
                ['team_id' => $rows[3]['target_id'], 'from' => null, 'to' => 'member'],
                ['from' => ['admin'], 'to' => ['admin', 'manager']],
            ],
            array_map(static fn (array $row): array => $row['data'], $rows),
        );
        self::assertSame(array_fill(0, 6, '2027-03-14 08:30:00'), array_column($rows, 'at'), 'Each row keeps the time of its event, not of its handling.');
        foreach ($rows as $row) {
            self::assertEqualsCanonicalizing(RecordOrganizationAuditEntry::DATA_KEYS[$row['action']] ?? [], array_keys($row['data']));
        }
    }

    #[Test]
    public function anInvitationWritesOneMemberInvitedRowAboutTheInvitationWithNoData(): void
    {
        $occurredAt = new \DateTimeImmutable(self::OCCURRED_AT);
        $invitationId = $this->transactions()->committedAs(self::ACME_FOODS, function () use ($occurredAt): string {
            $this->commands()->setUpShop(self::ACME_FOODS, self::SLUG, $occurredAt);

            return $this->commands()->invite(self::ACME_FOODS, 'mia@acme-foods.example', $occurredAt);
        });

        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');

        $invited = array_values(array_filter($this->auditRows(), static fn (array $row): bool => 'member.invited' === $row['action']));
        self::assertSame(
            [['user', OrganizationCommandsOnTheEventBus::SARA, 'member.invited', 'invitation', $invitationId, '2027-03-14 08:30:00', []]],
            array_map(static fn (array $row): array => [$row['actor_kind'], $row['actor_user_id'], $row['action'], $row['target_type'], $row['target_id'], $row['at'], $row['data']], $invited),
        );
        self::assertCount(7, $this->auditRows());
        self::assertSame('{}', $this->rawDataOf($invitationId));
    }

    #[Test]
    public function aRedeliveredEventWritesNoSecondRow(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->commands()->setUpShop(self::ACME_FOODS, self::SLUG, new \DateTimeImmutable(self::OCCURRED_AT)));
        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');
        $asyncNotify = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $asyncNotify);

        $asyncNotify->send($asyncNotify->getSent()[0]);
        self::runTheWorkerUntilIdle('async_notify');

        self::assertCount(7, $asyncNotify->getSent());
        self::assertCount(6, $this->auditRows());
        self::assertCount(1, array_filter($this->auditRows(), static fn (array $row): bool => OrganizationCommandsOnTheEventBus::SARA === $row['target_id']));
    }

    /**
     * @return list<array{actor_kind: mixed, actor_user_id: mixed, action: string, target_type: mixed, target_id: mixed, at: mixed, data: array<mixed>}>
     */
    private function auditRows(): array
    {
        $rows = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->entityManager()->getConnection()->fetchAllAssociative(
            "SELECT actor_kind, actor_user_id, action, target_type, target_id, to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS at,
                    data::text AS data
             FROM aud_event WHERE organization_id = ? ORDER BY id",
            [self::ACME_FOODS],
        ));

        return array_map(static function (array $row): array {
            self::assertIsString($row['action']);
            self::assertIsString($row['data']);
            $data = json_decode($row['data'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);

            return [
                'actor_kind' => $row['actor_kind'],
                'actor_user_id' => $row['actor_user_id'],
                'action' => $row['action'],
                'target_type' => $row['target_type'],
                'target_id' => $row['target_id'],
                'at' => $row['at'],
                'data' => $data,
            ];
        }, $rows);
    }

    private function rawDataOf(string $targetId): mixed
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->entityManager()->getConnection()->fetchOne(
            'SELECT data::text FROM aud_event WHERE organization_id = ? AND target_id = ?',
            [self::ACME_FOODS, $targetId],
        ));
    }

    private function commands(): OrganizationCommandsOnTheEventBus
    {
        return new OrganizationCommandsOnTheEventBus($this->entityManager(), self::getContainer()->get('event.bus'));
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
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
        return new OrganizationRows($this->migrator(), self::getContainer()->get(TenantContext::class));
    }
}
