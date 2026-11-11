<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Doctrine\CloseOnFailedBeginConnection;
use App\SharedKernel\Infrastructure\Doctrine\TenantMiddleware;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;

/**
 * Runs test work in a rolled-back transaction on a connection of its own, opened with the default connection's
 * parameters, so the transaction's tenant stays fixed while the work changes TenantContext. Leaves no tenant set.
 */
final readonly class OwnConnectionTransactions
{
    public function __construct(private Connection $defaultConnection, private TenantContext $tenantContext)
    {
    }

    /**
     * @template T
     *
     * @param \Closure(Connection): T $work
     * @param Middleware|null         $outermost wraps TenantMiddleware, so it does not see the tenant's set_config
     *
     * @return T
     */
    public function rolledBackAs(string $organizationId, \Closure $work, ?Middleware $outermost = null): mixed
    {
        $this->tenantContext->set(new OrganizationId($organizationId));
        $driver = new TenantMiddleware($this->tenantContext)->wrap(new PgSQLDriver());
        $connection = new CloseOnFailedBeginConnection($this->defaultConnection->getParams(), $outermost?->wrap($driver) ?? $driver);
        $connection->beginTransaction();
        try {
            return $work($connection);
        } finally {
            $connection->rollBack();
            $connection->close();
            $this->tenantContext->clear();
        }
    }

    /**
     * The transaction's tenant, which row-level security reads, is $tenantOrganizationId, while TenantContext, which
     * the SQL names, is $contextOrganizationId: a row of the context's organization the work still does not see is
     * hidden by row-level security alone.
     *
     * @template T
     *
     * @param \Closure(Connection): T $work
     *
     * @return T
     */
    public function rolledBackUnderAnotherContext(string $tenantOrganizationId, string $contextOrganizationId, \Closure $work, ?Middleware $outermost = null): mixed
    {
        return $this->rolledBackAs($tenantOrganizationId, function (Connection $connection) use ($contextOrganizationId, $work): mixed {
            $this->tenantContext->set(new OrganizationId($contextOrganizationId));

            return $work($connection);
        }, $outermost);
    }
}
