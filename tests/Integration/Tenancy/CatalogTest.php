<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Reads the migrated schema's catalog as pinround_app and fails on any tenancy rule of ADR-0005 it breaks
 * (see TenancyCatalog). Each rule is also proved to fire: the migrator creates a throwaway object that
 * breaks it, inside a transaction that is rolled back, and the rule must name exactly that object. These are
 * real tables in public, not CREATE TEMPORARY TABLE, which would land in pg_temp where no check looks.
 */
final class CatalogTest extends KernelTestCase
{
    #[Test]
    public function theMigratedSchemaBreaksNoTenancyRule(): void
    {
        $connection = $this->connection();
        self::assertSame('pinround_app', $connection->fetchOne('SELECT current_user'));
        $catalog = TenancyCatalog::read($connection, $this->migrator());

        self::assertSame([], $catalog->tenantTablesWithoutIsolation());
        self::assertSame([], $catalog->foreignPoliciesOnTenantTables());
        self::assertSame([], $catalog->rolesBypassingRowLevelSecurity());
        self::assertSame([], $catalog->securityDefinerFunctionsOutsideTheResolvers());
        self::assertSame([], $catalog->resolverRightsBeyondItsColumnReads());
        self::assertSame([], $catalog->rolesTheAppRoleIsMemberOf());
    }

    #[Test]
    public function aTenantTableBuiltWithTenantTableBreaksNoRuleEvenAsAPartitionedParentAndChild(): void
    {
        self::assertSame(
            [[], [], [], []],
            $this->inRolledBackFixture(
                [
                    'CREATE TABLE zz_catalog_allowed (id uuid, organization_id uuid NOT NULL)',
                    ...TenantTable::enableRls('zz_catalog_allowed'),
                    ...TenantTable::allowResolverRead('zz_catalog_allowed', ['id', 'organization_id']),
                    'CREATE TABLE zz_catalog_allowed_parent (organization_id uuid NOT NULL, month int) PARTITION BY LIST (month)',
                    ...TenantTable::enableRls('zz_catalog_allowed_parent'),
                    ...TenantTable::allowResolverRead('zz_catalog_allowed_parent', ['organization_id']),
                    'CREATE TABLE zz_catalog_allowed_child PARTITION OF zz_catalog_allowed_parent FOR VALUES IN (1)',
                    ...TenantTable::enableRls('zz_catalog_allowed_child'),
                ],
                static fn (TenancyCatalog $catalog): array => [
                    $catalog->tenantTablesWithoutIsolation(),
                    $catalog->foreignPoliciesOnTenantTables(),
                    $catalog->securityDefinerFunctionsOutsideTheResolvers(),
                    $catalog->resolverRightsBeyondItsColumnReads(),
                ],
            ),
        );
    }

    #[Test]
    public function aNewTenantTableWithoutRowLevelSecurityIsReported(): void
    {
        self::assertSame(
            ['zz_catalog_no_rls: ROW LEVEL SECURITY off, FORCE ROW LEVEL SECURITY off, no tenant_isolation policy as TenantTable::enableRls writes it'],
            $this->inRolledBackFixture(
                ['CREATE TABLE zz_catalog_no_rls (id uuid, organization_id uuid NOT NULL)'],
                static fn (TenancyCatalog $catalog): array => $catalog->tenantTablesWithoutIsolation(),
            ),
        );
    }

    #[Test]
    public function aChildPartitionWithoutRowLevelSecurityIsReportedWhileItsParentHasItInAnySchema(): void
    {
        self::assertSame(
            [
                'zz_catalog_child: ROW LEVEL SECURITY off, FORCE ROW LEVEL SECURITY off, no tenant_isolation policy as TenantTable::enableRls writes it',
                'zz_catalog_elsewhere.zz_catalog_child_elsewhere: ROW LEVEL SECURITY off, FORCE ROW LEVEL SECURITY off, no tenant_isolation policy as TenantTable::enableRls writes it',
            ],
            $this->inRolledBackFixture(
                [
                    'CREATE TABLE zz_catalog_parent (organization_id uuid NOT NULL, month int) PARTITION BY LIST (month)',
                    ...TenantTable::enableRls('zz_catalog_parent'),
                    'CREATE TABLE zz_catalog_child PARTITION OF zz_catalog_parent FOR VALUES IN (1)',
                    'CREATE SCHEMA zz_catalog_elsewhere',
                    'CREATE TABLE zz_catalog_elsewhere.zz_catalog_child_elsewhere PARTITION OF zz_catalog_parent FOR VALUES IN (2)',
                ],
                static fn (TenancyCatalog $catalog): array => $catalog->tenantTablesWithoutIsolation(),
            ),
        );
    }

    #[Test]
    public function aTableWithForceButNoEnableOrADifferentTenantPolicyIsReported(): void
    {
        self::assertSame(
            [
                'zz_catalog_loose_policy: no tenant_isolation policy as TenantTable::enableRls writes it',
                'zz_catalog_no_enable: ROW LEVEL SECURITY off',
            ],
            $this->inRolledBackFixture(
                [
                    'CREATE TABLE zz_catalog_no_enable (organization_id uuid NOT NULL)',
                    ...TenantTable::enableRls('zz_catalog_no_enable'),
                    'ALTER TABLE zz_catalog_no_enable DISABLE ROW LEVEL SECURITY',
                    'CREATE TABLE zz_catalog_loose_policy (organization_id uuid NOT NULL)',
                    'ALTER TABLE zz_catalog_loose_policy ENABLE ROW LEVEL SECURITY',
                    'ALTER TABLE zz_catalog_loose_policy FORCE ROW LEVEL SECURITY',
                    'CREATE POLICY tenant_isolation ON zz_catalog_loose_policy FOR ALL USING (true)',
                ],
                static fn (TenancyCatalog $catalog): array => $catalog->tenantTablesWithoutIsolation(),
            ),
        );
    }

