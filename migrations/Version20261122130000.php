<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * rvw_round_note, the general notes on a shared round, each with an optional verdict, in place of the per-screenshot
 * verdicts of rvw_screenshot_verdict, which this drops (ADR-0048, note of 2026-11-22; its rows were demo data only). A
 * tenant table with row-level security, hidden by the schema_filter as DbalGeneralNotes writes it; the CHECKs repeat
 * GeneralNote's rules.
 */
final class Version20261122130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create rvw_round_note, the general notes on a shared round, with row-level security; drop rvw_screenshot_verdict';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE rvw_screenshot_verdict');
        $this->addSql(
            'CREATE TABLE rvw_round_note (organization_id UUID NOT NULL, id UUID NOT NULL, round_id UUID NOT NULL, author_id UUID NOT NULL,'
            .' verdict TEXT DEFAULT NULL, note TEXT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            // AreaVerdict's values; GeneralNote::NOTE_MAX_LENGTH, trimmed as the domain trims it.
            ." CONSTRAINT rvw_round_note_verdict_check CHECK (verdict IS NULL OR verdict IN ('pass', 'needs_fix', 'question')),"
            ." CONSTRAINT rvw_round_note_note_check CHECK (char_length(note) BETWEEN 1 AND 2000 AND note = btrim(note, E' \\t\\n\\r\\x0B')),"
            .' CONSTRAINT rvw_round_note_round_fkey FOREIGN KEY (organization_id, round_id) REFERENCES rvw_round (organization_id, id) ON DELETE CASCADE)',
        );
        // The round page and get_round_notes read a round's notes newest first; the foreign key's cascade uses it too.
        $this->addSql('CREATE INDEX rvw_round_note_round_id_created_at_idx ON rvw_round_note (organization_id, round_id, created_at)');
        foreach (TenantTable::enableRls('rvw_round_note') as $sql) {
            $this->addSql($sql);
        }
        // No UPDATE or DELETE: a note is never changed, and goes only with its round.
        $this->addSql('GRANT SELECT, INSERT ON rvw_round_note TO pinround_app');
        $this->addSql('GRANT SELECT ON rvw_round_note TO pinround_readonly');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rvw_round_note');
        $this->addSql(
            'CREATE TABLE rvw_screenshot_verdict (organization_id UUID NOT NULL, screenshot_id UUID NOT NULL, round_id UUID NOT NULL,'
            .' verdict TEXT NOT NULL, note TEXT DEFAULT NULL, decided_by UUID NOT NULL, decided_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' PRIMARY KEY (organization_id, screenshot_id),'
            ." CONSTRAINT rvw_screenshot_verdict_verdict_check CHECK (verdict IN ('pass', 'needs_fix', 'question')),"
            ." CONSTRAINT rvw_screenshot_verdict_note_check CHECK (char_length(note) BETWEEN 1 AND 1000 AND note = btrim(note, E' \\t\\n\\r\\x0B')),"
            .' CONSTRAINT rvw_screenshot_verdict_screenshot_fkey FOREIGN KEY (organization_id, round_id, screenshot_id) REFERENCES rvw_screenshot (organization_id, round_id, id) ON DELETE CASCADE)',
        );
        $this->addSql('CREATE INDEX rvw_screenshot_verdict_round_id_idx ON rvw_screenshot_verdict (organization_id, round_id)');
        foreach (TenantTable::enableRls('rvw_screenshot_verdict') as $sql) {
            $this->addSql($sql);
        }
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON rvw_screenshot_verdict TO pinround_app');
        $this->addSql('GRANT SELECT ON rvw_screenshot_verdict TO pinround_readonly');
    }
}
