<?php

declare(strict_types=1);

namespace App\Tests\Integration\Audit;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The append-only, monthly-partitioned aud_event table of ADR-0026, as the roles see it. Every write here runs in a
 * transaction that is rolled back, so the table stays empty.
 */
final class AuditEventTableTest extends KernelTestCase
{
    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';
    private const string ORG_B = '0197a3c0-0000-7000-8000-00000000000b';
    private const string USER = '0197a3c0-0000-7000-8000-0000000000c1';
    private const string TOKEN = '0197a3c0-0000-7000-8000-0000000000d1';

    private const array PARTITIONS = [
        'aud_event_202611', 'aud_event_202612', 'aud_event_202701', 'aud_event_202702', 'aud_event_202703',
        'aud_event_202704', 'aud_event_202705', 'aud_event_202706', 'aud_event_202707', 'aud_event_202708',
        'aud_event_202709', 'aud_event_202710', 'aud_event_202711', 'aud_event_202712',
    ];

    private const string INSERT = 'INSERT INTO aud_event (id, organization_id, at, actor_kind, actor_user_id, actor_token_id, action, target_type, data)'
        ." VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, 'role.changed', 'membership', ?::jsonb)";

    #[Test]
    public function updateAndDeleteOnAudEventFailForTheAppRole(): void
    {
        foreach (['UPDATE aud_event SET action = action', 'DELETE FROM aud_event', 'TRUNCATE aud_event'] as $statement) {
            $this->assertRefusedForLackOfPrivilege(function (Connection $connection) use ($statement): void {
                $this->insertAs($connection, self::ORG_A, '2026-11-15 12:00:00+00');
                $connection->executeStatement($statement);
            }, $statement);
        }
    }

    #[Test]
    public function aDirectSelectOnAPartitionFailsForTheAppAndReadonlyRoles(): void
    {
        $readonly = self::getContainer()->get('doctrine.dbal.readonly_connection');
        self::assertInstanceOf(Connection::class, $readonly);
        $connections = ['pinround_app' => $this->connection(), 'pinround_readonly' => $readonly];

        foreach ($connections as $role => $connection) {
            self::assertSame($role, $connection->fetchOne('SELECT current_user'));
            foreach ($this->partitions() as $partition) {
                try {
                    $connection->fetchOne('SELECT count(*) FROM '.$partition);
                    self::fail(\sprintf('%s could read the partition %s directly.', $role, $partition));
                } catch (DriverException $exception) {
                    self::assertSame('42501', $exception->getSQLState(), $role.' on '.$partition);
                }
            }
        }
    }

    #[Test]
    public function thePartitionsAreTheFourteenMonthsFromNovember2026AndNoDefault(): void
    {
        self::assertSame(self::PARTITIONS, $this->partitions());
        self::assertSame([], $this->migrator()->fetchFirstColumn(
            "SELECT c.relname FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid
             WHERE i.inhparent = 'aud_event'::regclass AND pg_get_expr(c.relpartbound, c.oid) = 'DEFAULT'",
        ));
    }

