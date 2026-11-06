<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Connection;

/**
 * The test-only tenant table `zz_tenant_probe`. It lives only in the test database and never in a migration.
 * It is created and emptied through the migrator connection, which owns it.
 */
final readonly class TenantProbeTable
{
    public const string NAME = 'zz_tenant_probe';

    public function __construct(private Connection $migrator)
    {
    }

    public function createIfMissing(): void
    {
        if (null !== $this->migrator->fetchOne("SELECT to_regclass('public.zz_tenant_probe')")) {
            return;
        }

        $this->migrator->transactional(static function (Connection $migrator): void {
            $migrator->executeStatement(
                'CREATE TABLE zz_tenant_probe (
                    id uuid PRIMARY KEY DEFAULT uuidv7(),
                    organization_id uuid NOT NULL,
                    label text NOT NULL
                )',
            );
            $migrator->executeStatement('CREATE INDEX zz_tenant_probe_organization_id_label_idx ON zz_tenant_probe (organization_id, label)');
            foreach (TenantTable::enableRls(self::NAME) as $sql) {
                $migrator->executeStatement($sql);
            }
            $migrator->executeStatement('GRANT SELECT, INSERT, UPDATE, DELETE ON zz_tenant_probe TO pinround_app');
        });
    }

    /**
     * TRUNCATE, not DELETE: under FORCE ROW LEVEL SECURITY a DELETE by the owner without a tenant sees no rows
     * and removes nothing, while TRUNCATE is not subject to row security and the owner holds the right.
     */
    public function removeAllRows(): void
    {
        $this->migrator->executeStatement('TRUNCATE zz_tenant_probe');
    }
}
