<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;

/**
 * Adds committed Review rows of a test organization, and removes its rvw_round rows and with them their screenshots,
 * pins, check items and areas (ON DELETE CASCADE), through the migrator, which owns the tables and so may delete from them (the app
 * may not), under each organization as tenant, as FORCE ROW LEVEL SECURITY applies to the owner too. The caller makes
 * sure no transaction is open.
 */
final readonly class RoundRows
{
    public function __construct(private Connection $migrator, private TenantContext $tenantContext)
    {
    }

    /**
     * Commits the rows in the order given, each a table name and its column values, for rounds, screenshots, pins and
     * areas no command writes yet.
     *
     * @param list<array{string, array<string, mixed>}> $rows
     */
    public function addAllOf(string $organizationId, array $rows): void
    {
        $this->tenantContext->set(new OrganizationId($organizationId));
        try {
            $this->migrator->transactional(static function (Connection $migrator) use ($rows): void {
                foreach ($rows as [$table, $values]) {
                    $migrator->insert($table, $values);
                }
            });
        } finally {
            $this->tenantContext->clear();
        }
    }

    public function removeAllOf(string ...$organizationIds): void
    {
        foreach ($organizationIds as $organizationId) {
            $this->tenantContext->set(new OrganizationId($organizationId));
            try {
                $this->migrator->transactional(static function (Connection $migrator) use ($organizationId): void {
                    $migrator->executeStatement('DELETE FROM rvw_round WHERE organization_id = ?', [$organizationId]);
                });
            } finally {
                $this->tenantContext->clear();
            }
        }
    }
}
