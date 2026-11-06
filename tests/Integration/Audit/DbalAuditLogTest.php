<?php

declare(strict_types=1);

namespace App\Tests\Integration\Audit;

use App\Audit\Api\ActorKind;
use App\Audit\Api\AuditEntry;
use App\Audit\Api\AuditEntryForAnotherTenantRefused;
use App\Audit\Api\AuditLog;
use App\Audit\Infrastructure\DbalAuditLog;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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
    private const string MARKER = 'Customer wrote: AUDIT-LEAK-MARKER';

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
    public function theAuditLogServiceIsTheDbalOne(): void
    {
        self::assertInstanceOf(DbalAuditLog::class, $this->auditLog());
    }

    #[Test]
    public function aRecordedEntryIsVisibleToItsOrganizationOnlyAndLandsInThisMonthsPartition(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ORG_A));
        $before = new \DateTimeImmutable('-1 second');
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
        self::assertSame('aud_event_'.gmdate('Ym'), $row['partition']);
        $id = Uuid::fromString($row['id']);
        self::assertInstanceOf(UuidV7::class, $id);
        $at = new \DateTimeImmutable($row['at']);
        self::assertGreaterThanOrEqual($before, $at);
        self::assertLessThanOrEqual(new \DateTimeImmutable('+1 second'), $at);
        self::assertSame($id->getDateTime()->format('Y-m-d H:i:s.v'), $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'));

        $this->tenantContext()->clear();
        $this->tenantContext()->set(new OrganizationId(self::ORG_B));
        self::assertSame(0, $this->countVisibleEvents());
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

    /** Text goes nowhere but data: action and target type are codes, the ids are UUIDs. */
    #[Test]
    #[DataProvider('entriesCarryingTextOrBadIds')]
    public function anEntryCarryingTextOutsideDataOrABadIdCannotBeBuilt(\Closure $buildEntry): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $buildEntry();
    }

    /** @return iterable<string, array{\Closure(): AuditEntry}> */
    public static function entriesCarryingTextOrBadIds(): iterable
    {
        $org = new OrganizationId(self::ORG_A);

        yield 'text as the action' => [static fn () => AuditEntry::bySystem($org, self::MARKER, 'ticket', null)];
        yield 'text as the target type' => [static fn () => AuditEntry::bySystem($org, 'ticket.visibility_changed', self::MARKER, null)];
        yield 'an uppercase action' => [static fn () => AuditEntry::bySystem($org, 'Role.changed', 'membership', null)];
        yield 'an empty action' => [static fn () => AuditEntry::bySystem($org, '', 'membership', null)];
        yield 'an action with a trailing newline' => [static fn () => AuditEntry::bySystem($org, "role.changed\n", 'membership', null)];
        yield 'an action longer than 63 characters' => [static fn () => AuditEntry::bySystem($org, str_repeat('a', 64), 'membership', null)];
        yield 'text as the target id' => [static fn () => AuditEntry::bySystem($org, 'ticket.closed', 'ticket', self::MARKER)];
        yield 'text as the user id' => [static fn () => AuditEntry::byUser($org, self::MARKER, 'role.changed', 'membership', null)];
        yield 'text as the token id' => [static fn () => AuditEntry::byToken($org, self::USER, self::MARKER, 'mcp.tool_called', 'ticket', null)];
        yield 'an empty ip hash' => [static fn () => AuditEntry::byUser($org, self::USER, 'login.failed', 'user', self::USER, [], '')];
        yield 'an empty user agent hash' => [static fn () => AuditEntry::byUser($org, self::USER, 'login.failed', 'user', self::USER, [], null, '')];
    }

    #[Test]
    public function eachFactorySetsItsActorKind(): void
    {
        $org = new OrganizationId(self::ORG_A);

        self::assertSame(ActorKind::User, AuditEntry::byUser($org, self::USER, 'role.changed', 'membership', null)->actorKind);
        self::assertSame(ActorKind::Token, AuditEntry::byToken($org, self::USER, self::TOKEN, 'mcp.tool_called', 'ticket', null)->actorKind);
        self::assertSame(ActorKind::System, AuditEntry::bySystem($org, 'retention.changed', 'organization', null)->actorKind);
        self::assertSame(self::TARGET, AuditEntry::bySystem($org, 'ticket.closed', 'ticket', strtoupper(self::TARGET))->targetId);
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
        return self::getContainer()->get(AuditLog::class);
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
