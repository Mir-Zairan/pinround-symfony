<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;

/**
 * Removes the committed projects of the given test organizations through the migrator, which owns tkt_project and so
 * may delete from it (the app may not), under each organization as tenant, as FORCE ROW LEVEL SECURITY applies to the
 * owner too. The caller makes sure no transaction is open and the entity manager is empty.
 */
final readonly class ProjectRows
{
    public function __construct(private Connection $migrator, private TenantContext $tenantContext)
    {
    }

    public function removeAllOf(string ...$organizationIds): void
    {
        foreach ($organizationIds as $organizationId) {
            $this->tenantContext->set(new OrganizationId($organizationId));
            try {
                $this->migrator->transactional(static function (Connection $migrator) use ($organizationId): void {
                    $migrator->executeStatement('DELETE FROM tkt_project WHERE organization_id = ?', [$organizationId]);
                });
            } finally {
                $this->tenantContext->clear();
            }
        }
    }
}
