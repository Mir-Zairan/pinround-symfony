<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Tells TenantFilter whether the current transaction on the default connection has an organization, so the
 * filter throws TenantNotSet exactly when row-level security would see no tenant: at the outermost BEGIN it
 * marks the tenant set when TenantContext holds one, at COMMIT and ROLLBACK it clears the mark. It follows the
 * transaction boundary because that is where RLS takes the organization too; a SQLFilter cannot take services.
 * reset() clears the mark between worker requests, also when a connection was closed in the middle of a
 * transaction. Clearing the entity manager when the organization changes is EntityManagerTenantSwitchGuard's job.
 *
 * Priority below TenantMiddleware's 0 makes this the outer wrapper: the mark is set only after BEGIN and
 * set_config have both succeeded.
 */
#[AsMiddleware(connections: ['default'], priority: -10)]
final readonly class TenantFilterMiddleware implements Middleware, ResetInterface
{
    /**
     * @param \Closure(): EntityManagerInterface $entityManager a closure, because the entity manager is built on this connection
     */
    public function __construct(
        private TenantContext $tenantContext,
        #[AutowireServiceClosure('doctrine.orm.default_entity_manager')]
        private \Closure $entityManager,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly TenantFilterMiddleware $middleware)
            {
                parent::__construct($driver);
            }

            public function connect(
                #[\SensitiveParameter]
                array $params,
            ): DriverConnection {
                return new class(parent::connect($params), $this->middleware) extends AbstractConnectionMiddleware {
                    public function __construct(DriverConnection $connection, private readonly TenantFilterMiddleware $middleware)
                    {
                        parent::__construct($connection);
                    }

                    // DBAL calls these three only for the outermost transaction; savepoints never reach the driver.
                    public function beginTransaction(): void
                    {
                        parent::beginTransaction();
                        $this->middleware->markTenantOfNewTransaction();
                    }

                    public function commit(): void
                    {
                        try {
                            parent::commit();
                        } finally {
                            $this->middleware->clearTenantMark();
                        }
                    }

                    public function rollBack(): void
                    {
                        try {
                            parent::rollBack();
                        } finally {
                            $this->middleware->clearTenantMark();
                        }
                    }
                };
            }
        };
    }

    /**
     * @internal called by the wrapped driver connection right after an outermost BEGIN
     *
     * This builds the entity manager when it is not built yet: one built later in the transaction would start
     * with no mark and throw. In worker mode that happens once per worker.
     */
    public function markTenantOfNewTransaction(): void
    {
        $this->tenantFilter(($this->entityManager)())?->markTenantSet(null !== $this->tenantContext->get());
    }

    /**
     * @internal called by the wrapped driver connection when the outermost transaction ends
     */
    public function clearTenantMark(): void
    {
        $entityManager = ($this->entityManager)();
        // An entity manager that is not built yet has no filter; building it here would cost every request.
        if ($this->isBuilt($entityManager)) {
            $this->tenantFilter($entityManager)?->markTenantSet(false);
        }
    }

    public function reset(): void
    {
        $this->clearTenantMark();
    }

    private function isBuilt(EntityManagerInterface $entityManager): bool
    {
        return !new \ReflectionClass($entityManager)->isUninitializedLazyObject($entityManager);
    }

    /**
     * A suspended filter keeps its instance and may be restored later, so it is kept in step too. A disabled
     * one is dropped; enabling it again builds a new filter that has no mark until the next BEGIN.
     */
    private function tenantFilter(EntityManagerInterface $entityManager): ?TenantFilter
    {
        $filters = $entityManager->getFilters();
        $filter = $filters->getEnabledFilters()[TenantFilter::NAME] ?? $filters->getSuspendedFilters()[TenantFilter::NAME] ?? null;

        return $filter instanceof TenantFilter ? $filter : null;
    }
}
