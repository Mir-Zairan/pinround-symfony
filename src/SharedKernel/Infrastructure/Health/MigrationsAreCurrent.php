<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Health;

use Doctrine\Migrations\Version\MigrationStatusCalculator;

/**
 * Every migration the code ships has been executed. config/services.yaml takes the calculator from the migrations
 * DependencyFactory, so it reads the same metadata table, through the same connection, as doctrine:migrations:migrate.
 */
final readonly class MigrationsAreCurrent implements ReadinessCheck
{
    public function __construct(private MigrationStatusCalculator $migrationStatusCalculator)
    {
    }

    public function name(): string
    {
        return 'migrations';
    }

    public function verify(): void
    {
        $unexecutedMigrationCount = \count($this->migrationStatusCalculator->getNewMigrations());
        if (0 !== $unexecutedMigrationCount) {
            throw new ReadinessCheckFailed(\sprintf('%d migrations are not executed.', $unexecutedMigrationCount));
        }
    }
}