    /** UTC month bounds, whatever the session's time zone. */
    #[Test]
    #[DataProvider('instantsAndTheirPartition')]
    public function aRowLandsInThePartitionOfItsUtcMonth(string $at, string $partition): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ORG_A));
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement("SET LOCAL TIME ZONE 'Pacific/Kiritimati'");
            $this->insertAs($connection, self::ORG_A, $at);

            self::assertSame([$partition], $connection->fetchFirstColumn('SELECT tableoid::regclass::text FROM aud_event'));
        } finally {
            $connection->rollBack();
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function instantsAndTheirPartition(): iterable
    {
        yield 'first instant' => ['2026-11-01 00:00:00+00', 'aud_event_202611'];
        yield 'last instant of a month' => ['2026-11-30 23:59:59.999999+00', 'aud_event_202611'];
        yield 'first instant of the next month' => ['2026-12-01 00:00:00+00', 'aud_event_202612'];
        yield 'last month' => ['2027-12-31 23:59:59.999999+00', 'aud_event_202712'];
    }

    /** No DEFAULT partition: a row past the last month is refused, so the monthly job must run before then. */
    #[Test]
    public function aRowOutsideEveryPartitionIsRefused(): void
    {
        foreach (['2028-01-01 00:00:00+00', '2026-10-31 23:59:59+00'] as $at) {
            $this->assertRefusedWith('23514', fn (Connection $connection) => $this->insertAs($connection, self::ORG_A, $at), $at);
        }
    }

    #[Test]
    public function rowLevelSecurityRefusesARowOfAnotherOrganization(): void
    {
        $this->assertRefusedForLackOfPrivilege(fn (Connection $connection) => $this->insertAs($connection, self::ORG_B, '2026-11-15 12:00:00+00'), 'org B as A');
    }

    #[Test]
    #[DataProvider('actorsThatDoNotMatchTheirKind')]
    public function anActorThatDoesNotMatchItsKindOrDataThatIsNotAnObjectIsRefused(string $actorKind, ?string $userId, ?string $tokenId, string $data): void
    {
        $this->assertRefusedWith(
            '23514',
            fn (Connection $connection) => $this->insertAs($connection, self::ORG_A, '2026-11-15 12:00:00+00', $actorKind, $userId, $tokenId, $data),
            $actorKind,
        );
    }

    /** @return iterable<string, array{string, ?string, ?string, string}> */
    public static function actorsThatDoNotMatchTheirKind(): iterable
    {
        yield 'user without a user' => ['user', null, null, '{}'];
        yield 'user with a token' => ['user', self::USER, self::TOKEN, '{}'];
        yield 'token without a token' => ['token', self::USER, null, '{}'];
        yield 'token without its user' => ['token', null, self::TOKEN, '{}'];
        yield 'system with a user' => ['system', self::USER, null, '{}'];
        yield 'system with a token' => ['system', null, self::TOKEN, '{}'];
        yield 'unknown kind' => ['robot', null, null, '{}'];
        yield 'data as a list' => ['system', null, null, '[]'];
        yield 'data as a string' => ['system', null, null, '"text"'];
    }

    #[Test]
    public function eachActorKindIsAcceptedWithItsIds(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ORG_A));
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $this->insertAs($connection, self::ORG_A, '2026-11-15 12:00:00+00', 'user', self::USER, null);
            $this->insertAs($connection, self::ORG_A, '2026-11-15 12:00:00+00', 'token', self::USER, self::TOKEN);
            $this->insertAs($connection, self::ORG_A, '2026-11-15 12:00:00+00', 'system', null, null);

            self::assertSame(['system', 'token', 'user'], $connection->fetchFirstColumn('SELECT actor_kind FROM aud_event ORDER BY actor_kind'));
        } finally {
            $connection->rollBack();
        }
    }

    /** Every index starts with organization_id, except the key, which must hold the partition column (ADR-0005, ADR-0026). */
    #[Test]
    public function theParentIndexesStartWithTheOrganization(): void
    {
        self::assertEqualsCanonicalizing(
            [
                'CREATE UNIQUE INDEX aud_event_pkey ON ONLY public.aud_event USING btree (id, at)',
                'CREATE INDEX aud_event_organization_id_at_idx ON ONLY public.aud_event USING btree (organization_id, at DESC)',
                'CREATE INDEX aud_event_organization_id_target_idx ON ONLY public.aud_event USING btree (organization_id, target_type, target_id, at DESC)',
            ],
            $this->connection()->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'aud_event'"),
        );
    }

    /**
     * @param \Closure(Connection): mixed $write
     */
    private function assertRefusedForLackOfPrivilege(\Closure $write, string $case): void
    {
        $this->assertRefusedWith('42501', $write, $case);
    }

    /**
     * @param \Closure(Connection): mixed $write
     */
    private function assertRefusedWith(string $sqlState, \Closure $write, string $case): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ORG_A));
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $write($connection);
            self::fail($case.' was not refused.');
        } catch (DriverException $exception) {
            self::assertSame($sqlState, $exception->getSQLState(), $case);
        } finally {
            $connection->rollBack();
        }
    }

    private function insertAs(
        Connection $connection,
        string $organizationId,
        string $at,
        string $actorKind = 'system',
        ?string $userId = null,
        ?string $tokenId = null,
        string $data = '{}',
    ): void {
        $connection->executeStatement(self::INSERT, [$organizationId, $at, $actorKind, $userId, $tokenId, $data]);
    }

    /** @return list<string> */
    private function partitions(): array
    {
        /** @var list<string> $partitions */
        $partitions = $this->migrator()->fetchFirstColumn(
            "SELECT c.relname FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid WHERE i.inhparent = 'aud_event'::regclass ORDER BY c.relname",
        );

        return $partitions;
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }
}
