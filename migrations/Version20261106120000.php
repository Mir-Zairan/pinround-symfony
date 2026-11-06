<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enable the PostgreSQL extensions pg_trgm, citext and pgcrypto';
    }

    public function up(Schema $schema): void
    {
        // In the migration, not an init script, so every database (dev, test, CI, prod) gets them.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS citext');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pgcrypto');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP EXTENSION IF EXISTS pgcrypto');
        $this->addSql('DROP EXTENSION IF EXISTS citext');
        $this->addSql('DROP EXTENSION IF EXISTS pg_trgm');
    }
}