    #[Test]
    public function anExtraPermissivePolicyOrAWidenedResolverReadIsReported(): void
    {
        self::assertSame(
            ['zz_catalog_extra_policy: open_to_app', 'zz_catalog_extra_policy: resolver_read'],
            $this->inRolledBackFixture(
                [
                    'CREATE TABLE zz_catalog_extra_policy (organization_id uuid NOT NULL)',
                    ...TenantTable::enableRls('zz_catalog_extra_policy'),
                    'CREATE POLICY open_to_app ON zz_catalog_extra_policy FOR SELECT TO pinround_app USING (true)',
                    'CREATE POLICY resolver_read ON zz_catalog_extra_policy FOR SELECT TO pinround_resolver, pinround_app USING (true)',
                ],
                static fn (TenancyCatalog $catalog): array => $catalog->foreignPoliciesOnTenantTables(),
            ),
        );
    }

    #[Test]
    public function aSecurityDefinerFunctionNotInTheResolverListIsReported(): void
    {
        self::assertSame(
            ['zz_catalog_definer() is SECURITY DEFINER but not in ResolverFunctions::NAMES'],
            $this->inRolledBackFixture(
                ['CREATE FUNCTION zz_catalog_definer() RETURNS int LANGUAGE sql SECURITY DEFINER AS $fixture$ SELECT 1 $fixture$'],
                static fn (TenancyCatalog $catalog): array => $catalog->securityDefinerFunctionsOutsideTheResolvers(),
            ),
        );
    }

    #[Test]
    public function aSecondResolverNotOwnedByTheResolverRoleAndAnotherFunctionTheRoleOwnsAreReported(): void
    {
        self::assertSame(
            [
                'resolve_api_token(integer) is not owned by pinround_resolver',
                'zz_catalog_resolver_owned() is owned by pinround_resolver but not in ResolverFunctions::NAMES',
                'resolve_api_token: 2 SECURITY DEFINER functions in public, expected 1',
            ],
            $this->inRolledBackFixture(
                [
                    'CREATE FUNCTION resolve_api_token(p_number int) RETURNS int LANGUAGE sql SECURITY DEFINER AS $fixture$ SELECT p_number $fixture$',
                    'CREATE FUNCTION zz_catalog_resolver_owned() RETURNS int LANGUAGE sql AS $fixture$ SELECT 1 $fixture$',
                    'GRANT CREATE ON SCHEMA public TO pinround_resolver',
                    'ALTER FUNCTION zz_catalog_resolver_owned() OWNER TO pinround_resolver',
                ],
                static fn (TenancyCatalog $catalog): array => $catalog->securityDefinerFunctionsOutsideTheResolvers(),
            ),
        );
    }

    #[Test]
    public function aListedResolverThatIsMissingIsReported(): void
    {
        self::assertSame(
            ['resolve_laptop_package: 0 SECURITY DEFINER functions in public, expected 1'],
            $this->inRolledBackFixture(
                ['SET LOCAL ROLE pinround_resolver', 'DROP FUNCTION resolve_laptop_package(uuid)', 'RESET ROLE'],
                static fn (TenancyCatalog $catalog): array => $catalog->securityDefinerFunctionsOutsideTheResolvers(),
            ),
        );
    }

    #[Test]
    public function resolverRightsBeyondColumnReadsOnResolverReadTablesAreReported(): void
    {
        self::assertSame(
            [
                'default privilege of pinround_migrator: SELECT on new objects of type r',
                'zz_catalog_column_grant: SELECT (organization_id)',
                'zz_catalog_resolver_table: UPDATE (organization_id)',
                'zz_catalog_whole_table: SELECT on the whole table',
            ],
            $this->inRolledBackFixture(
                [
                    'CREATE TABLE zz_catalog_whole_table (organization_id uuid NOT NULL)',
                    ...TenantTable::enableRls('zz_catalog_whole_table'),
                    'GRANT SELECT ON zz_catalog_whole_table TO pinround_resolver',
                    'CREATE TABLE zz_catalog_column_grant (organization_id uuid NOT NULL)',
                    ...TenantTable::enableRls('zz_catalog_column_grant'),
                    'GRANT SELECT (organization_id) ON zz_catalog_column_grant TO pinround_resolver',
                    'CREATE TABLE zz_catalog_resolver_table (organization_id uuid NOT NULL)',
                    ...TenantTable::enableRls('zz_catalog_resolver_table'),
                    ...TenantTable::allowResolverRead('zz_catalog_resolver_table', ['organization_id']),
                    'GRANT UPDATE (organization_id) ON zz_catalog_resolver_table TO pinround_resolver',
                    'ALTER DEFAULT PRIVILEGES FOR ROLE pinround_migrator IN SCHEMA public GRANT SELECT ON TABLES TO pinround_resolver',
                ],
                static fn (TenancyCatalog $catalog): array => $catalog->resolverRightsBeyondItsColumnReads(),
            ),
        );
    }

    /**
     * Runs $statements as the migrator in a transaction, reads the catalog on that connection (where the
     * uncommitted objects are visible), then rolls back so nothing remains.
     *
     * @template T
     *
     * @param list<string>                $statements
     * @param \Closure(TenancyCatalog): T $check
     *
     * @return T
     */
    private function inRolledBackFixture(array $statements, \Closure $check): mixed
    {
        $migrator = $this->migrator();
        $catalog = TenancyCatalog::read($migrator, $migrator);
        $migrator->beginTransaction();
        try {
            foreach ($statements as $sql) {
                $migrator->executeStatement($sql);
            }

            return $check($catalog);
        } finally {
            $migrator->rollBack();
        }
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }
}
