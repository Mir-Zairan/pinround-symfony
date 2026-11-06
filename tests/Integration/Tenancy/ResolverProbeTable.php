<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Connection;

/**
 * The test-only tenant table `zz_resolver_probe` with TenantTable::allowResolverRead on three of its
 * four columns, and SECURITY DEFINER functions owned by pinround_resolver that read it: one reads granted
 * columns, one the ungranted `secret_note`, one names its input like the column it matches. Never in a
 * migration; created and dropped through the migrator connection.
 */
final readonly class ResolverProbeTable
{
    public const array RESOLVER_COLUMNS = ['id', 'organization_id', 'lookup_key'];

    /** Function name => its input, then the rest of its definition. */
    private const array FUNCTIONS = [
        'zz_resolve_probe' => '(p_lookup_key text) RETURNS TABLE (id uuid, organization_id uuid) LANGUAGE sql STABLE SECURITY DEFINER'
            .' SET search_path = pg_catalog, public, pg_temp'
            .' AS $probe$ SELECT id, organization_id FROM zz_resolver_probe WHERE lookup_key = p_lookup_key $probe$',
        'zz_resolve_probe_note' => '(p_lookup_key text) RETURNS TABLE (secret_note text) LANGUAGE sql STABLE SECURITY DEFINER'
            .' SET search_path = pg_catalog, public, pg_temp'
            .' AS $probe$ SELECT secret_note FROM zz_resolver_probe WHERE lookup_key = p_lookup_key $probe$',
        // The mistake the p_ prefix prevents: the column wins over the same-named parameter, so this matches every row.
        'zz_resolve_probe_unprefixed' => '(lookup_key text) RETURNS TABLE (id uuid, organization_id uuid) LANGUAGE sql STABLE SECURITY DEFINER'
            .' SET search_path = pg_catalog, public, pg_temp'
            .' AS $probe$ SELECT id, organization_id FROM zz_resolver_probe WHERE lookup_key = lookup_key $probe$',
    ];

    public function __construct(private Connection $migrator)
    {
    }

    /**
     * Drops any earlier copy first, so the policy always matches the current TenantTable output.
     */
    public function recreate(): void
    {
        $this->migrator->transactional(function (Connection $migrator): void {
            $this->dropIn($migrator);
            $migrator->executeStatement(
                'CREATE TABLE zz_resolver_probe (
                    id uuid PRIMARY KEY DEFAULT uuidv7(),
                    organization_id uuid NOT NULL,
                    lookup_key text NOT NULL,
                    secret_note text
                )',
            );
            $migrator->executeStatement('CREATE INDEX zz_resolver_probe_organization_id_lookup_key_idx ON zz_resolver_probe (organization_id, lookup_key)');
            foreach ([...TenantTable::enableRls('zz_resolver_probe'), ...TenantTable::allowResolverRead('zz_resolver_probe', self::RESOLVER_COLUMNS)] as $sql) {
                $migrator->executeStatement($sql);
            }
            $migrator->executeStatement('GRANT SELECT, INSERT ON zz_resolver_probe TO pinround_app');

            foreach (self::FUNCTIONS as $name => $definition) {
                $migrator->executeStatement(\sprintf('CREATE FUNCTION %s%s', $name, $definition));
                $migrator->executeStatement(\sprintf('REVOKE EXECUTE ON FUNCTION %s(text) FROM PUBLIC', $name));
                $migrator->executeStatement(\sprintf('GRANT EXECUTE ON FUNCTION %s(text) TO pinround_app', $name));
            }
            $migrator->executeStatement('GRANT CREATE ON SCHEMA public TO pinround_resolver');
            foreach (array_keys(self::FUNCTIONS) as $name) {
                $migrator->executeStatement(\sprintf('ALTER FUNCTION %s(text) OWNER TO pinround_resolver', $name));
            }
            $migrator->executeStatement('REVOKE CREATE ON SCHEMA public FROM pinround_resolver');
        });
    }

    public function drop(): void
    {
        $this->migrator->transactional($this->dropIn(...));
    }

    /**
     * Only the owner may drop the functions, and the migrator holds pinround_resolver's rights only after SET ROLE.
     */
    private function dropIn(Connection $migrator): void
    {
        $migrator->executeStatement('SET LOCAL ROLE pinround_resolver');
        foreach (array_keys(self::FUNCTIONS) as $name) {
            $migrator->executeStatement(\sprintf('DROP FUNCTION IF EXISTS %s(text)', $name));
        }
        $migrator->executeStatement('RESET ROLE');
        $migrator->executeStatement('DROP TABLE IF EXISTS zz_resolver_probe');
    }
}
