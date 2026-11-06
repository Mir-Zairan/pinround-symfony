<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Doctrine\CloseOnFailedBeginConnection;
use App\SharedKernel\Infrastructure\Doctrine\TenantMiddleware;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the row-level security policy from TenantTable::enableRls and the tenant the TenantMiddleware
 * sets per transaction (ADR-0005), on the normal kernel connection (pinround_app).
 */
final class TenantIsolationTest extends KernelTestCase
{
    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';
    private const string ORG_B = '0197a3c0-0000-7000-8000-00000000000b';

    private Connection $connection;
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
        $this->connection = $container->get('doctrine.dbal.default_connection');
        $this->tenantContext = $container->get(TenantContext::class);

        $this->insertLabelsAs(self::ORG_A, 'a-first', 'a-second');
        $this->insertLabelsAs(self::ORG_B, 'b-only');
    }

    #[Test]
    public function withOrgASetAQueryReturnsOnlyOrgARows(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));

        self::assertSame(['a-first', 'a-second'], $this->connection->transactional($this->visibleLabels(...)));
    }

    #[Test]
    public function withNoOrgSetAQueryReturnsNoRowsInATransactionAndInAutocommit(): void
    {
        // A new backend, so current_setting() is NULL here (the '' case is covered on a reused backend below).
        $this->connection->close();

        self::assertSame([], $this->visibleLabels($this->connection));
        self::assertSame([], $this->connection->transactional($this->visibleLabels(...)));
    }

    #[Test]
    public function aConnectionThatServedOrgAReturnsNoRowsWithoutAnOrgInItsNextTransaction(): void
    {
        $backendPid = $this->connection->fetchOne('SELECT pg_backend_pid()');
        $this->tenantContext->set(new OrganizationId(self::ORG_A));
        self::assertSame(['a-first', 'a-second'], $this->connection->transactional($this->visibleLabels(...)));

        $this->tenantContext->clear();

        self::assertSame([], $this->connection->transactional($this->visibleLabels(...)));
        self::assertSame($backendPid, $this->connection->fetchOne('SELECT pg_backend_pid()'));
    }

    #[Test]
    public function writingARowForAnotherOrgIsRejectedByThePolicy(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));

        $this->assertRejectedByRowLevelSecurity(static fn (Connection $connection): int|string => $connection->executeStatement(
            'INSERT INTO zz_tenant_probe (organization_id, label) VALUES (?, ?)',
            [self::ORG_B, 'b-smuggled'],
        ));
        $this->assertRejectedByRowLevelSecurity(static fn (Connection $connection): int|string => $connection->executeStatement(
            'UPDATE zz_tenant_probe SET organization_id = ? WHERE label = ?',
            [self::ORG_B, 'a-first'],
        ));

        $this->insertLabelsAs(self::ORG_A, 'a-third');
        $this->tenantContext->set(new OrganizationId(self::ORG_A));
        self::assertSame(['a-first', 'a-second', 'a-third'], $this->connection->transactional($this->visibleLabels(...)));
        $this->tenantContext->set(new OrganizationId(self::ORG_B));
        self::assertSame(['b-only'], $this->connection->transactional($this->visibleLabels(...)));
    }

    #[Test]
    public function theMiddlewareSetsTheOrgIdForTheTransactionOnly(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));

        self::assertSame(self::ORG_A, $this->connection->transactional($this->currentOrgSetting(...)));
        self::assertSame('', $this->currentOrgSetting($this->connection));
    }

    #[Test]
    public function aNestedTransactionKeepsTheOrgIdOfTheOuterOne(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));

        $settingInSavepoint = $this->connection->transactional(function (Connection $connection): mixed {
            $this->tenantContext->set(new OrganizationId(self::ORG_B));

            return $connection->transactional($this->currentOrgSetting(...));
        });

        self::assertSame(self::ORG_A, $settingInSavepoint);
        self::assertSame(self::ORG_B, $this->connection->transactional($this->currentOrgSetting(...)));
    }

    #[Test]
    public function aFailedTenantSetupLeavesTheConnectionReadyForTheNextTransaction(): void
    {
        $setTenantFailsOnce = new SetTenantFailsOnceMiddleware();
        // The failing middleware sits inside TenantMiddleware, so TenantMiddleware's set_config passes through it.
        $driver = new TenantMiddleware($this->tenantContext)->wrap($setTenantFailsOnce->wrap(new PgSQLDriver()));
        $connection = new CloseOnFailedBeginConnection($this->connection->getParams(), $driver);
        $this->tenantContext->set(new OrganizationId(self::ORG_A));

        $thrown = null;
        try {
            $connection->transactional($this->visibleLabels(...));
        } catch (\RuntimeException $exception) {
            $thrown = $exception;
        }

        self::assertSame($setTenantFailsOnce->failure, $thrown);
        self::assertFalse($connection->isTransactionActive());
        self::assertSame(['a-first', 'a-second'], $connection->transactional($this->visibleLabels(...)));
        $connection->close();
    }

    private static function probeTable(): TenantProbeTable
    {
        return new TenantProbeTable(self::getContainer()->get('doctrine.dbal.migrator_connection'));
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

    /**
     * @return list<mixed>
     */
    private function visibleLabels(Connection $connection): array
    {
        return $connection->fetchFirstColumn('SELECT label FROM zz_tenant_probe ORDER BY label');
    }

    private function currentOrgSetting(Connection $connection): mixed
    {
        return $connection->fetchOne("SELECT current_setting('app.org_id', true)");
    }

    /**
     * @param \Closure(Connection): mixed $write
     */
    private function assertRejectedByRowLevelSecurity(\Closure $write): void
    {
        try {
            $this->connection->transactional($write);
        } catch (DriverException $exception) {
            self::assertSame('42501', $exception->getSQLState());
            self::assertStringContainsString('new row violates row-level security policy for table "zz_tenant_probe"', $exception->getMessage());

            return;
        }

        self::fail('The write for another organization was not rejected.');
    }
}
