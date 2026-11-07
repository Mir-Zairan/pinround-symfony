<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\Provider\OrmSchemaProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves docker/postgres/roles.sql ran and the migrations ran as pinround_migrator (ADR-0005).
 * It reads the catalog through the normal kernel connection, which must be pinround_app.
 */
final class DatabaseRolesTest extends KernelTestCase
{
    private const array ROLES = ['pinround_app', 'pinround_migrator', 'pinround_retention', 'pinround_readonly'];
    private const array MESSENGER_TABLES = ['messenger_outbox', 'messenger_failed'];

    #[Test]
    public function theKernelConnectsAsTheAppRole(): void
    {
        self::assertSame('pinround_app', $this->connection()->fetchOne('SELECT current_user'));
    }

    #[Test]
    public function exactlyTheFourPinroundRolesExistAndCanLogIn(): void
    {
        $loginRoles = $this->connection()->fetchFirstColumn(
            "SELECT rolname FROM pg_roles WHERE rolname LIKE 'pinround\\_%' AND rolcanlogin",
        );

        self::assertEqualsCanonicalizing(self::ROLES, $loginRoles);
    }

    #[Test]
    public function theResolverIsTheOnlyPinroundRoleWithoutLogin(): void
    {
        self::assertSame(['pinround_resolver'], $this->connection()->fetchFirstColumn(
            "SELECT rolname FROM pg_roles WHERE rolname LIKE 'pinround\\_%' AND NOT rolcanlogin",
        ));
    }

    #[Test]
    public function onlyTheMigratorIsAMemberOfAnyRoleAndItCanSetButNotInheritTheResolver(): void
    {
        self::assertSame(
            ['pinround_migrator in pinround_resolver inherit=false set=true admin=false'],
            $this->connection()->fetchFirstColumn(
                "SELECT m.member::regrole::text || ' in ' || m.roleid::regrole::text
                        || ' inherit=' || m.inherit_option || ' set=' || m.set_option || ' admin=' || m.admin_option
                 FROM pg_auth_members m
                 WHERE m.member::regrole::text LIKE 'pinround\\_%' OR m.roleid = 'pinround_resolver'::regrole",
            ),
        );
    }

    #[Test]
    public function theResolverHasSchemaUsageButNoCreateAndNoConnect(): void
    {
        $connection = $this->connection();

        self::assertTrue($connection->fetchOne("SELECT has_schema_privilege('pinround_resolver', 'public', 'USAGE')"));
        self::assertFalse($connection->fetchOne("SELECT has_schema_privilege('pinround_resolver', 'public', 'CREATE')"));
        self::assertFalse($connection->fetchOne("SELECT has_database_privilege('pinround_resolver', current_database(), 'CONNECT')"));
    }

    #[Test]
    public function onlyTheRetentionRoleBypassesRowLevelSecurity(): void
    {
        $bypassingRoles = $this->connection()->fetchFirstColumn(
            "SELECT rolname FROM pg_roles WHERE rolname LIKE 'pinround\\_%' AND rolbypassrls",
        );

        self::assertSame(['pinround_retention'], $bypassingRoles);
    }

    #[Test]
    public function noPinroundRoleIsSuperuserOrCreatesRolesOrDatabasesOrReplicates(): void
    {
        $privilegedRoles = $this->connection()->fetchFirstColumn(
            "SELECT rolname FROM pg_roles WHERE rolname LIKE 'pinround\\_%'
             AND (rolsuper OR rolcreaterole OR rolcreatedb OR rolreplication)",
        );

        self::assertSame([], $privilegedRoles);
    }

    #[Test]
    public function theAppRoleCannotCreateObjectsButTheMigratorCan(): void
    {
        $connection = $this->connection();

        self::assertFalse($connection->fetchOne("SELECT has_schema_privilege('pinround_app', 'public', 'CREATE')"));
        self::assertFalse($connection->fetchOne("SELECT has_database_privilege('pinround_app', current_database(), 'CREATE')"));
        self::assertFalse($connection->fetchOne("SELECT has_database_privilege('pinround_app', current_database(), 'TEMPORARY')"));
        self::assertTrue($connection->fetchOne("SELECT has_schema_privilege('pinround_migrator', 'public', 'CREATE')"));
    }

