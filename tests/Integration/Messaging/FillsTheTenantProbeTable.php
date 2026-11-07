<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\SharedKernel\Infrastructure\Doctrine\TenantSetting;
use App\Tests\Integration\Tenancy\TenantProbeTable;
use Doctrine\DBAL\Connection;

/**
 * Rows of organizations A and B in zz_tenant_probe, written through the migrator, so the app's entity manager and
 * TenantContext stay untouched before the message under test runs.
 */
trait FillsTheTenantProbeTable
{
    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';
    private const string ORG_B = '0197a3c0-0000-7000-8000-00000000000b';

    private static function recreateTheTenantProbeTableWithRowsOfAAndB(): void
    {
        self::tenantProbeTable()->recreate();
        self::insertTenantProbeLabelsAs(self::ORG_A, 'a-first', 'a-second');
        self::insertTenantProbeLabelsAs(self::ORG_B, 'b-only');
    }

    private static function dropTheTenantProbeTable(): void
    {
        self::tenantProbeTable()->drop();
    }

    private static function tenantProbeTable(): TenantProbeTable
    {
        return new TenantProbeTable(self::getContainer()->get('doctrine.dbal.migrator_connection'));
    }

    /**
     * The migrator owns the table, but FORCE ROW LEVEL SECURITY still checks each row against the organization set.
     */
    private static function insertTenantProbeLabelsAs(string $organizationId, string ...$labels): void
    {
        $migrator = self::getContainer()->get('doctrine.dbal.migrator_connection');
        $migrator->transactional(static function (Connection $migrator) use ($organizationId, $labels): void {
            $migrator->executeStatement('SELECT set_config(?, ?, true)', [TenantSetting::NAME, $organizationId]);
            foreach ($labels as $label) {
                $migrator->executeStatement('INSERT INTO zz_tenant_probe (organization_id, label) VALUES (?, ?)', [$organizationId, $label]);
            }
        });
    }
}
