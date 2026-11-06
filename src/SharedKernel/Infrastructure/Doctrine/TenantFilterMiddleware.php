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
 * Gives TenantFilter the organization of each transaction on the default connection, so the filter and
 * row-level security always agree: at the outermost BEGIN it takes the organization from TenantContext (or
 * none), at COMMIT and ROLLBACK it removes it. A SQLFilter cannot take services, and TenantContext tells no one
 * when it changes, so the transaction boundary is the one point every unit of work (request, message,
 * command) passes. reset() removes the organization between worker requests, also when a connection was
 * closed in the middle of a transaction.
 *
 * Priority below TenantMiddleware's 0 makes this the outer wrapper: the filter gets the organization only
 * after BEGIN and set_config have both succeeded.
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
                        $this->middleware->useTenantOfNewTransaction();
                    }

                    public function commit(): void
                    {
                        try {
                            parent::commit();
                        } finally {
                            $this->middleware->removeTenant();
                        }
                    }

                    public function rollBack(): void
                    {
                        try {
                            parent::rollBack();
                        } finally {
                            $this->middleware->removeTenant();
                        }
                    }
                };
            }
        };
    }

    /**
     * @internal called by the wrapped driver connection right after an outermost BEGIN
     */
    public function useTenantOfNewTransaction(): void
    {
        $this->tenantFilter()?->useOrganization($this->tenantContext->get());
    }

    /**
     * @internal called by the wrapped driver connection when the outermost transaction ends
     */
    public function removeTenant(): void
    {
        $this->tenantFilter()?->useOrganization(null);
    }

    public function reset(): void
    {
        $this->removeTenant();
    }

    /**
     * A suspended filter keeps its instance and may be restored later, so it is kept in step too. A disabled
     * one is dropped; enabling it again builds a new filter that has no organization until the next BEGIN.
     */
    private function tenantFilter(): ?TenantFilter
    {
        $filters = ($this->entityManager)()->getFilters();
        $filter = $filters->getEnabledFilters()[TenantFilter::NAME] ?? $filters->getSuspendedFilters()[TenantFilter::NAME] ?? null;

        return $filter instanceof TenantFilter ? $filter : null;
    }
}