    #[Test]
    public function publicGetsNothingAndOnlyThePinroundRolesConnect(): void
    {
        $connection = $this->connection();

        self::assertSame([], $connection->fetchFirstColumn(
            'SELECT acl.privilege_type FROM pg_database d, aclexplode(d.datacl) acl
             WHERE d.datname = current_database() AND acl.grantee = 0',
        ));
        self::assertSame([], $connection->fetchFirstColumn(
            "SELECT acl.privilege_type FROM pg_namespace n, aclexplode(n.nspacl) acl
             WHERE n.nspname = 'public' AND acl.grantee = 0",
        ));
        // The owner (pinround_migrator) is listed too: an explicit ACL spells out the owner's own rights.
        self::assertEqualsCanonicalizing(self::ROLES, $connection->fetchFirstColumn(
            "SELECT acl.grantee::regrole::text FROM pg_database d, aclexplode(d.datacl) acl
             WHERE d.datname = current_database() AND acl.privilege_type = 'CONNECT'",
        ));
    }

    #[Test]
    public function noDefaultPrivilegeGivesTheAppOrReadonlyRoleRights(): void
    {
        self::assertSame([], $this->connection()->fetchFirstColumn(
            "SELECT d.defaclobjtype::text || ' ' || acl.grantee::regrole::text || ' ' || acl.privilege_type
             FROM pg_default_acl d, aclexplode(d.defaclacl) acl
             WHERE acl.grantee IN ('pinround_app'::regrole, 'pinround_readonly'::regrole)",
        ));
    }

    #[Test]
    public function aNewTableHasOnlyTheRightsItsMigrationGrantsAndAPartitionNone(): void
    {
        $migrator = self::getContainer()->get('doctrine.dbal.migrator_connection');
        $migrator->beginTransaction();

        try {
            $migrator->executeStatement('CREATE TABLE roles_probe_plain (id int)');
            $migrator->executeStatement('CREATE TABLE roles_probe_parent (id int, month int) PARTITION BY LIST (month)');
            $migrator->executeStatement('CREATE TABLE roles_probe_child PARTITION OF roles_probe_parent FOR VALUES IN (1)');
            $migrator->executeStatement('GRANT SELECT, INSERT ON roles_probe_parent TO pinround_app');

            self::assertSame([], $this->grantsToOthersThanTheOwner($migrator, 'roles_probe_plain'));
            self::assertEqualsCanonicalizing(
                ['pinround_app INSERT', 'pinround_app SELECT'],
                $this->grantsToOthersThanTheOwner($migrator, 'roles_probe_parent'),
            );
            self::assertSame([], $this->grantsToOthersThanTheOwner($migrator, 'roles_probe_child'));
        } finally {
            $migrator->rollBack();
        }
    }

    /** Vacuous until the first partitioned table; then it guards every migration that adds one. */
    #[Test]
    public function noChildPartitionInTheSchemaHasDirectGrants(): void
    {
        self::assertSame([], $this->connection()->fetchFirstColumn(
            "SELECT c.relname || ' ' || acl.grantee::regrole::text || ' ' || acl.privilege_type
             FROM pg_class c, aclexplode(c.relacl) acl
             WHERE c.relnamespace = 'public'::regnamespace AND c.relispartition AND acl.grantee <> c.relowner",
        ));
    }

    #[Test]
    public function theAppAndReadonlyRolesHoldNoTruncateReferencesOrTriggerInTheSchema(): void
    {
        self::assertSame([], $this->connection()->fetchFirstColumn(
            "SELECT c.relname || ' ' || acl.grantee::regrole::text || ' ' || acl.privilege_type
             FROM pg_class c, aclexplode(c.relacl) acl
             WHERE c.relnamespace = 'public'::regnamespace
               AND acl.grantee IN ('pinround_app'::regrole, 'pinround_readonly'::regrole)
               AND acl.privilege_type IN ('TRUNCATE', 'REFERENCES', 'TRIGGER')",
        ));
    }

    /** The app only reads which migrations ran, for /health/ready (Version20261107180000); roles.sql keeps it that way. */
    #[Test]
    public function theAppRoleMayOnlyReadTheMigrationsTableAndNobodyElseHasRights(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(
            ['pinround_app SELECT'],
            $this->grantsToOthersThanTheOwner($connection, 'doctrine_migration_versions'),
        );
        self::assertSame('pinround_migrator', $connection->fetchOne("SELECT relowner::regrole::text FROM pg_class WHERE oid = to_regclass('public.doctrine_migration_versions')"));
        self::assertGreaterThan(0, $connection->fetchOne('SELECT count(*) FROM doctrine_migration_versions'));
        self::assertFalse($connection->fetchOne("SELECT has_table_privilege('pinround_readonly', 'public.doctrine_migration_versions', 'SELECT')"));
    }

