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
        $tenantBoundReader = 'CREATE FUNCTION zz_catalog_bound_reader(p_organization_id uuid, p_query text) RETURNS SETOF uuid LANGUAGE sql'
            .' AS $fixture$ SELECT organization_id FROM zz_catalog_matched WHERE organization_id = p_organization_id'
            ." AND p_organization_id = NULLIF(current_setting('app.org_id', true), '')::uuid AND title = p_query \$fixture\$";
        $ownedByTheResolver = static fn (string $signature): array => [
            'GRANT CREATE ON SCHEMA public TO pinround_resolver',
            \sprintf('ALTER FUNCTION %s OWNER TO pinround_resolver', $signature),
            'REVOKE CREATE ON SCHEMA public FROM pinround_resolver',
        ];
        $matchedTable = [
            'CREATE TABLE zz_catalog_matched (organization_id uuid NOT NULL, title text)',
            ...TenantTable::enableRls('zz_catalog_matched'),
            ...TenantTable::allowResolverRead('zz_catalog_matched', ['organization_id', 'title']),
            $tenantBoundReader,
            ...$ownedByTheResolver('zz_catalog_bound_reader(uuid, text)'),
        ];

        self::assertSame(
            [
                [],
                ['zz_catalog_matched.title (text): no CHECK to a fixed set of values, and zz_catalog_unbound_reader read zz_catalog_matched without keeping to the tenant of the transaction'],
            ],
            [
                $this->inRolledBackFixture(
                    $matchedTable,
                    static fn (TenancyCatalog $catalog): array => $catalog->resolverTextColumnsWithoutAClosedSetOrATenantBoundReader(),
                ),
                $this->inRolledBackFixture(
                    [
                        ...$matchedTable,
                        'CREATE FUNCTION zz_catalog_unbound_reader(p_query text) RETURNS SETOF uuid LANGUAGE sql'
                        .' AS $fixture$ SELECT organization_id FROM zz_catalog_matched WHERE title = p_query $fixture$',
                        ...$ownedByTheResolver('zz_catalog_unbound_reader(text)'),
                    ],
                    static fn (TenancyCatalog $catalog): array => $catalog->resolverTextColumnsWithoutAClosedSetOrATenantBoundReader(),
                ),
            ],
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
