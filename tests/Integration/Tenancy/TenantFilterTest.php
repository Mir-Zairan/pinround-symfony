<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Doctrine\TenantFilter;
use App\Tests\Fixtures\Entity\TenantProbe;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Yaml\Yaml;

/**
 * Proves TenantFilter and TenantFilterMiddleware on the kernel's entity manager (ADR-0005), with the test-only
 * TenantProbe entity on zz_tenant_probe.
 */
final class TenantFilterTest extends KernelTestCase
{
    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';
    private const string ORG_B = '0197a3c0-0000-7000-8000-00000000000b';
    private const string LABELS_DQL = 'SELECT p.label FROM '.TenantProbe::class.' p ORDER BY p.label';

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
        $this->connection = $container->get('doctrine.dbal.default_connection');
        $this->entityManager = $container->get('doctrine.orm.default_entity_manager');
        $this->tenantContext = $container->get(TenantContext::class);

        $this->insertLabelsAs(self::ORG_A, 'a-first', 'a-second');
        $this->insertLabelsAs(self::ORG_B, 'b-only');
    }

    #[Test]
    public function theTenantFilterIsTheOnlyFilterAndIsEnabledByDefault(): void
    {
        $enabledFilters = $this->entityManager->getFilters()->getEnabledFilters();

        self::assertSame([TenantFilter::NAME], array_keys($enabledFilters));
        self::assertInstanceOf(TenantFilter::class, $enabledFilters[TenantFilter::NAME]);
    }

    #[Test]
    public function theSqlReadsTheOrganizationOfTheTransactionInsteadOfALiteralId(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));

        $sql = $this->entityManager->wrapInTransaction(fn (): mixed => $this->labelsQuery()->getSQL());

        self::assertIsString($sql);
        self::assertStringContainsString(".organization_id = NULLIF(current_setting('app.org_id', true), '')::uuid", $sql);
        self::assertStringNotContainsString(self::ORG_A, $sql);
    }

    #[Test]
    public function allOrganizationsShareOneQueryCacheEntryAndEachSeesOnlyItsOwnRows(): void
    {
        $queryCache = new ArrayAdapter();
        $this->entityManager->getConfiguration()->setQueryCache($queryCache);

        self::assertSame(['a-first', 'a-second'], $this->labelsAs(self::ORG_A));
        self::assertSame(['b-only'], $this->labelsAs(self::ORG_B));
        self::assertSame(['a-first', 'a-second'], $this->labelsAs(self::ORG_A));
        self::assertCount(1, $queryCache->getValues());
    }

    #[Test]
    public function aRepositoryFindByReturnsOnlyTheRowsOfTheTransactionsOrganizationWithRowLevelSecurityOff(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));

        $labels = $this->withRowLevelSecurityOff(fn (): mixed => $this->entityManager->wrapInTransaction(fn (): array => array_map(
            static fn (TenantProbe $probe): string => $probe->label(),
            $this->entityManager->getRepository(TenantProbe::class)->findBy([], ['label' => 'ASC']),
        )));

        self::assertSame(['a-first', 'a-second'], $labels);
    }

    #[Test]
    public function findDoesNotReturnTheIdentityMapsObjectOfTheLastOrganizationWhenTheOrganizationChanges(): void
    {
        $rowOfOrgA = $this->findOneAs(self::ORG_A, 'a-first');
        $this->tenantContext->set(new OrganizationId(self::ORG_B));

        $found = $this->entityManager->wrapInTransaction(
            fn (): ?TenantProbe => $this->entityManager->find(TenantProbe::class, $rowOfOrgA->id()),
        );

        self::assertNull($found);
    }

    #[Test]
    public function aTransactionForTheSameOrganizationKeepsTheIdentityMap(): void
    {
        $rowOfOrgA = $this->findOneAs(self::ORG_A, 'a-first');
        $this->tenantContext->set(new OrganizationId(self::ORG_A));

        $found = $this->entityManager->wrapInTransaction(
            fn (): ?TenantProbe => $this->entityManager->find(TenantProbe::class, $rowOfOrgA->id()),
        );

        self::assertSame($rowOfOrgA, $found);
    }

    #[Test]
    public function findInATransactionWithoutAnOrganizationAfterOrgAThrowsInsteadOfReturningTheCachedObject(): void
    {
        $rowOfOrgA = $this->findOneAs(self::ORG_A, 'a-first');

        $this->expectException(TenantNotSet::class);
        $this->entityManager->wrapInTransaction(
            fn (): ?TenantProbe => $this->entityManager->find(TenantProbe::class, $rowOfOrgA->id()),
        );
    }

    #[Test]
    public function theFirstOrmQueryInATransactionBegunBeforeTheEntityManagerWasBuiltSeesOnlyTheTransactionsOrganization(): void
    {
        self::ensureKernelShutdown();
        $container = self::getContainer();
        $connection = $container->get('doctrine.dbal.default_connection');
        $entityManager = $container->get('doctrine.orm.default_entity_manager');
        $container->get(TenantContext::class)->set(new OrganizationId(self::ORG_A));
        self::assertTrue(new \ReflectionClass($entityManager)->isUninitializedLazyObject($entityManager));

        $labels = $this->withRowLevelSecurityOff(static function () use ($connection, $entityManager): array {
            $connection->beginTransaction();
            try {
                return $entityManager->createQuery(self::LABELS_DQL)->getSingleColumnResult();
            } finally {
                $connection->rollBack();
            }
        });

        self::assertSame(['a-first', 'a-second'], $labels);
    }

    #[Test]
    public function theResultCacheStoresNothingInEveryEnvironment(): void
    {
        $doctrineConfig = Yaml::parseFile(
            self::getContainer()->getParameter('kernel.project_dir').'/config/packages/doctrine.yaml',
            Yaml::PARSE_CONSTANT,
        );

        self::assertSame([
            'doctrine.orm.result_cache_driver' => ['type' => 'service', 'id' => NullAdapter::class],
            'when@prod.doctrine.orm.query_cache_driver' => ['type' => 'pool', 'pool' => 'doctrine.system_cache_pool'],
        ], self::cacheSettings($doctrineConfig));
        self::assertInstanceOf(NullAdapter::class, $this->entityManager->getConfiguration()->getResultCache());
    }

    #[Test]
    public function aTenantOwnedQueryWithTheResultCacheOnStillAsksTheDatabaseForEachOrganization(): void
    {
        $cachedLabels = fn (): mixed => $this->labelsQuery()->enableResultCache(3600, 'tenant-probe-labels')->getSingleColumnResult();

        $this->tenantContext->set(new OrganizationId(self::ORG_A));
        self::assertSame(['a-first', 'a-second'], $this->entityManager->wrapInTransaction($cachedLabels));
        $this->tenantContext->set(new OrganizationId(self::ORG_B));
        self::assertSame(['b-only'], $this->entityManager->wrapInTransaction($cachedLabels));
    }

    #[Test]
    public function withRowLevelSecurityOffTheFilterStillRestrictsToTheTransactionsOrganization(): void
    {
        [$labelsFiltered, $labelsUnfiltered] = $this->withRowLevelSecurityOff(function (): array {
            $labelsFiltered = $this->labelsAs(self::ORG_A);
            $this->entityManager->getFilters()->disable(TenantFilter::NAME);

            return [$labelsFiltered, $this->labelsAs(self::ORG_A)];
        });

        self::assertSame(['a-first', 'a-second'], $labelsFiltered);
        self::assertSame(['a-first', 'a-second', 'b-only'], $labelsUnfiltered);
    }

    #[Test]
    public function aWorkerResetDoesNotBuildAnEntityManagerThatWasNeverUsed(): void
    {
        self::ensureKernelShutdown();
        $container = self::getContainer();
        $container->get('doctrine.dbal.default_connection');
        $entityManager = $container->get('doctrine.orm.default_entity_manager');
        self::assertTrue(new \ReflectionClass($entityManager)->isUninitializedLazyObject($entityManager));

        $container->get('services_resetter')->reset();

        self::assertTrue(new \ReflectionClass($entityManager)->isUninitializedLazyObject($entityManager));
    }

    #[Test]
    public function aReusedQueryFollowsTheOrganizationOfEachTransaction(): void
    {
        $query = $this->labelsQuery();
        $labelsOfQuery = static fn (): mixed => $query->getSingleColumnResult();

        $this->tenantContext->set(new OrganizationId(self::ORG_A));
        self::assertSame(['a-first', 'a-second'], $this->entityManager->wrapInTransaction($labelsOfQuery));
        $this->tenantContext->set(new OrganizationId(self::ORG_B));
        self::assertSame(['b-only'], $this->entityManager->wrapInTransaction($labelsOfQuery));
    }

    #[Test]
    public function aTransactionWithoutAnOrganizationThrowsTenantNotSetAfterTheContextWasCleared(): void
    {
        $this->entityManager->getConfiguration()->setQueryCache(new ArrayAdapter());
        self::assertSame(['a-first', 'a-second'], $this->labelsAs(self::ORG_A));

        $this->expectException(TenantNotSet::class);
        $this->entityManager->wrapInTransaction($this->visibleLabels(...));
    }

    #[Test]
    public function aQueryOutsideATransactionThrowsTenantNotSetEvenWithAnOrganizationInTheContext(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));

        $this->expectException(TenantNotSet::class);
        $this->visibleLabels();
    }

    #[Test]
    public function aTransactionBegunWithoutAnOrganizationThrowsAfterAClosedConnectionLeftTheOldOneBehind(): void
    {
        $this->leaveOrgAInTheFilterThroughAClosedConnection();

        $this->expectException(TenantNotSet::class);
        $this->entityManager->wrapInTransaction($this->visibleLabels(...));
    }

    #[Test]
    public function theWorkerResetRemovesTheOrganizationAClosedConnectionLeftBehind(): void
    {
        $this->leaveOrgAInTheFilterThroughAClosedConnection();
        self::getContainer()->get('services_resetter')->reset();

        $this->expectException(TenantNotSet::class);
        $this->visibleLabels();
    }

    #[Test]
    public function aSuspendedFilterIsKeptInStepAndAppliesTheTransactionsOrganizationWhenRestored(): void
    {
        $filters = $this->entityManager->getFilters();
        $filters->suspend(TenantFilter::NAME);
        $this->tenantContext->set(new OrganizationId(self::ORG_B));

        $labels = $this->entityManager->wrapInTransaction(function () use ($filters): array {
            $filters->restore(TenantFilter::NAME);

            return $this->visibleLabels();
        });

        self::assertSame(['b-only'], $labels);
    }

    #[Test]
    public function withTheFilterOffRowLevelSecurityStillHidesTheOtherOrganization(): void
    {
        $this->entityManager->getFilters()->disable(TenantFilter::NAME);
        $this->tenantContext->set(new OrganizationId(self::ORG_A));

        [$sql, $labels] = $this->entityManager->wrapInTransaction(
            fn (): array => [$this->labelsQuery()->getSQL(), $this->visibleLabels()],
        );

        self::assertIsString($sql);
        self::assertStringNotContainsString('organization_id', $sql);
        self::assertSame(['a-first', 'a-second'], $labels);
    }

    #[Test]
    public function anEntityThatIsNotTenantOwnedGetsNoConditionEvenWithoutAnOrganization(): void
    {
        $filter = $this->entityManager->getFilters()->getFilter(TenantFilter::NAME);

        self::assertSame('', $filter->addFilterConstraint(new ClassMetadata(\stdClass::class), 't0_'));

        $this->expectException(TenantNotSet::class);
        $filter->addFilterConstraint($this->entityManager->getClassMetadata(TenantProbe::class), 't0_');
    }

    /**
     * @return array<string, mixed> every key that configures a query or result cache, by dotted path
     */
    private static function cacheSettings(mixed $config, string $path = ''): array
    {
        if (!\is_array($config)) {
            return [];
        }

        $settings = [];
        foreach ($config as $key => $value) {
            $keyPath = ltrim($path.'.'.$key, '.');
            if (str_contains((string) $key, 'cache_driver') || str_contains((string) $key, 'result_cache')) {
                $settings[$keyPath] = $value;
            }
            $settings += self::cacheSettings($value, $keyPath);
        }

        return $settings;
    }

    private static function probeTable(): TenantProbeTable
    {
        return new TenantProbeTable(self::getContainer()->get('doctrine.dbal.migrator_connection'));
    }

    /**
     * Stands in for a role that bypasses row-level security, so only the filter can keep the organizations apart.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function withRowLevelSecurityOff(\Closure $work): mixed
    {
        $migrator = self::getContainer()->get('doctrine.dbal.migrator_connection');
        $migrator->executeStatement('ALTER TABLE zz_tenant_probe DISABLE ROW LEVEL SECURITY');
        try {
            return $work();
        } finally {
            $migrator->executeStatement('ALTER TABLE zz_tenant_probe ENABLE ROW LEVEL SECURITY');
        }
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
    private function labelsAs(string $organizationId): array
    {
        $this->tenantContext->set(new OrganizationId($organizationId));
        $labels = $this->entityManager->wrapInTransaction($this->visibleLabels(...));
        $this->tenantContext->clear();

        return $labels;
    }

    /**
     * @return list<mixed>
     */
    private function visibleLabels(): array
    {
        return array_values($this->labelsQuery()->getSingleColumnResult());
    }

    /**
     * @return Query<mixed, mixed>
     */
    private function labelsQuery(): Query
    {
        return $this->entityManager->createQuery(self::LABELS_DQL);
    }

    private function findOneAs(string $organizationId, string $label): TenantProbe
    {
        $this->tenantContext->set(new OrganizationId($organizationId));
        $row = $this->entityManager->wrapInTransaction(
            fn (): ?TenantProbe => $this->entityManager->getRepository(TenantProbe::class)->findOneBy(['label' => $label]),
        );
        $this->tenantContext->clear();
        self::assertInstanceOf(TenantProbe::class, $row);

        return $row;
    }

    /**
     * Closing the connection drops the transaction without a COMMIT or ROLLBACK, so the filter keeps org A.
     */
    private function leaveOrgAInTheFilterThroughAClosedConnection(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));
        $this->connection->beginTransaction();
        try {
            $labels = $this->visibleLabels();
        } catch (\Throwable $setupFailure) {
            // The callers expect TenantNotSet, so a failure here must not pass as that.
            self::fail('Setting up org A in the filter failed with '.$setupFailure::class);
        }
        self::assertSame(['a-first', 'a-second'], $labels);
        $this->connection->close();
        $this->tenantContext->clear();
    }
}
