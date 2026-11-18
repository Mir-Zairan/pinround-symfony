<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds ast_usage.status (ADR-0018 › Metering), so a call whose answer was refused or that failed after the provider was
 * asked is metered too. On the partitioned parent, so every partition gets the column, the CHECK and no default; the
 * grants on the parent already cover the new column.
 */
final class Version20261117143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add status (answered, rejected, failed) to ast_usage';
    }

    public function up(Schema $schema): void
    {
        // Every row written before this column existed was an answered call; from now on DbalMetering always names it.
        $this->addSql("ALTER TABLE ast_usage ADD COLUMN status TEXT NOT NULL DEFAULT 'answered'");
        $this->addSql('ALTER TABLE ast_usage ALTER COLUMN status DROP DEFAULT');
        // AiCallStatus's values.
        $this->addSql("ALTER TABLE ast_usage ADD CONSTRAINT ast_usage_status_check CHECK (status IN ('answered', 'rejected', 'failed'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ast_usage DROP COLUMN status');
    }
}
