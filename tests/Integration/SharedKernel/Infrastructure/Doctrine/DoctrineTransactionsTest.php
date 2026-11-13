<?php

declare(strict_types=1);

namespace App\Tests\Integration\SharedKernel\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Doctrine\DoctrineTransactions;
use App\SharedKernel\Infrastructure\Doctrine\TenantSetting;
use App\Tests\Fixtures\Entity\TenantProbe;
use App\Tests\Integration\Tenancy\TenantProbeTable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrineTransactions on a real tenant table (wf_step) as pinround_app, under row-level security. The rows are
 * written and counted through plain DBAL, so the test depends on no module. What a run writes and reads through the
 * entity manager uses the test-only TenantProbe on zz_tenant_probe.
 */
final class DoctrineTransactionsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000012a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000012a2';
    private const string DESIGN_CHECK = '0197a3c0-0000-7000-8000-0000000012d1';
    private const string BAKERY_CHECK = '0197a3c0-0000-7000-8000-0000000012d2';
    private const string REFUSED_PROBE = '0197a3c0-0000-7000-8000-0000000012e1';
    private const string KEPT_PROBE = '0197a3c0-0000-7000-8000-0000000012e2';
    private const string PROBE_LABELS_DQL = 'SELECT p.label FROM '.TenantProbe::class.' p ORDER BY p.label';

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
        $this->removeStepsOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->removeStepsOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function theWriteRunsUnderTheRequestsTenantAndIsCommitted(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::BETA_BAKERY));
        $this->transactions()->run(fn () => $this->insertCompanyStep(self::BETA_BAKERY, self::BAKERY_CHECK, 'Taste check'));
        $this->tenantContext()->reset();
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        [$tenantSetting, $visibleNames] = $this->transactions()->run(fn (): array => [
            $this->connection()->fetchOne('SELECT current_setting(?, true)', [TenantSetting::NAME]),
            $this->connection()->fetchFirstColumn('SELECT name FROM wf_step'),
        ]);
        $this->transactions()->run(fn () => $this->insertCompanyStep(self::ACME_FOODS, self::DESIGN_CHECK, 'Design check'));

        self::assertSame(self::ACME_FOODS, $tenantSetting);
        self::assertSame([], $visibleNames, 'a step of another organization is visible');
        self::assertFalse($this->connection()->isTransactionActive());
        self::assertSame(1, $this->stepRowCount(self::ACME_FOODS, self::DESIGN_CHECK));
    }

    #[Test]
    public function aFailedWriteIsRolledBackTheEntityManagerClosedAndTheExceptionRethrown(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        $thrown = $this->thrownBy(fn () => $this->transactions()->run(function (): void {
            $this->insertCompanyStep(self::ACME_FOODS, self::DESIGN_CHECK, 'Design check');

            throw new \DomainException('refused');
        }));

        self::assertInstanceOf(\DomainException::class, $thrown);
        self::assertSame('refused', $thrown->getMessage());
        self::assertFalse($this->connection()->isTransactionActive());
        self::assertFalse($this->entityManager()->isOpen());
        self::assertSame(0, $this->stepRowCount(self::ACME_FOODS, self::DESIGN_CHECK));
    }

    #[Test]
    public function aRunAfterAFailedOneReopensTheEntityManagerWithTheTenantFilterOfTheRequestsTenant(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::BETA_BAKERY));
        $this->transactions()->run(fn () => $this->connection()->executeStatement(
            'INSERT INTO zz_tenant_probe (organization_id, label) VALUES (?, ?)',
            [self::BETA_BAKERY, 'bakery probe'],
        ));
        $this->tenantContext()->reset();
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $repositoryEntityManager = $this->entityManager();
        $this->thrownBy(fn () => $this->transactions()->run(function (): void {
            $this->entityManager()->persist(new TenantProbe(self::REFUSED_PROBE, self::ACME_FOODS, 'refused probe'));

            throw new \DomainException('refused');
        }));
        self::assertFalse($repositoryEntityManager->isOpen(), 'the failed run did not close the entity manager');

        $this->transactions()->run(fn () => $this->entityManager()->persist(new TenantProbe(self::KEPT_PROBE, self::ACME_FOODS, 'kept probe')));
        // With row-level security off, only the tenant filter of the reopened entity manager keeps the bakery's row out.
        [$tenantSetting, $visibleLabels] = $this->withProbeRowLevelSecurityOff(fn (): array => $this->transactions()->run(fn (): array => [
            $this->connection()->fetchOne('SELECT current_setting(?, true)', [TenantSetting::NAME]),
            $this->entityManager()->createQuery(self::PROBE_LABELS_DQL)->getSingleColumnResult(),
        ]));

        self::assertTrue($repositoryEntityManager->isOpen(), 'the entity manager a repository holds is still closed');
        self::assertSame(self::ACME_FOODS, $tenantSetting);
        self::assertSame(['kept probe'], $visibleLabels);
        self::assertInstanceOf(
            TenantNotSet::class,
            $this->thrownBy(fn () => $this->entityManager()->createQuery(self::PROBE_LABELS_DQL)->getSingleColumnResult()),
            'the tenant of the committed run is still marked on the reopened entity manager',
        );
    }

    #[Test]
    public function withoutATenantTheWriteDoesNotRun(): void
    {
        /** @var \ArrayObject<int, true> $runs */
        $runs = new \ArrayObject();

        $thrown = $this->thrownBy(fn () => $this->transactions()->run(static function () use ($runs): void {
            $runs->append(true);
        }));

        self::assertInstanceOf(\LogicException::class, $thrown);
        self::assertCount(0, $runs, 'the write ran');
        self::assertFalse($this->connection()->isTransactionActive());
    }

    #[Test]
    public function insideAnOpenTransactionTheWriteDoesNotRun(): void
    {
        /** @var \ArrayObject<int, true> $runs */
        $runs = new \ArrayObject();
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $this->connection()->beginTransaction();
        try {
            $thrown = $this->thrownBy(fn () => $this->transactions()->run(static function () use ($runs): void {
                $runs->append(true);
            }));
            $nestingLevelAfter = $this->connection()->getTransactionNestingLevel();
        } finally {
            $this->connection()->rollBack();
        }

        self::assertInstanceOf(\LogicException::class, $thrown);
        self::assertCount(0, $runs, 'the write ran');
        self::assertSame(1, $nestingLevelAfter);
    }

    private function thrownBy(\Closure $call): ?\Throwable
    {
        try {
            $call();
        } catch (\Throwable $thrown) {
            return $thrown;
        }

        return null;
    }

    /**
     * A company step: no team and no core code.
     */
    private function insertCompanyStep(string $organizationId, string $stepId, string $name): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position, archived_at) VALUES (?, ?, NULL, ?, 'check', NULL, 'company', 0, NULL)",
            [$organizationId, $stepId, $name],
        );
    }

    /**
     * Through the migrator, which FORCE ROW LEVEL SECURITY binds too, so it counts under the organization as tenant.
     */
    private function stepRowCount(string $organizationId, string $stepId): int
    {
        $this->tenantContext()->reset();
        $this->tenantContext()->set(new OrganizationId($organizationId));
        try {
            $count = $this->migrator()->transactional(
                static fn (Connection $migrator): mixed => $migrator->fetchOne('SELECT count(*) FROM wf_step WHERE id = ?', [$stepId]),
            );
        } finally {
            $this->tenantContext()->reset();
        }

        return \is_int($count) ? $count : -1;
    }

    /**
     * Through the migrator, which owns wf_step and so may delete from it (the app may not), under each organization as
     * tenant.
     */
    private function removeStepsOf(string ...$organizationIds): void
    {
        foreach ($organizationIds as $organizationId) {
            $this->tenantContext()->set(new OrganizationId($organizationId));
            try {
                $this->migrator()->transactional(static function (Connection $migrator) use ($organizationId): void {
                    $migrator->executeStatement('DELETE FROM wf_step WHERE organization_id = ?', [$organizationId]);
                });
            } finally {
                $this->tenantContext()->reset();
            }
        }
    }

    private static function probeTable(): TenantProbeTable
    {
        return new TenantProbeTable(self::getContainer()->get('doctrine.dbal.migrator_connection'));
    }

    /**
     * Stands in for a role that bypasses row-level security, so only the tenant filter can keep the organizations apart.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function withProbeRowLevelSecurityOff(\Closure $work): mixed
    {
        $this->migrator()->executeStatement('ALTER TABLE zz_tenant_probe DISABLE ROW LEVEL SECURITY');
        try {
            return $work();
        } finally {
            $this->migrator()->executeStatement('ALTER TABLE zz_tenant_probe ENABLE ROW LEVEL SECURITY');
        }
    }

    private function transactions(): DoctrineTransactions
    {
        return new DoctrineTransactions($this->entityManager(), $this->tenantContext(), self::getContainer()->get('doctrine'));
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
