<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A project is owned by at most one team (FR-ORG-06): tkt_project.team_id is null while the project is unlinked.
 *
 * down() makes team_id required again. PostgreSQL refuses it (SQLSTATE 23502) while any project has no team, so the
 * rollback stops and nothing is lost; link those projects to a team first.
 */
final class Version20261111100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let a project be unlinked from its team; the rollback fails while a project has no team, losing nothing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tkt_project ALTER team_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tkt_project ALTER team_id SET NOT NULL');
    }
}
