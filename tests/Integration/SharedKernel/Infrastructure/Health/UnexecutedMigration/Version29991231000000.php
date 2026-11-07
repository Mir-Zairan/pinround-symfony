<?php

declare(strict_types=1);

namespace App\Tests\Integration\SharedKernel\Infrastructure\Health\UnexecutedMigration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Test-only: a migration MigrationsAreCurrentTest adds to the real ones and never executes.
 */
final class Version29991231000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        throw new \LogicException('MigrationsAreCurrentTest never executes this migration.');
    }
}