    /** The worker inserts, locks, marks and deletes rows; support reports never read envelopes (Version20261107150000). */
    #[Test]
    public function theAppRoleAloneMayReadAndWriteTheMessengerTables(): void
    {
        $connection = $this->connection();

        foreach (self::MESSENGER_TABLES as $table) {
            self::assertEqualsCanonicalizing(
                ['pinround_app DELETE', 'pinround_app INSERT', 'pinround_app SELECT', 'pinround_app UPDATE'],
                $this->grantsToOthersThanTheOwner($connection, $table),
                $table,
            );
            self::assertSame('pinround_migrator', $connection->fetchOne('SELECT relowner::regrole::text FROM pg_class WHERE oid = to_regclass(?)', ['public.'.$table]), $table);
        }
    }

    /** Append-only for the app, so a recorded message cannot be forgotten and handled again (Version20261107160000). */
    #[Test]
    public function theAppRoleMayOnlyReadAndAddProcessedMessages(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(
            ['pinround_app INSERT', 'pinround_app SELECT'],
            $this->grantsToOthersThanTheOwner($connection, 'processed_message'),
        );
        self::assertSame('pinround_migrator', $connection->fetchOne("SELECT relowner::regrole::text FROM pg_class WHERE oid = to_regclass('public.processed_message')"));
        // The retention purge (P11) deletes by age.
        self::assertSame(
            ['CREATE INDEX processed_message_processed_at_idx ON public.processed_message USING btree (processed_at)'],
            $connection->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'processed_message' AND indexname <> 'processed_message_pkey'"),
        );
    }

    /**
     * Append-only for the app; support reads it under row-level security. Grants on the parent only, so no role can
     * read a partition directly and skip the parent's policy (ADR-0026, Version20261107170000).
     */
    #[Test]
    public function theAuditParentAloneHasGrantsAndTheAppMayOnlyReadAndAdd(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(
            ['pinround_app INSERT', 'pinround_app SELECT', 'pinround_readonly SELECT'],
            $this->grantsToOthersThanTheOwner($connection, 'aud_event'),
        );
        /** @var list<string> $partitions */
        $partitions = $connection->fetchFirstColumn("SELECT inhrelid::regclass::text FROM pg_inherits WHERE inhparent = 'public.aud_event'::regclass");
        self::assertCount(14, $partitions);
        foreach ($partitions as $partition) {
            self::assertSame([], $this->grantsToOthersThanTheOwner($connection, $partition), $partition);
        }
    }

    /** The tables no entity maps; the diff must neither drop them nor hide a table that is mapped. */
    #[Test]
    public function theSchemaFilterHidesOnlyTheTablesNoEntityMapsOnBothConnections(): void
    {
        $hidden = ['processed_message', 'aud_event', 'aud_event_202611', 'aud_event_209912', 'public.aud_event', 'public.processed_message'];
        $kept = ['messenger_outbox', 'messenger_failed', 'aud_export', 'aud_event_note', 'aud_event_2026', 'aud_events', 'processed_message_v2', 'tkt_ticket_event', 'zz_tenant_probe'];

        foreach (['doctrine.dbal.default_connection', 'doctrine.dbal.migrator_connection'] as $connectionId) {
            $connection = self::getContainer()->get($connectionId);
            self::assertInstanceOf(Connection::class, $connection);
            $isKept = $connection->getConfiguration()->getSchemaAssetsFilter();

            self::assertSame([], array_values(array_filter($hidden, static fn (string $table): bool => (bool) $isKept($table))), $connectionId);
            self::assertSame($kept, array_values(array_filter($kept, static fn (string $table): bool => (bool) $isKept($table))), $connectionId);
        }
    }

