<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * rvw_screenshot.retake_of (ADR-0048, P14.T6): a retake is a later try of a screenshot and points at the first try of
 * its chain. The foreign key names the organization and the round with it, through rvw_screenshot_round_id_id_key, so a
 * try is always of the same organization and round. Row-level security and the grants of Version20261115180000 cover
 * the new column as they are.
 */
final class Version20261121100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let a screenshot be a retake of the first try of its chain in the same round';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rvw_screenshot ADD retake_of UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE rvw_screenshot ADD CONSTRAINT rvw_screenshot_retake_of_check CHECK (retake_of IS DISTINCT FROM id)');
        // NO ACTION, not RESTRICT: deleting a round cascades to its first tries and their retakes in one statement.
        $this->addSql(
            'ALTER TABLE rvw_screenshot ADD CONSTRAINT rvw_screenshot_retake_of_fkey FOREIGN KEY (organization_id, round_id, retake_of)'
            .' REFERENCES rvw_screenshot (organization_id, round_id, id)',
        );
        // Serves the foreign key when a first try is removed.
        $this->addSql('CREATE INDEX rvw_screenshot_retake_of_idx ON rvw_screenshot (organization_id, round_id, retake_of)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rvw_screenshot DROP COLUMN retake_of');
    }
}
