<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;

/**
 * Removes the committed Organization rows of the given test organizations through the migrator, which owns the
 * tables and so may delete from all of them, under each organization as tenant (FORCE ROW LEVEL SECURITY applies
 * to the owner too). The caller makes sure no transaction is open and the entity manager is empty.
 */
final readonly class OrganizationRows
{
    /** Every org_ table, children first. */
    public const array TABLES = ['org_team_member', 'org_team', 'org_settings', 'org_membership', 'org_organization'];

    public function __construct(private Connection $migrator, private TenantContext $tenantContext)
    {
    }

    public function removeAllOf(string ...$organizationIds): void
    {
        foreach ($organizationIds as $organizationId) {
            $this->tenantContext->set(new OrganizationId($organizationId));
            try {
                $this->migrator->transactional(static function (Connection $migrator) use ($organizationId): void {
                    foreach (self::TABLES as $table) {
                        $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                    }
                });
            } finally {
                $this->tenantContext->clear();
            }
        }
    }
}
