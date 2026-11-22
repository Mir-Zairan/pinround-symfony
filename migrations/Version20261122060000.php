<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The checklist made from the report's pins is no numbered round any more (product decision 2026-11-22, ADR-0048's
 * note): it is shared from the start with no number, so the developer's first shared round is Round 1. Its one slot per
 * ticket stays rvw_round_ticket_id_checklist_key; rvw_round_ticket_id_number_key now holds only Round 0 and the shared
 * hand-off rounds. A stored checklist drops its number; the rounds after it keep theirs.
 */
final class Version20261122060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Share the checklist round of a ticket with no number';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rvw_round DROP CONSTRAINT rvw_round_shared_check');
        // FORCE ROW LEVEL SECURITY hides every row from the owner with no tenant set, so it is lifted for the update only,
        // as in Version20261118140000; the migration's transaction restores it on failure.
        $this->addSql('ALTER TABLE rvw_round NO FORCE ROW LEVEL SECURITY');
        $this->addSql("UPDATE rvw_round SET number = NULL WHERE kind = 'checklist'");
        $this->addSql('ALTER TABLE rvw_round FORCE ROW LEVEL SECURITY');
        // Round::restored's rule: a checklist is shared with no number; any other round has a number exactly when shared.
        $this->addSql(
            'ALTER TABLE rvw_round ADD CONSTRAINT rvw_round_shared_check CHECK (CASE'
            ." WHEN kind = 'checklist' THEN number IS NULL AND shared_at IS NOT NULL"
            .' ELSE (number IS NULL) = (shared_at IS NULL) END)',
        );
    }

    public function down(Schema $schema): void
    {
        // The old check wants a number on every checklist, and which one each had is gone.
        $this->throwIrreversibleMigrationException('A checklist round cannot get back the number it had; restore a backup instead.');
    }
}
