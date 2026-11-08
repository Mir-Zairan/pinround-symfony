<?php

declare(strict_types=1);

namespace App\Tests\Integration\Audit;

use App\Audit\Api\AuditEntry;
use App\Audit\Api\AuditEntryForAnotherTenantRefused;
use App\Audit\Api\AuditLog;
use App\Audit\Infrastructure\DbalAuditLog;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Fixtures\Audit\AuditLogConsumer;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Audit\Api\AuditLog on the real aud_event table as pinround_app. Rows are committed so another organization can
 * look for them; the migrator, which owns the table, truncates it around each test.
 */
final class DbalAuditLogTest extends KernelTestCase
{
    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';
    private const string ORG_B = '0197a3c0-0000-7000-8000-00000000000b';
    private const string USER = '0197a3c0-0000-7000-8000-0000000000c1';
    private const string TOKEN = '0197a3c0-0000-7000-8000-0000000000d1';
    private const string TARGET = '0197a3c0-0000-7000-8000-0000000000e1';
    // Inside the months the migrations created partitions for (2026-11 to 2027-12).
    private const string NOW = '2027-03-15 10:00:00 UTC';

    protected function setUp(): void
    {
        self::truncateAuditEvents();
    }

    public static function tearDownAfterClass(): void
    {
        self::truncateAuditEvents();
        self::ensureKernelShutdown();
        parent::tearDownAfterClass();
    }

    #[Test]
    public function aModuleAskingForAuditLogGetsTheDbalOne(): void
    {
        $consumer = self::getContainer()->get(AuditLogConsumer::class);

        self::assertInstanceOf(AuditLogConsumer::class, $consumer);
        self::assertInstanceOf(DbalAuditLog::class, $consumer->auditLog);
    }

