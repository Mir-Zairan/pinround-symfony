<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Reads the migrated schema's catalog as pinround_app and fails on any tenancy rule of ADR-0005 it breaks
 * (see TenancyCatalog). Each rule is also proved to fire. For tables, policies, functions and privileges the
 * migrator creates a throwaway object that breaks the rule, inside a transaction that is rolled back, and the
 * rule must name exactly that object. These are real tables, not CREATE TEMPORARY TABLE, which would land in
 * pg_temp where no check looks. The two role rules are proved on roles that exist.
 */
final class CatalogTest extends KernelTestCase
{
    /**
     * A run cut short inside ResolverFunctionTest leaves its probe committed, and every check here reads the
     * real catalog, so each test starts without it whatever ran or broke before.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->dropResolverProbeLeftovers();
    }

    #[Test]
    public function theMigratedSchemaBreaksNoTenancyRule(): void
    {
        $this->assertTheMigratedSchemaBreaksNoTenancyRule();
    }

    #[Test]
    public function resolverProbeLeftoversOfARunCutShortAreDroppedBeforeTheMainCheck(): void
    {
        $this->resolverProbeTable()->recreate();
        self::assertEqualsCanonicalizing(
            [
                'zz_resolve_probe_note(text) is SECURITY DEFINER but not in ResolverFunctions::NAMES',
                'zz_resolve_probe_note(text) is owned by pinround_resolver but not in ResolverFunctions::NAMES',
                'zz_resolve_probe(text) is SECURITY DEFINER but not in ResolverFunctions::NAMES',
                'zz_resolve_probe(text) is owned by pinround_resolver but not in ResolverFunctions::NAMES',
                'zz_resolve_probe_unprefixed(text) is SECURITY DEFINER but not in ResolverFunctions::NAMES',
                'zz_resolve_probe_unprefixed(text) is owned by pinround_resolver but not in ResolverFunctions::NAMES',
            ],
            TenancyCatalog::read($this->connection(), $this->migrator())->securityDefinerFunctionsOutsideTheResolvers(),
        );

        $this->dropResolverProbeLeftovers();

        $this->assertTheMigratedSchemaBreaksNoTenancyRule();
    }

    private function assertTheMigratedSchemaBreaksNoTenancyRule(): void
    {
        $connection = $this->connection();
        self::assertSame('pinround_app', $connection->fetchOne('SELECT current_user'));
        $catalog = TenancyCatalog::read($connection, $this->migrator());

        self::assertSame([], $catalog->tenantTablesWithoutIsolation());
        self::assertSame([], $catalog->foreignPoliciesOnTenantTables());
        self::assertSame([], $catalog->rolesBypassingRowLevelSecurityOtherThan('pinround_retention'));
        self::assertSame([], $catalog->securityDefinerFunctionsOutsideTheResolvers());
        self::assertSame([], $catalog->resolverRightsBeyondItsColumnReads());
        self::assertSame([], $catalog->resolverTextColumnsWithoutAClosedSetOrATenantBoundReader());
        self::assertSame([], $catalog->resolverFunctionsInALanguageOtherThan(['sql', 'plpgsql']));
        self::assertSame([], $catalog->rolesGrantedTo('pinround_app'));
    }

    /** The test roles cannot create roles or grant membership, so the role rules are proved on roles that exist. */
    #[Test]
    public function theRoleRulesReportTheRolesThatReallyBypassOrAreGranted(): void
    {
        $catalog = TenancyCatalog::read($this->connection(), $this->migrator());

        self::assertSame(['pinround_retention'], $catalog->rolesBypassingRowLevelSecurityOtherThan('pinround_migrator'));
        self::assertSame(['pinround_resolver'], $catalog->rolesGrantedTo('pinround_migrator'));
    }

