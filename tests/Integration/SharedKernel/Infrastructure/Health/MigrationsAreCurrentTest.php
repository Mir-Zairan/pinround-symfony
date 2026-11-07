<?php

declare(strict_types=1);

namespace App\Tests\Integration\SharedKernel\Infrastructure\Health;

use App\SharedKernel\Infrastructure\Health\MigrationsAreCurrent;
use App\SharedKernel\Infrastructure\Health\ReadinessCheckFailed;
use App\Tests\Integration\SharedKernel\Infrastructure\Health\UnexecutedMigration\Version29991231000000;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
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

        new MigrationsAreCurrent($this->migrationsOn($appConnection)->getMigrationStatusCalculator())->verify();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function aMigrationTheCodeShipsButTheDatabaseNeverRanFailsTheCheck(): void
    {
        $dependencyFactory = $this->migrationsOn(
            $this->migratorConnection(),
            [new \ReflectionClass(Version29991231000000::class)->getNamespaceName() => __DIR__.'/UnexecutedMigration'],
        );

        $this->expectException(ReadinessCheckFailed::class);

        new MigrationsAreCurrent($dependencyFactory->getMigrationStatusCalculator())->verify();
    }

    /**
     * @param array<string, string> $extraMigrationDirectories namespace => directory
     */
    private function migrationsOn(Connection $connection, array $extraMigrationDirectories = []): DependencyFactory
    {
        $configuration = new Configuration();
        $configuration->setMetadataStorageConfiguration(new TableMetadataStorageConfiguration());
        $configuration->addMigrationsDirectory('DoctrineMigrations', self::getContainer()->getParameter('kernel.project_dir').'/migrations');
        foreach ($extraMigrationDirectories as $namespace => $directory) {
            $configuration->addMigrationsDirectory($namespace, $directory);
        }

        return DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($connection));
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
