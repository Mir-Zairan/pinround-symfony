<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * rvw_area, the screen areas of a review round (P05.T7): each compares one "after" picture of the round with the
 * earlier round's picture of the same place ("before", none when there is no earlier one) and holds the reviewer's
 * verdict on it. A tenant table with row-level security, like the other Review tables (Version20261115180000), hidden
 * by the schema_filter as DbalAreas writes it. The CHECKs repeat Area's rules. A verdict on a pin of the earlier round
 * is rvw_pin.verdict, which the app may update already. rvw_screenshot gets a key with its round for the "after" FK.
 */
final class Version20261116130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create rvw_area, the screen areas of a review round with their verdicts, with row-level security';
    }

    public function up(Schema $schema): void
    {
        // The primary key with round_id: rvw_area_after_screenshot_fkey names it so an "after" picture is of the area's round.
        $this->addSql('CREATE UNIQUE INDEX rvw_screenshot_round_id_id_key ON rvw_screenshot (organization_id, round_id, id)');
        $this->addSql(
            'CREATE TABLE rvw_area (organization_id UUID NOT NULL, id UUID NOT NULL, round_id UUID NOT NULL, name TEXT NOT NULL,'
            .' position INT NOT NULL, before_screenshot_id UUID DEFAULT NULL, after_screenshot_id UUID NOT NULL,'
            .' verdict TEXT DEFAULT NULL, note TEXT DEFAULT NULL, decided_by UUID DEFAULT NULL,'
            .' decided_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            .' CONSTRAINT rvw_area_position_check CHECK (position >= 0),'
            // Area::NAME_MAX_LENGTH and NOTE_MAX_LENGTH, counted and trimmed as rvw_screenshot's title; a blank note is NULL.
            ." CONSTRAINT rvw_area_name_check CHECK (char_length(name) BETWEEN 1 AND 80 AND name = btrim(name, E' \\t\\n\\r\\x0B')),"
            ." CONSTRAINT rvw_area_note_check CHECK (char_length(note) BETWEEN 1 AND 1000 AND note = btrim(note, E' \\t\\n\\r\\x0B')),"
            // The values of AreaVerdict; who decided and when come with the verdict, and a note only with one (Area::decide).
            ." CONSTRAINT rvw_area_verdict_check CHECK (verdict IN ('pass', 'needs_fix', 'question')),"
            .' CONSTRAINT rvw_area_decided_check CHECK ((verdict IS NULL) = (decided_by IS NULL) AND (verdict IS NULL) = (decided_at IS NULL) AND (note IS NULL OR verdict IS NOT NULL)),'
            .' CONSTRAINT rvw_area_screenshots_check CHECK (before_screenshot_id IS DISTINCT FROM after_screenshot_id),'
            .' CONSTRAINT rvw_area_round_fkey FOREIGN KEY (organization_id, round_id) REFERENCES rvw_round (organization_id, id) ON DELETE CASCADE,'
            .' CONSTRAINT rvw_area_after_screenshot_fkey FOREIGN KEY (organization_id, round_id, after_screenshot_id) REFERENCES rvw_screenshot (organization_id, round_id, id) ON DELETE CASCADE,'
            // An earlier round is shared, and a shared round's pictures are never removed, so a "before" is never cascaded away.
            .' CONSTRAINT rvw_area_before_screenshot_fkey FOREIGN KEY (organization_id, before_screenshot_id) REFERENCES rvw_screenshot (organization_id, id))',
        );
        $this->addSql('CREATE UNIQUE INDEX rvw_area_round_id_position_key ON rvw_area (organization_id, round_id, position)');
        // One area per "after" picture; it also serves the foreign key when a draft removes a picture.
        $this->addSql('CREATE UNIQUE INDEX rvw_area_after_screenshot_id_key ON rvw_area (organization_id, after_screenshot_id)');
        $this->addSql('CREATE INDEX rvw_area_before_screenshot_id_idx ON rvw_area (organization_id, before_screenshot_id)');
        foreach (TenantTable::enableRls('rvw_area') as $sql) {
            $this->addSql($sql);
        }
        // No DELETE: an area goes only with its round or picture. UPDATE: a verdict.
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON rvw_area TO pinround_app');
        $this->addSql('GRANT SELECT ON rvw_area TO pinround_readonly');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rvw_area');
        $this->addSql('DROP INDEX rvw_screenshot_round_id_id_key');
    }
}