    #[Test]
    public function aTenantTableAndASecurityDefinerFunctionInAnotherSchemaAreReported(): void
    {
        self::assertSame(
            [
                ['zz_other.zz_catalog_no_rls_elsewhere: ROW LEVEL SECURITY off, FORCE ROW LEVEL SECURITY off, no tenant_isolation policy as TenantTable::enableRls writes it'],
                [
                    'zz_other.resolve_api_token(bytea) is SECURITY DEFINER but not in ResolverFunctions::NAMES',
                    'zz_other.zz_catalog_definer_elsewhere() is SECURITY DEFINER but not in ResolverFunctions::NAMES',
                ],
            ],
            $this->inRolledBackFixture(
                [
                    'CREATE SCHEMA zz_other',
                    'CREATE TABLE zz_other.zz_catalog_no_rls_elsewhere (organization_id uuid NOT NULL)',
                    'CREATE FUNCTION zz_other.zz_catalog_definer_elsewhere() RETURNS int LANGUAGE sql SECURITY DEFINER AS $fixture$ SELECT 1 $fixture$',
                    'CREATE FUNCTION zz_other.resolve_api_token(p_token_hash bytea) RETURNS int LANGUAGE sql SECURITY DEFINER AS $fixture$ SELECT 1 $fixture$',
                ],
                static fn (TenancyCatalog $catalog): array => [
                    $catalog->tenantTablesWithoutIsolation(),
                    $catalog->securityDefinerFunctionsOutsideTheResolvers(),
                ],
            ),
        );
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
                    // A session's own temporary objects are not schema objects other sessions could reach.
                    'CREATE TEMPORARY TABLE zz_catalog_session_scratch (organization_id uuid NOT NULL)',
                    'CREATE FUNCTION pg_temp.zz_catalog_session_definer() RETURNS int LANGUAGE sql SECURITY DEFINER AS $fixture$ SELECT 1 $fixture$',
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
    public function aTenantTableOrSecurityDefinerFunctionAddedToAnExtensionIsStillReported(): void
    {
        self::assertSame(
            [
                ['zz_catalog_in_extension: ROW LEVEL SECURITY off, FORCE ROW LEVEL SECURITY off, no tenant_isolation policy as TenantTable::enableRls writes it'],
                ['zz_catalog_definer_in_extension() is SECURITY DEFINER but not in ResolverFunctions::NAMES'],
            ],
            $this->inRolledBackFixture(
                [
                    'CREATE TABLE zz_catalog_in_extension (organization_id uuid NOT NULL)',
                    'ALTER EXTENSION citext ADD TABLE zz_catalog_in_extension',
                    'CREATE FUNCTION zz_catalog_definer_in_extension() RETURNS int LANGUAGE sql SECURITY DEFINER AS $fixture$ SELECT 1 $fixture$',
                    'ALTER EXTENSION citext ADD FUNCTION zz_catalog_definer_in_extension()',
                ],
                static fn (TenancyCatalog $catalog): array => [
                    $catalog->tenantTablesWithoutIsolation(),
                    $catalog->securityDefinerFunctionsOutsideTheResolvers(),
                ],
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

    /**
     * The ticket search is a SECURITY DEFINER function too (data-model.md › Query patterns), so the list holds it and
     * the check misses it when it is gone.
     */
    #[Test]
    public function theTicketSearchIsAListedResolverThatIsReportedWhenMissing(): void
    {
        self::assertSame(
            ['search_ticket_ids: 0 SECURITY DEFINER functions in public, expected 1'],
            $this->inRolledBackFixture(
                ['SET LOCAL ROLE pinround_resolver', 'DROP FUNCTION search_ticket_ids(uuid, text, uuid, uuid[], boolean, boolean, uuid, uuid[], uuid)', 'RESET ROLE'],
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
     * Only a validated CHECK on the column alone to a fixed set of values counts; any type that is not a number, date,
     * time, boolean, enum, uuid or bytea counts as text, arrays of text and domains over it included.
     */
    #[Test]
    public function aResolverTextColumnWithoutAClosedSetAndNoResolverReadingItsTableIsReported(): void
    {
        self::assertSame(
            [
                'zz_catalog_text_columns.free_note (text): no CHECK to a fixed set of values, and no resolver function reads zz_catalog_text_columns',
                'zz_catalog_text_columns.labels (text[]): no CHECK to a fixed set of values, and no resolver function reads zz_catalog_text_columns',
                'zz_catalog_text_columns.loose_kind (text): no CHECK to a fixed set of values, and no resolver function reads zz_catalog_text_columns',
                'zz_catalog_text_columns.name_domain (zz_catalog_name): no CHECK to a fixed set of values, and no resolver function reads zz_catalog_text_columns',
                'zz_catalog_text_columns.payload (jsonb): no CHECK to a fixed set of values, and no resolver function reads zz_catalog_text_columns',
                'zz_catalog_text_columns.short_note (character varying(20)): no CHECK to a fixed set of values, and no resolver function reads zz_catalog_text_columns',
                'zz_catalog_text_columns.unchecked_kind (text): no CHECK to a fixed set of values, and no resolver function reads zz_catalog_text_columns',
                'zz_catalog_text_columns.words (tsvector): no CHECK to a fixed set of values, and no resolver function reads zz_catalog_text_columns',
            ],
            $this->inRolledBackFixture(
                [
                    'CREATE DOMAIN zz_catalog_name AS text',
                    'CREATE TABLE zz_catalog_text_columns (organization_id uuid NOT NULL, created_at timestamptz, rank int, is_open boolean,'
                    .' token_hash bytea, member_ids uuid[], free_note text, short_note varchar(20), payload jsonb, words tsvector,'
                    .' labels text[], name_domain zz_catalog_name, kind text, loose_kind text, unchecked_kind text,'
                    ." CONSTRAINT zz_catalog_kind_check CHECK (kind IN ('ping', 'your_turn')),"
                    ." CONSTRAINT zz_catalog_loose_kind_check CHECK (loose_kind <> ''))",
                    "ALTER TABLE zz_catalog_text_columns ADD CONSTRAINT zz_catalog_unchecked_kind_check CHECK (unchecked_kind IN ('ping')) NOT VALID",
                    ...TenantTable::enableRls('zz_catalog_text_columns'),
                    ...TenantTable::allowResolverRead('zz_catalog_text_columns', [
                        'organization_id', 'created_at', 'rank', 'is_open', 'token_hash', 'member_ids', 'free_note', 'short_note',
                        'payload', 'words', 'labels', 'name_domain', 'kind', 'loose_kind', 'unchecked_kind',
                    ]),
                ],
                static fn (TenancyCatalog $catalog): array => $catalog->resolverTextColumnsWithoutAClosedSetOrATenantBoundReader(),
            ),
        );
    }

    /**
     * A text column with no closed set passes only while every resolver function that names its table keeps to the
     * tenant of the transaction, as search_ticket_ids does for tkt_ticket.title.
     */
    #[Test]
    public function aResolverTextColumnPassesOnlyWhileEveryResolverReadingItsTableIsTenantBound(): void
    {
        self::assertSame(
            [
                [],
                ['zz_catalog_matched.title (text): no CHECK to a fixed set of values, and zz_catalog_unbound_reader read zz_catalog_matched without keeping to the tenant of the transaction'],
            ],
            [
                $this->inRolledBackFixture(
                    self::matchedTableWithATenantBoundReader(),
                    static fn (TenancyCatalog $catalog): array => $catalog->resolverTextColumnsWithoutAClosedSetOrATenantBoundReader(),
                ),
                $this->inRolledBackFixture(
                    [
                        ...self::matchedTableWithATenantBoundReader(),
                        'CREATE FUNCTION zz_catalog_unbound_reader(p_query text) RETURNS SETOF uuid LANGUAGE sql'
                        .' AS $fixture$ SELECT organization_id FROM zz_catalog_matched WHERE title = p_query $fixture$',
                        ...self::ownedByTheResolver('zz_catalog_unbound_reader(text)'),
                    ],
                    static fn (TenancyCatalog $catalog): array => $catalog->resolverTextColumnsWithoutAClosedSetOrATenantBoundReader(),
                ),
            ],
        );
    }

    /**
     * A SQL function written with BEGIN ATOMIC keeps its body in prosqlbody and leaves prosrc empty, so a search of
     * prosrc alone never sees what it reads.
     */
    #[Test]
    public function aBeginAtomicResolverThatReadsATextColumnWithoutTheTenantIsReported(): void
    {
        self::assertSame(
            ['zz_catalog_matched.title (text): no CHECK to a fixed set of values, and zz_catalog_atomic_reader read zz_catalog_matched without keeping to the tenant of the transaction'],
            $this->inRolledBackFixture(
                [
                    ...self::matchedTableWithATenantBoundReader(),
                    'CREATE FUNCTION zz_catalog_atomic_reader(p_organization_id uuid, p_query text) RETURNS SETOF uuid LANGUAGE sql'
                    .' BEGIN ATOMIC SELECT organization_id FROM zz_catalog_matched WHERE title = p_query; END',
                    ...self::ownedByTheResolver('zz_catalog_atomic_reader(uuid, text)'),
                ],
                static fn (TenancyCatalog $catalog): array => $catalog->resolverTextColumnsWithoutAClosedSetOrATenantBoundReader(),
            ),
        );
    }

    /**
     * The binding counts only outside comments, nested block comments included, and only in a function that takes
     * p_organization_id as an input: a local variable or an output column of that name binds no row to the tenant. An
     * unbound overload makes its name unbound.
     */
    #[Test]
    public function aTenantBindingInACommentOrWithoutAnOrganizationInputDoesNotCount(): void
    {
        $binding = "p_organization_id = NULLIF(current_setting('app.org_id', true), '')::uuid";

        self::assertSame(
            [
                'zz_catalog_matched.title (text): no CHECK to a fixed set of values, and zz_catalog_block_comment_reader,'
                .' zz_catalog_bound_reader, zz_catalog_line_comment_reader, zz_catalog_local_binding_reader, zz_catalog_output_binding_reader'
                .' read zz_catalog_matched without keeping to the tenant of the transaction',
            ],
            $this->inRolledBackFixture(
                [
                    ...self::matchedTableWithATenantBoundReader(),
                    'CREATE FUNCTION zz_catalog_line_comment_reader(p_organization_id uuid, p_query text) RETURNS SETOF uuid LANGUAGE sql'
                    ." AS \$fixture\$ SELECT organization_id FROM zz_catalog_matched WHERE title = p_query -- AND {$binding}\n\$fixture\$",
                    'CREATE FUNCTION zz_catalog_block_comment_reader(p_organization_id uuid, p_query text) RETURNS SETOF uuid LANGUAGE sql'
                    ." AS \$fixture\$ SELECT organization_id FROM zz_catalog_matched WHERE title = p_query /* outer /* inner */ AND {$binding} */ \$fixture\$",
                    'CREATE FUNCTION zz_catalog_local_binding_reader(p_query text) RETURNS SETOF uuid LANGUAGE plpgsql'
                    ." AS \$fixture\$ DECLARE p_organization_id uuid := NULLIF(current_setting('app.org_id', true), '')::uuid;"
                    ." BEGIN RETURN QUERY SELECT m.organization_id FROM zz_catalog_matched m WHERE {$binding} AND m.title = p_query; END \$fixture\$",
                    'CREATE FUNCTION zz_catalog_output_binding_reader(p_query text) RETURNS TABLE (p_organization_id uuid) LANGUAGE plpgsql'
                    ." AS \$fixture\$ BEGIN RETURN QUERY SELECT m.organization_id FROM zz_catalog_matched m WHERE {$binding} AND m.title = p_query; END \$fixture\$",
                    'CREATE FUNCTION zz_catalog_bound_reader(p_query text) RETURNS SETOF uuid LANGUAGE sql'
                    .' AS $fixture$ SELECT organization_id FROM zz_catalog_matched WHERE title = p_query $fixture$',
                    ...self::ownedByTheResolver(
                        'zz_catalog_bound_reader(text)',
                        'zz_catalog_line_comment_reader(uuid, text)',
                        'zz_catalog_block_comment_reader(uuid, text)',
                        'zz_catalog_local_binding_reader(text)',
                        'zz_catalog_output_binding_reader(text)',
                    ),
                ],
                static fn (TenancyCatalog $catalog): array => $catalog->resolverTextColumnsWithoutAClosedSetOrATenantBoundReader(),
            ),
        );
    }

    /**
     * A resolver function runs what it calls with pinround_resolver's rights, so a function the resolver may run reads
     * as the resolver too; one it may not run reads nothing for it.
     */
    #[Test]
    public function aFunctionTheResolverMayRunCountsAsAReaderOfTheTablesItNames(): void
    {
        $helper = static fn (string $name): string => \sprintf(
            'CREATE FUNCTION %s(p_query text) RETURNS SETOF uuid LANGUAGE sql'
            .' AS $fixture$ SELECT organization_id FROM zz_catalog_matched WHERE title = p_query $fixture$',
            $name,
        );

        self::assertSame(
            ['zz_catalog_matched.title (text): no CHECK to a fixed set of values, and zz_catalog_runnable_helper read zz_catalog_matched without keeping to the tenant of the transaction'],
            $this->inRolledBackFixture(
                [
                    ...self::matchedTableWithATenantBoundReader(),
                    $helper('zz_catalog_runnable_helper'),
                    $helper('zz_catalog_unrunnable_helper'),
                    'REVOKE EXECUTE ON FUNCTION zz_catalog_unrunnable_helper(text) FROM PUBLIC',
                ],
                static fn (TenancyCatalog $catalog): array => $catalog->resolverTextColumnsWithoutAClosedSetOrATenantBoundReader(),
            ),
        );
    }

    /**
     * Whatever lets pinround_resolver read a column of a resolver_read table counts, a grant to PUBLIC on the column or
     * the whole table included; PUBLIC on a table without resolver_read leaves the resolver to the tenant_isolation policy.
     */
    #[Test]
    public function aTextColumnThatPublicMayReadOnAResolverReadTableIsReported(): void
    {
        self::assertSame(
            [
                'zz_catalog_public_column.title (text): no CHECK to a fixed set of values, and no resolver function reads zz_catalog_public_column',
                'zz_catalog_public_table.note (text): no CHECK to a fixed set of values, and no resolver function reads zz_catalog_public_table',
            ],
            $this->inRolledBackFixture(
                [
                    'CREATE TABLE zz_catalog_public_column (organization_id uuid NOT NULL, title text, note text)',
                    ...TenantTable::enableRls('zz_catalog_public_column'),
                    ...TenantTable::allowResolverRead('zz_catalog_public_column', ['organization_id']),
                    'GRANT SELECT (title) ON zz_catalog_public_column TO PUBLIC',
                    'CREATE TABLE zz_catalog_public_table (organization_id uuid NOT NULL, note text)',
                    ...TenantTable::enableRls('zz_catalog_public_table'),
                    ...TenantTable::allowResolverRead('zz_catalog_public_table', ['organization_id']),
                    'GRANT SELECT ON zz_catalog_public_table TO PUBLIC',
                    'CREATE TABLE zz_catalog_public_tenant_only (organization_id uuid NOT NULL, note text)',
                    ...TenantTable::enableRls('zz_catalog_public_tenant_only'),
                    'GRANT SELECT ON zz_catalog_public_tenant_only TO PUBLIC',
                ],
                static fn (TenancyCatalog $catalog): array => $catalog->resolverTextColumnsWithoutAClosedSetOrATenantBoundReader(),
            ),
        );
    }

    /**
     * The migrator can create functions in no language but sql and plpgsql, so the rule is proved with plpgsql left out
     * of the readable languages, on what the fixture adds to the functions that exist. The C functions an extension
     * brings are left out: only a superuser or a trusted extension's script can create them.
     */
    #[Test]
    public function aFunctionTheResolverOwnsOrMayRunInAnotherLanguageIsReported(): void
    {
        $plpgsqlFunction = static fn (string $name): string => \sprintf(
            'CREATE FUNCTION %s() RETURNS int LANGUAGE plpgsql AS $fixture$ BEGIN RETURN 1; END $fixture$',
            $name,
        );
        $languageRule = static fn (TenancyCatalog $catalog): array => $catalog->resolverFunctionsInALanguageOtherThan(['sql']);

        $existing = $this->inRolledBackFixture([], $languageRule);
        $withFixture = $this->inRolledBackFixture(
            [
                $plpgsqlFunction('zz_catalog_owned_plpgsql'),
                'REVOKE EXECUTE ON FUNCTION zz_catalog_owned_plpgsql() FROM PUBLIC',
                ...self::ownedByTheResolver('zz_catalog_owned_plpgsql()'),
                $plpgsqlFunction('zz_catalog_runnable_plpgsql'),
                $plpgsqlFunction('zz_catalog_unrunnable_plpgsql'),
                'REVOKE EXECUTE ON FUNCTION zz_catalog_unrunnable_plpgsql() FROM PUBLIC',
                'CREATE EXTENSION fuzzystrmatch',
            ],
            $languageRule,
        );

        self::assertSame(
            ['zz_catalog_owned_plpgsql() is in language plpgsql', 'zz_catalog_runnable_plpgsql() is in language plpgsql'],
            array_values(array_diff($withFixture, $existing)),
        );
    }

    /**
     * digest_recipients reads ntf_notification.kind in every organization (Version20261115150000) and keeps to no
     * tenant, so only ntf_notification_kind_check (Version20261115163000) lets that grant pass.
     */
    #[Test]
    public function theNotificationKindGrantFailsWithoutItsCheck(): void
    {
        self::assertSame(
            ['ntf_notification.kind (text): no CHECK to a fixed set of values, and digest_recipients, organizations_with_due_notifications read ntf_notification without keeping to the tenant of the transaction'],
            $this->inRolledBackFixture(
                ['ALTER TABLE ntf_notification DROP CONSTRAINT ntf_notification_kind_check'],
                static fn (TenancyCatalog $catalog): array => $catalog->resolverTextColumnsWithoutAClosedSetOrATenantBoundReader(),
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

    /**
     * @return list<string> zz_catalog_matched with title granted to pinround_resolver, read by a tenant-bound resolver function
     */
    private static function matchedTableWithATenantBoundReader(): array
    {
        return [
            'CREATE TABLE zz_catalog_matched (organization_id uuid NOT NULL, title text)',
            ...TenantTable::enableRls('zz_catalog_matched'),
            ...TenantTable::allowResolverRead('zz_catalog_matched', ['organization_id', 'title']),
            'CREATE FUNCTION zz_catalog_bound_reader(p_organization_id uuid, p_query text) RETURNS SETOF uuid LANGUAGE sql'
            .' AS $fixture$ SELECT organization_id FROM zz_catalog_matched WHERE organization_id = p_organization_id'
            ." AND p_organization_id = NULLIF(current_setting('app.org_id', true), '')::uuid AND title = p_query \$fixture\$",
            ...self::ownedByTheResolver('zz_catalog_bound_reader(uuid, text)'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function ownedByTheResolver(string ...$signatures): array
    {
        return [
            'GRANT CREATE ON SCHEMA public TO pinround_resolver',
            ...array_map(static fn (string $signature): string => \sprintf('ALTER FUNCTION %s OWNER TO pinround_resolver', $signature), array_values($signatures)),
            'REVOKE CREATE ON SCHEMA public FROM pinround_resolver',
        ];
    }

    private function dropResolverProbeLeftovers(): void
    {
        $this->resolverProbeTable()->drop();
    }

    private function resolverProbeTable(): ResolverProbeTable
    {
        return new ResolverProbeTable($this->migrator());
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
