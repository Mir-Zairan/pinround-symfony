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
 * - The tenant must be set before the transaction begins. Changing it inside a transaction has no effect
 *   until the next one; nested transactions (savepoints) do not run it again.
 * - Without a tenant nothing is set, and the tenant_isolation policy returns no rows (fails closed).
 * - When setting the tenant fails, the transaction is rolled back and that failure is thrown from beginTransaction().
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
