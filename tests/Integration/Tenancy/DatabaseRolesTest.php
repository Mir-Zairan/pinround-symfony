<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use Doctrine\DBAL\Connection;
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
    public function theAppRoleOwnsNothingInSchemaPublic(): void
    {
        $ownedByApp = $this->connection()->fetchFirstColumn(
            "SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public' AND c.relowner = 'pinround_app'::regrole",
        );

        self::assertSame([], $ownedByApp);
    }

    #[Test]
    public function newTablesGiveTheAppRowRightsWithoutTruncateAndTheReadonlyRoleSelect(): void
    {
        self::assertEqualsCanonicalizing(
            [
                'pinround_app SELECT', 'pinround_app INSERT', 'pinround_app UPDATE', 'pinround_app DELETE',
                'pinround_readonly SELECT',
            ],
            $this->defaultPrivilegesOfTheMigrator('r'),
        );
        self::assertEqualsCanonicalizing(
            ['pinround_app USAGE', 'pinround_app SELECT'],
            $this->defaultPrivilegesOfTheMigrator('S'),
        );
    }

    #[Test]
    public function theAppRoleCannotTouchTheMigrationsTable(): void
    {
        $connection = $this->connection();

        self::assertFalse($connection->fetchOne("SELECT has_table_privilege('pinround_app', 'public.doctrine_migration_versions', 'INSERT')"));
        self::assertFalse($connection->fetchOne(
            "SELECT has_table_privilege('pinround_app', 'public.doctrine_migration_versions', 'SELECT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER')",
        ));
    }

    #[Test]
    public function theReadonlyRoleOnlyReads(): void
    {
        $connection = $this->connection();
        $table = "'public.doctrine_migration_versions'";

        self::assertTrue($connection->fetchOne("SELECT has_table_privilege('pinround_readonly', {$table}, 'SELECT')"));
        self::assertFalse($connection->fetchOne("SELECT has_table_privilege('pinround_readonly', {$table}, 'INSERT, UPDATE, DELETE, TRUNCATE')"));
    }

    #[Test]
    public function theRetentionRoleHasNoTableRightsYet(): void
    {
        self::assertFalse($this->connection()->fetchOne(
            "SELECT has_table_privilege('pinround_retention', 'public.doctrine_migration_versions', 'SELECT, INSERT, UPDATE, DELETE, TRUNCATE')",
        ));
    }

    #[Test]
    public function theMigrationsRanAsTheMigrator(): void
    {
        self::assertSame('pinround_migrator', $this->connection()->fetchOne(
            "SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'doctrine_migration_versions'",
        ));
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
     * @param 'r'|'S' $objectType pg_default_acl's type: tables or sequences
     *
     * @return list<mixed> "grantee PRIVILEGE" pairs for objects pinround_migrator creates in public
     */
    private function defaultPrivilegesOfTheMigrator(string $objectType): array
    {
        return $this->connection()->fetchFirstColumn(
            "SELECT acl.grantee::regrole::text || ' ' || acl.privilege_type
             FROM pg_default_acl d, aclexplode(d.defaclacl) acl
             WHERE d.defaclrole = 'pinround_migrator'::regrole
               AND d.defaclnamespace = 'public'::regnamespace
               AND d.defaclobjtype = ?",
            [$objectType],
        );
    }
}
