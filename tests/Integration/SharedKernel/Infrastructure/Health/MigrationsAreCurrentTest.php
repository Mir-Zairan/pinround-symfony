<?php

declare(strict_types=1);

namespace App\Tests\Integration\SharedKernel\Infrastructure\Health;

use App\SharedKernel\Infrastructure\Health\MigrationsAreCurrent;
use App\SharedKernel\Infrastructure\Health\ReadinessCheckFailed;
use App\Tests\Integration\SharedKernel\Infrastructure\Health\UnexecutedMigration\Version29991231000000;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\MetadataStorageConfiguration;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * On the migrated test database. The test env reads the migrations table as pinround_migrator (the connection
 * doctrine:migrations:migrate uses); prod's app container reads it as pinround_app (Version20261107180000).
 */
final class MigrationsAreCurrentTest extends KernelTestCase
{
    #[Test]
    public function theConfiguredCheckPassesOnTheMigratedTestDatabase(): void
    {
        $migrationsAreCurrent = self::getContainer()->get(MigrationsAreCurrent::class);

        $migrationsAreCurrent->verify();

        self::assertSame('migrations', $migrationsAreCurrent->name());
    }

    #[Test]
    public function theCheckPassesReadingAsTheAppRoleAsProdDoes(): void
    {
        $appConnection = $this->appConnection();
        self::assertSame('pinround_app', $appConnection->fetchOne('SELECT current_user'));

        new MigrationsAreCurrent($this->migrationsOn($appConnection))->verify();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function aMigrationTheCodeShipsButTheDatabaseNeverRanFailsTheCheck(): void
    {
        $migrations = $this->migrationsOn(
            $this->migratorConnection(),
            [new \ReflectionClass(Version29991231000000::class)->getNamespaceName() => __DIR__.'/UnexecutedMigration'],
        );

        $this->expectException(ReadinessCheckFailed::class);
        $this->expectExceptionMessageIsOrContains('1 migrations are not executed.');

        new MigrationsAreCurrent($migrations)->verify();
    }

    #[Test]
    public function aDatabaseWithoutTheMigrationsTableFailsTheCheck(): void
    {
        $configuration = self::configuration();
        $missingTable = new TableMetadataStorageConfiguration();
        $missingTable->setTableName('zz_no_such_migration_versions');
        $configuration->setMetadataStorageConfiguration($missingTable);

        $this->expectException(TableNotFoundException::class);

        new MigrationsAreCurrent(DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($this->appConnection())))->verify();
    }

    #[Test]
    public function migrationsKeptAnywhereButInATableFailTheCheck(): void
    {
        $configuration = self::configuration();
        $configuration->setMetadataStorageConfiguration(new class implements MetadataStorageConfiguration {});

        $this->expectException(ReadinessCheckFailed::class);

        new MigrationsAreCurrent(DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($this->appConnection())))->verify();
    }

    #[Test]
    public function oneProbeSendsOnePlainSelectOfTheMigrationsTable(): void
    {
        /** @var \ArrayObject<int, string> $sentSql */
        $sentSql = new \ArrayObject();
        $recordsSql = new class(new PgSQLDriver(), $sentSql) extends AbstractDriverMiddleware {
            /**
             * @param \ArrayObject<int, string> $sentSql
             */
            public function __construct(Driver $driver, private readonly \ArrayObject $sentSql)
            {
                parent::__construct($driver);
            }

            public function connect(
                #[\SensitiveParameter]
                array $params,
            ): DriverConnection {
                return new class(parent::connect($params), $this->sentSql) extends AbstractConnectionMiddleware {
                    /**
                     * @param \ArrayObject<int, string> $sentSql
                     */
                    public function __construct(DriverConnection $connection, private readonly \ArrayObject $sentSql)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $this->sentSql->append($sql);

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        $this->sentSql->append($sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        $this->sentSql->append($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
        $migrations = $this->migrationsOn(new Connection($this->appConnection()->getParams(), $recordsSql));

        new MigrationsAreCurrent($migrations)->verify();

        self::assertSame(['SELECT "version" FROM "doctrine_migration_versions"'], $sentSql->getArrayCopy());
    }

    /**
     * @param array<string, string> $extraMigrationDirectories namespace => directory
     */
    private function migrationsOn(Connection $connection, array $extraMigrationDirectories = []): DependencyFactory
    {
        $configuration = self::configuration();
        foreach ($extraMigrationDirectories as $namespace => $directory) {
            $configuration->addMigrationsDirectory($namespace, $directory);
        }

        return DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($connection));
    }

    private static function configuration(): Configuration
    {
        $configuration = new Configuration();
        $configuration->setMetadataStorageConfiguration(new TableMetadataStorageConfiguration());
        $configuration->addMigrationsDirectory('DoctrineMigrations', self::getContainer()->getParameter('kernel.project_dir').'/migrations');

        return $configuration;
    }

    private function appConnection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function migratorConnection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }
}