    #[Test]
    public function aRecordedEntryIsVisibleToItsOrganizationOnlyAndLandsInTheClocksMonthPartition(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ORG_A));
        $this->connection()->transactional(fn () => $this->auditLog()->record(AuditEntry::byUser(
            new OrganizationId(self::ORG_A),
            self::USER,
            'role.changed',
            'membership',
            self::TARGET,
            ['from' => 'member', 'to' => 'admin', 'team_id' => 7],
            "\x00\xff-ip",
            "\x01ua",
        )));

        $rows = $this->connection()->transactional(static fn (Connection $connection) => $connection->fetchAllAssociative(
            "SELECT id, organization_id, at, actor_kind, actor_user_id, actor_token_id, action, target_type, target_id, data::text AS data,
                    encode(ip_hash, 'hex') AS ip_hash, encode(user_agent_hash, 'hex') AS user_agent_hash, tableoid::regclass::text AS partition
             FROM aud_event",
        ));

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame(self::ORG_A, $row['organization_id']);
        self::assertSame('user', $row['actor_kind']);
        self::assertSame(self::USER, $row['actor_user_id']);
        self::assertNull($row['actor_token_id']);
        self::assertSame('role.changed', $row['action']);
        self::assertSame('membership', $row['target_type']);
        self::assertSame(self::TARGET, $row['target_id']);
        self::assertIsString($row['data']);
        self::assertIsString($row['id']);
        self::assertIsString($row['at']);
        $data = json_decode($row['data'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        ksort($data);
        self::assertSame(['from' => 'member', 'team_id' => 7, 'to' => 'admin'], $data);
        self::assertSame('00ff2d6970', $row['ip_hash']);
        self::assertSame('017561', $row['user_agent_hash']);
        self::assertSame('aud_event_202703', $row['partition']);
        $id = Uuid::fromString($row['id']);
        self::assertInstanceOf(UuidV7::class, $id);
        $at = new \DateTimeImmutable($row['at'])->setTimezone(new \DateTimeZone('UTC'));
        self::assertSame('2027-03-15 10:00:00.000', $at->format('Y-m-d H:i:s.v'));
        self::assertSame('2027-03-15 10:00:00.000', $id->getDateTime()->format('Y-m-d H:i:s.v'));

        $this->tenantContext()->clear();
        $this->tenantContext()->set(new OrganizationId(self::ORG_B));
        self::assertSame(0, $this->countVisibleEvents());
    }

    /**
     * An entry recorded from an event keeps the event's time, also across a month boundary from the clock's now: the
     * row goes to the month of the action.
     */
    #[Test]
    public function anEntryWithItsOwnTimeIsRecordedAtThatTimeNotTheClocks(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ORG_A));
        $this->connection()->transactional(fn () => $this->auditLog()->record(AuditEntry::bySystem(
            new OrganizationId(self::ORG_A),
            'member.joined',
            'user',
            self::USER,
            occurredAt: new \DateTimeImmutable('2027-02-28 23:59:58.250 UTC'),
        )));

        $row = $this->connection()->transactional(static fn (Connection $connection): array|false => $connection->fetchAssociative(
            "SELECT id, to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.MS') AS at, tableoid::regclass::text AS partition FROM aud_event",
        ));

        self::assertIsArray($row);
        self::assertSame('2027-02-28 23:59:58.250', $row['at']);
        self::assertSame('aud_event_202702', $row['partition']);
        self::assertIsString($row['id']);
        $id = Uuid::fromString($row['id']);
        self::assertInstanceOf(UuidV7::class, $id);
        self::assertSame('2027-02-28 23:59:58.250', $id->getDateTime()->format('Y-m-d H:i:s.v'));
    }

    #[Test]
    public function aTokenEntryKeepsTheTokenAndItsUserAndASystemEntryNeither(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ORG_A));
        $this->connection()->transactional(function (): void {
            $this->auditLog()->record(AuditEntry::byToken(new OrganizationId(self::ORG_A), self::USER, self::TOKEN, 'mcp.tool_called', 'ticket', self::TARGET, ['tool' => 'get_ticket']));
            $this->auditLog()->record(AuditEntry::bySystem(new OrganizationId(self::ORG_A), 'retention.changed', 'organization', null));
        });

        self::assertSame(
            [
                ['actor_kind' => 'system', 'actor_user_id' => null, 'actor_token_id' => null, 'target_id' => null, 'data' => '{}', 'ip_hash' => null],
                ['actor_kind' => 'token', 'actor_user_id' => self::USER, 'actor_token_id' => self::TOKEN, 'target_id' => self::TARGET, 'data' => '{"tool": "get_ticket"}', 'ip_hash' => null],
            ],
            $this->connection()->transactional(static fn (Connection $connection) => $connection->fetchAllAssociative(
                'SELECT actor_kind, actor_user_id, actor_token_id, target_id, data::text AS data, ip_hash FROM aud_event ORDER BY actor_kind',
            )),
        );
    }

    #[Test]
    public function anEntryForAnotherOrganizationIsRefusedBeforeTheDatabaseIsAsked(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ORG_A));

        try {
            $this->connection()->transactional(fn () => $this->auditLog()->record($this->systemEntryFor(self::ORG_B)));
            self::fail('An entry for organization B was recorded while A was set.');
        } catch (AuditEntryForAnotherTenantRefused) {
        }

        self::assertSame(0, $this->countVisibleEvents());
        $this->tenantContext()->clear();
        $this->tenantContext()->set(new OrganizationId(self::ORG_B));
        self::assertSame(0, $this->countVisibleEvents());
    }

    #[Test]
    public function withoutATenantRecordingFails(): void
    {
        $this->expectException(TenantNotSet::class);

        $this->connection()->transactional(fn () => $this->auditLog()->record($this->systemEntryFor(self::ORG_A)));
    }

    /** Outside a transaction the row would have no tenant set and could not share the fate of the change it describes. */
    #[Test]
    public function outsideATransactionRecordingIsRefusedAndNothingIsWritten(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ORG_A));

        try {
            $this->auditLog()->record($this->systemEntryFor(self::ORG_A));
            self::fail('An entry was recorded outside a transaction.');
        } catch (\LogicException $exception) {
            self::assertNotInstanceOf(AuditEntryForAnotherTenantRefused::class, $exception);
        }

        self::assertSame(0, $this->countVisibleEvents());
    }

    #[Test]
    public function aRolledBackChangeTakesItsAuditEventWithIt(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ORG_A));
        $this->connection()->beginTransaction();
        $this->auditLog()->record($this->systemEntryFor(self::ORG_A));
        $this->connection()->rollBack();

        self::assertSame(0, $this->countVisibleEvents());
    }

    /** A list-shaped array must not turn the column into a JSON array. */
    #[Test]
    public function dataShapedLikeAListIsStoredAsAnObject(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ORG_A));
        $listShapedData = [0 => 'x'];
        $this->connection()->transactional(fn () => $this->auditLog()->record(
            // @phpstan-ignore argument.type (a caller can still pass a list at run time, which is what this proves)
            AuditEntry::bySystem(new OrganizationId(self::ORG_A), 'retention.changed', 'organization', null, $listShapedData),
        ));

        self::assertSame(
            ['{"0": "x"}', 'object'],
            $this->connection()->transactional(static fn (Connection $connection) => $connection->fetchNumeric(
                'SELECT data::text, jsonb_typeof(data) FROM aud_event',
            )),
        );
    }

    private function systemEntryFor(string $organizationId): AuditEntry
    {
        return AuditEntry::bySystem(new OrganizationId($organizationId), 'retention.changed', 'organization', null);
    }

    private function countVisibleEvents(): int
    {
        $count = $this->connection()->transactional(static fn (Connection $connection) => $connection->fetchOne('SELECT count(*) FROM aud_event'));
        self::assertIsInt($count);

        return $count;
    }

    private static function truncateAuditEvents(): void
    {
        self::getContainer()->get('doctrine.dbal.migrator_connection')->executeStatement('TRUNCATE aud_event');
    }

    private function auditLog(): AuditLog
    {
        return new DbalAuditLog($this->connection(), $this->tenantContext(), new MockClock(self::NOW));
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }
}
