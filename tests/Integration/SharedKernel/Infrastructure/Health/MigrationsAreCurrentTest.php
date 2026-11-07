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
 * On the migrated test database, as pinround_migrator (the connection doctrine:migrations:migrate uses in test).
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
    public function aMigrationTheCodeShipsButTheDatabaseNeverRanFailsTheCheck(): void
    {
        $configuration = new Configuration();
        $configuration->setMetadataStorageConfiguration(new TableMetadataStorageConfiguration());
        $configuration->addMigrationsDirectory('DoctrineMigrations', self::bootKernel()->getProjectDir().'/migrations');
        $configuration->addMigrationsDirectory(
            new \ReflectionClass(Version29991231000000::class)->getNamespaceName(),
            __DIR__.'/UnexecutedMigration',
        );
        $dependencyFactory = DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($this->migratorConnection()));

        $this->expectException(ReadinessCheckFailed::class);

        new MigrationsAreCurrent($dependencyFactory->getMigrationStatusCalculator())->verify();
    }

    private function migratorConnection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }
}
