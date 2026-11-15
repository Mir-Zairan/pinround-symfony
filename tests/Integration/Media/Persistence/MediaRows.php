<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media\Persistence;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;

/**
 * Removes the committed med_upload_intent and med_object rows of the given test organizations through the migrator,
 * which owns the tables and so may delete from them (the app may not), under each organization as tenant, as FORCE ROW
 * LEVEL SECURITY applies to the owner too. The caller makes sure no transaction is open.
 */
final readonly class MediaRows
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
                    $migrator->executeStatement('DELETE FROM med_object WHERE organization_id = ?', [$organizationId]);
                    $migrator->executeStatement('DELETE FROM med_upload_intent WHERE organization_id = ?', [$organizationId]);
                });
            } finally {
                $this->tenantContext->clear();
            }
        }
    }
}
