<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Sets the tenant for row-level security on every DBAL connection (ADR-0005).
 *
 * Right after each outermost BEGIN, when TenantContext holds an organization, it runs
 * `SELECT set_config('app.org_id', ?, true)` with the id bound, so the setting is transaction-local and
 * ends with COMMIT or ROLLBACK. Contract:
 * - The tenant must be set before the transaction begins; nested transactions (savepoints) do not run it again.
 *   On the default connection TenantContext refuses a change while a transaction is open (DoctrineTenantSwitchGuard);
 *   on another connection a change takes effect at its next transaction.
 * - Without a tenant nothing is set, and the tenant_isolation policy returns no rows (fails closed).
 * - When setting the tenant fails, beginTransaction() throws that failure, and CloseOnFailedBeginConnection
 *   closes the connection so neither DBAL nor the server keeps the half-begun transaction.
 * - Statements outside a transaction (autocommit) never get a tenant.
 */
#[AsMiddleware]
final readonly class TenantMiddleware implements Middleware
{
    public function __construct(private TenantContext $tenantContext)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new TenantDriver($driver, $this->tenantContext);
    }
}
