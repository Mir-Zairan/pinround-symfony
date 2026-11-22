<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * rvw_screenshot_verdict, the verdict with an optional note on one screenshot of a shared draft, as rvw_area holds one
 * on a screen area of a review round (ADR-0048, note of 2026-11-22). One row per screenshot, saved over by the next
 * verdict. A tenant table with row-level security, hidden by the schema_filter as DbalAreas writes it; the CHECKs repeat
 * DraftScreenshotVerdict's rules, which are rvw_area's.
 */
final class Version20261122120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create rvw_screenshot_verdict, the verdicts on the screenshots of shared drafts, with row-level security';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE rvw_screenshot_verdict (organization_id UUID NOT NULL, screenshot_id UUID NOT NULL, round_id UUID NOT NULL,'
            .' verdict TEXT NOT NULL, note TEXT DEFAULT NULL, decided_by UUID NOT NULL, decided_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' PRIMARY KEY (organization_id, screenshot_id),'
            // AreaVerdict's values and Area::NOTE_MAX_LENGTH, trimmed as rvw_area's note; a blank note is NULL.
            ." CONSTRAINT rvw_screenshot_verdict_verdict_check CHECK (verdict IN ('pass', 'needs_fix', 'question')),"
            ." CONSTRAINT rvw_screenshot_verdict_note_check CHECK (char_length(note) BETWEEN 1 AND 1000 AND note = btrim(note, E' \\t\\n\\r\\x0B')),"
            // Through rvw_screenshot_round_id_id_key, so the screenshot is one of the verdict's round.
            .' CONSTRAINT rvw_screenshot_verdict_screenshot_fkey FOREIGN KEY (organization_id, round_id, screenshot_id) REFERENCES rvw_screenshot (organization_id, round_id, id) ON DELETE CASCADE)',
        );
        // The round page reads the shown screenshot's verdict by the primary key; this one serves the foreign key's
        // cascade when a round goes, and a read of a round's verdicts.
        $this->addSql('CREATE INDEX rvw_screenshot_verdict_round_id_idx ON rvw_screenshot_verdict (organization_id, round_id)');
        foreach (TenantTable::enableRls('rvw_screenshot_verdict') as $sql) {
            $this->addSql($sql);
        }
        // No DELETE: a verdict goes only with its screenshot. UPDATE: the next verdict saved over it.
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON rvw_screenshot_verdict TO pinround_app');
        $this->addSql('GRANT SELECT ON rvw_screenshot_verdict TO pinround_readonly');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rvw_screenshot_verdict');
    }
}
