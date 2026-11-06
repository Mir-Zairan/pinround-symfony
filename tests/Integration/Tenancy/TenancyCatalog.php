<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\SharedKernel\Infrastructure\Doctrine\Migration\ResolverFunctions;
use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Connection;

/**
 * The tenancy rules of ADR-0005 read from the PostgreSQL catalog. Each check returns one line per
 * offending object, so an empty list means the rule holds. A tenant table is a table with an
 * `organization_id` column, plus every partition or inheritance child below it, in any schema but the
 * system ones; tables and functions an extension owns are left out.
 */
final readonly class TenancyCatalog
{
    private const string REFERENCE_TABLE = 'zz_tenancy_catalog_reference';

    /** Every schema but PostgreSQL's own and the per-session temporary ones; any other the migrator could create. */
    private const string USER_SCHEMAS = "(SELECT n.oid FROM pg_namespace n
        WHERE n.nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast')
          AND n.nspname NOT LIKE 'pg\\_temp\\_%' AND n.nspname NOT LIKE 'pg\\_toast\\_temp\\_%')";

    private const string TENANT_TABLES = 'WITH RECURSIVE tenant_table AS (
            SELECT c.oid FROM pg_class c
            WHERE c.relnamespace IN '.self::USER_SCHEMAS."
              AND c.relkind IN ('r', 'p')
              AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.deptype = 'e')
              AND EXISTS (
                  SELECT 1 FROM pg_attribute a
                  WHERE a.attrelid = c.oid AND a.attname = 'organization_id' AND a.attnum > 0 AND NOT a.attisdropped
              )
            UNION
            SELECT i.inhrelid FROM pg_inherits i JOIN tenant_table t ON i.inhparent = t.oid
        )";

    /**
     * @param array<string, array<string, mixed>> $policiesTenantTableWrites policy name => shape, see read()
     */
    private function __construct(private Connection $connection, private array $policiesTenantTableWrites)
    {
    }

    /**
     * Learns the policies TenantTable::enableRls and ::allowResolverRead write, as PostgreSQL stores them,
     * from a table the migrator creates and rolls back; then reads the catalog through $connection.
     */
    public static function read(Connection $connection, Connection $migrator): self
    {
        $migrator->beginTransaction();
        try {
            $migrator->executeStatement(\sprintf('CREATE TABLE %s (id uuid, organization_id uuid NOT NULL)', self::REFERENCE_TABLE));
            foreach ([...TenantTable::enableRls(self::REFERENCE_TABLE), ...TenantTable::allowResolverRead(self::REFERENCE_TABLE, ['id', 'organization_id'])] as $sql) {
                $migrator->executeStatement($sql);
            }
            $policies = [];
            foreach (self::policiesOnTenantTables($migrator) as $policy) {
                if (self::REFERENCE_TABLE === $policy['table_name']) {
                    $policies[self::text($policy['name'])] = self::shapeOf($policy);
                }
            }
        } finally {
            $migrator->rollBack();
        }

        return new self($connection, $policies);
    }

    /**
     * @return list<string> "table: what is missing", for each tenant table without ENABLE and FORCE ROW LEVEL
     *                      SECURITY and the tenant_isolation policy exactly as TenantTable::enableRls writes it
     */
    public function tenantTablesWithoutIsolation(): array
    {
        $tenantIsolationTables = [];
        foreach (self::policiesOnTenantTables($this->connection) as $policy) {
            if ('tenant_isolation' === $policy['name'] && self::shapeOf($policy) === $this->policiesTenantTableWrites['tenant_isolation']) {
                $tenantIsolationTables[] = $policy['table_name'];
            }
        }

        $violations = [];
        foreach ($this->connection->fetchAllAssociative(
            self::TENANT_TABLES.' SELECT c.oid::regclass::text AS table_name, c.relrowsecurity, c.relforcerowsecurity
             FROM tenant_table t JOIN pg_class c ON c.oid = t.oid
             WHERE c.relkind IN (\'r\', \'p\')
             ORDER BY 1',
        ) as $table) {
            $missing = array_keys(array_filter([
                'ROW LEVEL SECURITY off' => true !== $table['relrowsecurity'],
                'FORCE ROW LEVEL SECURITY off' => true !== $table['relforcerowsecurity'],
                'no tenant_isolation policy as TenantTable::enableRls writes it' => !\in_array($table['table_name'], $tenantIsolationTables, true),
            ]));
            if ([] !== $missing) {
                $violations[] = self::text($table['table_name']).': '.implode(', ', $missing);
            }
        }

        return $violations;
    }

    /**
     * @return list<string> "table: policy", for each policy on a tenant table that is neither tenant_isolation nor
     *                      resolver_read exactly as TenantTable::allowResolverRead writes it (FOR SELECT TO pinround_resolver)
     */
    public function foreignPoliciesOnTenantTables(): array
    {
        $violations = [];
        foreach (self::policiesOnTenantTables($this->connection) as $policy) {
            if ('tenant_isolation' !== $policy['name'] && self::shapeOf($policy) !== $this->policiesTenantTableWrites['resolver_read']) {
                $violations[] = self::text($policy['table_name']).': '.self::text($policy['name']);
            }
        }

        return $violations;
    }

    /**
     * Superusers are left out: they bypass row-level security whatever rolbypassrls says, and the only one
     * is the bootstrap admin account that runs roles.sql.
     *
     * @return list<string> roles other than $allowedRole that bypass row-level security
     */
    public function rolesBypassingRowLevelSecurityOtherThan(string $allowedRole): array
    {
        return self::strings($this->connection->fetchFirstColumn(
            'SELECT rolname FROM pg_roles WHERE rolbypassrls AND NOT rolsuper AND rolname <> ? ORDER BY 1',
            [$allowedRole],
        ));
    }

    /**
     * Only the resolver functions may run with their owner's rights, and pinround_resolver owns nothing else.
     * Their search_path, inputs, outputs and EXECUTE rights are ResolverFunctionTest's job.
     *
     * @return list<string> SECURITY DEFINER functions (extension functions left out) that are not exactly
     *                      ResolverFunctions::NAMES in public, one each, owned by pinround_resolver, and functions
     *                      that pinround_resolver owns but NAMES does not list; in any schema but the system ones
     */
    public function securityDefinerFunctionsOutsideTheResolvers(): array
    {
        $violations = [];
        $securityDefinerCounts = array_fill_keys(ResolverFunctions::NAMES, 0);
        foreach ($this->connection->fetchAllAssociative(
            "SELECT p.oid::regprocedure::text AS signature, p.proname AS name, p.prosecdef AS is_security_definer,
                    p.pronamespace = 'public'::regnamespace AS is_in_public, p.proowner = 'pinround_resolver'::regrole AS is_resolver_owned
             FROM pg_proc p
             WHERE (p.prosecdef AND p.pronamespace IN ".self::USER_SCHEMAS." OR p.proowner = 'pinround_resolver'::regrole)
               AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_proc'::regclass AND d.objid = p.oid AND d.deptype = 'e')
             ORDER BY 1",
        ) as $function) {
            $isListed = true === $function['is_in_public'] && \in_array($function['name'], ResolverFunctions::NAMES, true);
            $isSecurityDefiner = true === $function['is_security_definer'];
            if ($isSecurityDefiner && $isListed) {
                ++$securityDefinerCounts[self::text($function['name'])];
            }
            if ($isSecurityDefiner && !$isListed) {
                $violations[] = self::text($function['signature']).' is SECURITY DEFINER but not in ResolverFunctions::NAMES';
            }
            if (true === $function['is_resolver_owned'] && !$isListed) {
                $violations[] = self::text($function['signature']).' is owned by pinround_resolver but not in ResolverFunctions::NAMES';
            }
            if (true !== $function['is_resolver_owned'] && $isListed) {
                $violations[] = self::text($function['signature']).' is not owned by pinround_resolver';
            }
        }
        foreach ($securityDefinerCounts as $name => $count) {
            if (1 !== $count) {
                $violations[] = \sprintf('%s: %d SECURITY DEFINER functions in public, expected 1', $name, $count);
            }
        }

        return $violations;
    }

    /**
     * @return list<string> rights of pinround_resolver beyond column SELECT grants on tables with a resolver_read
     *                      policy: table-wide privileges, default privileges, and column grants anywhere else
     */
    public function resolverRightsBeyondItsColumnReads(): array
    {
        return self::strings($this->connection->fetchFirstColumn(
            "SELECT c.oid::regclass::text || ': ' || acl.privilege_type || ' on the whole table'
             FROM pg_class c, aclexplode(c.relacl) acl
             WHERE acl.grantee = 'pinround_resolver'::regrole
             UNION ALL
             SELECT 'default privilege of ' || d.defaclrole::regrole::text || ': ' || acl.privilege_type || ' on new objects of type ' || d.defaclobjtype::text
             FROM pg_default_acl d, aclexplode(d.defaclacl) acl
             WHERE acl.grantee = 'pinround_resolver'::regrole
             UNION ALL
             SELECT a.attrelid::regclass::text || ': ' || acl.privilege_type || ' (' || a.attname || ')'
             FROM pg_attribute a, aclexplode(a.attacl) acl
             WHERE acl.grantee = 'pinround_resolver'::regrole
               AND (acl.privilege_type <> 'SELECT'
                    OR NOT EXISTS (SELECT 1 FROM pg_policy p WHERE p.polrelid = a.attrelid AND p.polname = 'resolver_read'))
             ORDER BY 1",
        ));
    }

    /**
     * @return list<string> roles $member is a member of; for pinround_app any would lend it that role's rights or policies
     */
    public function rolesGrantedTo(string $member): array
    {
        return self::strings($this->connection->fetchFirstColumn(
            'SELECT m.roleid::regrole::text FROM pg_auth_members m WHERE m.member = ?::regrole ORDER BY 1',
            [$member],
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function policiesOnTenantTables(Connection $connection): array
    {
        return $connection->fetchAllAssociative(
            self::TENANT_TABLES." SELECT c.oid::regclass::text AS table_name, pol.polname AS name,
                    CASE WHEN pol.polpermissive THEN 'PERMISSIVE' ELSE 'RESTRICTIVE' END AS permissive,
                    pol.polcmd::text AS command,
                    ARRAY(SELECT CASE WHEN r = 0 THEN 'PUBLIC' ELSE r::regrole::text END FROM unnest(pol.polroles) AS r ORDER BY 1)::text AS roles,
                    pg_get_expr(pol.polqual, pol.polrelid) AS using_expression,
                    pg_get_expr(pol.polwithcheck, pol.polrelid) AS check_expression
             FROM tenant_table t JOIN pg_class c ON c.oid = t.oid JOIN pg_policy pol ON pol.polrelid = c.oid
             ORDER BY 1, 2",
        );
    }

    /**
     * @param array<string, mixed> $policy
     *
     * @return array<string, mixed> the policy without its table, so policies of different tables compare equal
     */
    private static function shapeOf(array $policy): array
    {
        unset($policy['table_name']);

        return $policy;
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_map(self::text(...), $values);
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('The catalog returned a non-text name.');
    }
}