    /**
     * What doctrine:migrations:diff runs (DiffGenerator: the filtered database against the filtered ORM schema, both
     * ways), so a new hand-written table is either mapped or added to the schema_filter. The test-only zz_ entities have
     * no migration and are left out, and so is the migrations' own table, which the bundle hides only while one of its
     * commands runs.
     */
    #[Test]
    public function doctrineMigrationsDiffFindsNoChanges(): void
    {
        $migrations = self::getContainer()->get('doctrine.migrations.dependency_factory');
        $migratorConnection = $migrations->getConnection();
        $schemaManager = $migratorConnection->createSchemaManager();
        $isKept = $migratorConnection->getConfiguration()->getSchemaAssetsFilter();
        $metadataStorage = $migrations->getConfiguration()->getMetadataStorageConfiguration();
        self::assertInstanceOf(TableMetadataStorageConfiguration::class, $metadataStorage);
        $migrationsTable = $metadataStorage->getTableName();
        $withoutHiddenTables = static function (Schema $schema) use ($isKept, $migrationsTable): Schema {
            foreach ($schema->getTables() as $table) {
                $name = $table->getObjectName()->toString();
                if (!(bool) $isKept($name) || str_starts_with($name, 'zz_') || $migrationsTable === $name) {
                    $schema = $schema->edit()->dropTable($table->getObjectName())->create();
                }
            }

            return $schema;
        };
        $migratedSchema = $withoutHiddenTables($schemaManager->introspectSchema());
        $expectedSchema = $withoutHiddenTables($migrations->getSchemaProvider()->createSchema());
        $comparator = $schemaManager->createComparator(new ComparatorConfig()->withReportModifiedIndexes(false));
        $platform = $migratorConnection->getDatabasePlatform();

        self::assertSame([], $platform->getAlterSchemaSQL($comparator->compareSchemas($migratedSchema, $expectedSchema)));
        self::assertSame([], $platform->getAlterSchemaSQL($comparator->compareSchemas($expectedSchema, $migratedSchema)));
    }

    /** What doctrine:migrations:diff compares, limited to the Messenger tables: the migration built them as Messenger would. */
    #[Test]
    public function theMigratedMessengerTablesMatchTheSchemaMessengerExpects(): void
    {
        $migrations = self::getContainer()->get('doctrine.migrations.dependency_factory');
        $migratorConnection = $migrations->getConnection();
        $schemaManager = $migratorConnection->createSchemaManager();
        $expectedSchema = $migrations->getSchemaProvider()->createSchema();
        $migratedSchema = $schemaManager->introspectSchema();
        $comparator = $schemaManager->createComparator(new ComparatorConfig()->withReportModifiedIndexes(false));

        foreach (self::MESSENGER_TABLES as $table) {
            self::assertTrue($expectedSchema->hasTable($table), $table.' is not in the schema Doctrine expects.');
            $tableDiff = $comparator->compareTables($migratedSchema->getTable($table), $expectedSchema->getTable($table));

            self::assertSame([], $migratorConnection->getDatabasePlatform()->getAlterTableSQL($tableDiff), $table);
        }
    }

    #[Test]
    public function theMigrationsRanAsTheMigrator(): void
    {
        // roles.sql re-owns tables but cannot re-own extensions, so only a migration run as the migrator makes it their owner.
        self::assertEqualsCanonicalizing(
            ['citext', 'pg_trgm', 'pgcrypto'],
            $this->connection()->fetchFirstColumn("SELECT extname FROM pg_extension WHERE extowner = 'pinround_migrator'::regrole"),
            'A test database migrated before the roles existed keeps the old owner: drop pinround_test, then run make db-roles migrate-test.',
        );
    }

    #[Test]
    public function theMigrationsUseTheMigratorConnectionAndTheOrmSchema(): void
    {
        $migrations = self::getContainer()->get('doctrine.migrations.dependency_factory');

        self::assertSame('pinround_migrator', $migrations->getConnection()->fetchOne('SELECT current_user'));
        self::assertInstanceOf(OrmSchemaProvider::class, $migrations->getSchemaProvider());
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    /**
     * @return list<mixed> "grantee PRIVILEGE" pairs on a table in public, the owner's own rights left out
     */
    private function grantsToOthersThanTheOwner(Connection $connection, string $table): array
    {
        return $connection->fetchFirstColumn(
            "SELECT acl.grantee::regrole::text || ' ' || acl.privilege_type
             FROM pg_class c, aclexplode(c.relacl) acl
             WHERE c.oid = to_regclass('public.' || quote_ident(?)) AND acl.grantee <> c.relowner",
            [$table],
        );
    }
}
