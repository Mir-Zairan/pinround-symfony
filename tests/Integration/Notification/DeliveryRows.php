<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;

/**
 * Removes the committed ntf_delivery rows of the given test organizations through the migrator, which owns the table and
 * so may delete from it (the app may not), under each organization as tenant, as FORCE ROW LEVEL SECURITY applies to the
 * owner too. ntf_delivery has no ticket, so TicketRows::removeAllOf leaves these rows behind. The caller makes sure no
 * transaction is open.
 */
final readonly class DeliveryRows
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
                    $migrator->executeStatement('DELETE FROM ntf_delivery WHERE organization_id = ?', [$organizationId]);
                });
            } finally {
                $this->tenantContext->clear();
            }
        }
    }
}
