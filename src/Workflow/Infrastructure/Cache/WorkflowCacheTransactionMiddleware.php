<?php

declare(strict_types=1);

namespace App\Workflow\Infrastructure\Cache;

use App\SharedKernel\Application\TenantContext;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Tells WorkflowCache where the outermost transaction on the default connection begins and ends, so the tags of a
 * change are invalidated only after its COMMIT succeeded, never for a rollback or a savepoint. Invalidating before
 * the commit would let a reader fill the entry again from the old rows.
 *
 * Priority below TenantFilterMiddleware's -10 makes this the outermost wrapper: the tenant is recorded only after BEGIN
 * and set_config have succeeded, and the tags are invalidated only after every inner COMMIT returned.
 */
#[AsMiddleware(connections: ['default'], priority: -20)]
final readonly class WorkflowCacheTransactionMiddleware implements Middleware
{
    public function __construct(private WorkflowCache $workflowCache, private TenantContext $tenantContext)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this->workflowCache, $this->tenantContext) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly WorkflowCache $workflowCache, private readonly TenantContext $tenantContext)
            {
                parent::__construct($driver);
            }

            public function connect(
                #[\SensitiveParameter]
                array $params,
            ): DriverConnection {
                return new class(parent::connect($params), $this->workflowCache, $this->tenantContext) extends AbstractConnectionMiddleware {
                    public function __construct(DriverConnection $connection, private readonly WorkflowCache $workflowCache, private readonly TenantContext $tenantContext)
                    {
                        parent::__construct($connection);
                    }

                    // DBAL calls these three only for the outermost transaction; savepoints never reach the driver.
                    public function beginTransaction(): void
                    {
                        parent::beginTransaction();
                        $this->workflowCache->transactionBegan($this->tenantContext->get());
                    }

                    public function commit(): void
                    {
                        try {
                            parent::commit();
                        } catch (\Throwable $failure) {
                            $this->workflowCache->transactionEnded();

                            throw $failure;
                        }
                        $this->workflowCache->transactionCommitted();
                    }

                    public function rollBack(): void
                    {
                        try {
                            parent::rollBack();
                        } finally {
                            $this->workflowCache->transactionEnded();
                        }
                    }
                };
            }
        };
    }
}
