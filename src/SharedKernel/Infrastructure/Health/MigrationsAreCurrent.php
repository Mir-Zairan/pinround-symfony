<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Health;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Every migration the code ships has been executed: the versions doctrine:migrations:migrate would find, against the
 * versions its metadata table lists, read through the migrations configuration's connection (the app's own in prod,
 * which may only SELECT that table). One plain query per probe: Doctrine's own status check would also read the
 * table's structure back from the catalog each time.
 */
final readonly class MigrationsAreCurrent implements ReadinessCheck
{
    public function __construct(
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private DependencyFactory $migrations,
    ) {
    }

    public function name(): string
    {
        return 'migrations';
    }

    public function verify(): void
    {
        $storage = $this->migrations->getConfiguration()->getMetadataStorageConfiguration() ?? new TableMetadataStorageConfiguration();
        if (!$storage instanceof TableMetadataStorageConfiguration) {
            throw new ReadinessCheckFailed('The migrations metadata is not kept in a table.');
        }

        $connection = $this->migrations->getConnection();
        $executedVersions = array_filter($connection->fetchFirstColumn(\sprintf(
            'SELECT %s FROM %s',
            $connection->quoteSingleIdentifier($storage->getVersionColumnName()),
            implode('.', array_map($connection->quoteSingleIdentifier(...), explode('.', $storage->getTableName()))),
        )), \is_string(...));
        $availableVersions = array_map(
            static fn (AvailableMigration $migration): string => (string) $migration->getVersion(),
            $this->migrations->getMigrationRepository()->getMigrations()->getItems(),
        );

        $unexecutedMigrationCount = \count(array_diff($availableVersions, $executedVersions));
        if (0 !== $unexecutedMigrationCount) {
            throw new ReadinessCheckFailed(\sprintf('%d migrations are not executed.', $unexecutedMigrationCount));
        }
    }
}
