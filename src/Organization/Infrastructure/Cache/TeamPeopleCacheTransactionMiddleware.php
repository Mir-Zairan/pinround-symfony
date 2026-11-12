<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Cache;

use App\SharedKernel\Application\TenantContext;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Tells TeamPeopleCache where the outermost transaction on the default connection begins and ends, so the tags of a
 * change are invalidated only after its COMMIT succeeded, never for a rollback or a savepoint. Invalidating before the
 * commit would let a reader fill the entry again from the old rows.
 *
 * Priority below TenantFilterMiddleware's -10 wraps it around that one: the tenant is recorded only after BEGIN and
 * set_config have succeeded, and the tags are invalidated only after every inner COMMIT returned.
 */
#[AsMiddleware(connections: ['default'], priority: -30)]
final readonly class TeamPeopleCacheTransactionMiddleware implements Middleware
{
    public function __construct(private TeamPeopleCache $teamPeopleCache, private TenantContext $tenantContext)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this->teamPeopleCache, $this->tenantContext) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly TeamPeopleCache $teamPeopleCache, private readonly TenantContext $tenantContext)
            {
                parent::__construct($driver);
            }

            public function connect(
                #[\SensitiveParameter]
                array $params,
            ): DriverConnection {
                return new class(parent::connect($params), $this->teamPeopleCache, $this->tenantContext) extends AbstractConnectionMiddleware {
                    public function __construct(DriverConnection $connection, private readonly TeamPeopleCache $teamPeopleCache, private readonly TenantContext $tenantContext)
                    {
                        parent::__construct($connection);
                    }

                    // DBAL calls these three only for the outermost transaction; savepoints never reach the driver.
                    public function beginTransaction(): void
                    {
                        parent::beginTransaction();
                        $this->teamPeopleCache->transactionBegan($this->tenantContext->get());
                    }

                    public function commit(): void
                    {
                        try {
                            parent::commit();
                        } catch (\Throwable $failure) {
                            $this->teamPeopleCache->transactionEnded();

                            throw $failure;
                        }
                        $this->teamPeopleCache->transactionCommitted();
                    }

                    public function rollBack(): void
                    {
                        try {
                            parent::rollBack();
                        } finally {
                            $this->teamPeopleCache->transactionEnded();
                        }
                    }
                };
            }
        };
    }
}
