<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let pinround_app read doctrine_migration_versions, for the migrations check of /health/ready';
    }

    public function up(Schema $schema): void
    {
        // Read only: the table holds version names and run times, no tenant data. pinround_readonly gets nothing.
        $this->addSql('GRANT SELECT ON public.doctrine_migration_versions TO pinround_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE SELECT ON public.doctrine_migration_versions FROM pinround_app');
    }
}
