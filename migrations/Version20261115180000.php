<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * rvw_round, rvw_screenshot and rvw_pin, the rounds of a ticket with their pictures and pins (data-model.md, Review
 * and Media), as tenant tables with row-level security. DbalRounds writes them and no entity maps them, so the
 * schema_filter hides them. ticket_id, owner_id, media_id and author_id hold ids of other modules and have no foreign
 * key (ADR-0002); the keys stay inside Review. The CHECKs repeat the domain's rules (Round, Screenshot, Pin), so a row
 * written past the domain cannot hold a state the domain could not reach. Index names leave out the leading
 * organization_id, as Version20261111120000 does.
 */
final class Version20261115180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create rvw_round, rvw_screenshot and rvw_pin with row-level security';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE rvw_round (organization_id UUID NOT NULL, id UUID NOT NULL, ticket_id UUID NOT NULL,'
            .' number INT DEFAULT NULL, kind TEXT NOT NULL, owner_id UUID NOT NULL,'
            .' shared_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, state TEXT NOT NULL, version INT NOT NULL,'
            .' created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            // The values of RoundKind and RoundState.
            ." CONSTRAINT rvw_round_kind_check CHECK (kind IN ('report', 'checklist', 'review', 'draft')),"
            ." CONSTRAINT rvw_round_state_check CHECK (state IN ('open', 'closed')),"
            // A round has a number exactly when it is shared; 0 is the report's and only the report's (Round::share).
            .' CONSTRAINT rvw_round_shared_check CHECK ((number IS NULL) = (shared_at IS NULL)),'
            ." CONSTRAINT rvw_round_number_check CHECK (number >= 0 AND (kind = 'report') = (number IS NOT DISTINCT FROM 0)),"
            .' CONSTRAINT rvw_round_version_check CHECK (version >= 1))',
        );
        // The slots DbalRounds::add takes with ON CONFLICT DO NOTHING; a draft has no number, so drafts never collide here.
        $this->addSql('CREATE UNIQUE INDEX rvw_round_ticket_id_number_key ON rvw_round (organization_id, ticket_id, number)');
        $this->addSql(
            'CREATE UNIQUE INDEX rvw_round_ticket_id_owner_id_open_draft_key ON rvw_round (organization_id, ticket_id, owner_id)'
            ." WHERE kind = 'draft' AND shared_at IS NULL",
        );
        foreach (TenantTable::enableRls('rvw_round') as $sql) {
            $this->addSql($sql);
        }
        // No DELETE yet: nothing in the app removes a round. UPDATE: sharing a draft sets its number and shared_at.
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON rvw_round TO pinround_app');
        $this->addSql('GRANT SELECT ON rvw_round TO pinround_readonly');

        $this->addSql(
            'CREATE TABLE rvw_screenshot (organization_id UUID NOT NULL, id UUID NOT NULL, round_id UUID NOT NULL,'
            .' media_id UUID NOT NULL, position INT NOT NULL, variant_label TEXT DEFAULT NULL, verdict TEXT DEFAULT NULL,'
            .' title TEXT DEFAULT NULL, summary TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            .' CONSTRAINT rvw_screenshot_position_check CHECK (position >= 0),'
            // The values of VariantLabel and ScreenshotVerdict.
            ." CONSTRAINT rvw_screenshot_variant_label_check CHECK (variant_label IN ('before', 'after')),"
            ." CONSTRAINT rvw_screenshot_verdict_check CHECK (verdict IN ('looks_right', 'not_right')),"
            // Screenshot::TITLE_MAX_LENGTH and SUMMARY_MAX_LENGTH, counted in characters as mb_strlen counts them, of a
            // text trimmed of what PHP's trim() removes; a blank one is NULL (PostgreSQL text holds no NUL).
            ." CONSTRAINT rvw_screenshot_title_check CHECK (char_length(title) BETWEEN 1 AND 120 AND title = btrim(title, E' \\t\\n\\r\\x0B')),"
            ." CONSTRAINT rvw_screenshot_summary_check CHECK (char_length(summary) BETWEEN 1 AND 600 AND summary = btrim(summary, E' \\t\\n\\r\\x0B')),"
            .' CONSTRAINT rvw_screenshot_round_fkey FOREIGN KEY (organization_id, round_id) REFERENCES rvw_round (organization_id, id) ON DELETE CASCADE)',
        );
        $this->addSql('CREATE UNIQUE INDEX rvw_screenshot_round_id_position_key ON rvw_screenshot (organization_id, round_id, position)');
        foreach (TenantTable::enableRls('rvw_screenshot') as $sql) {
            $this->addSql($sql);
        }
        // No DELETE yet; UPDATE: a verdict on a picture.
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON rvw_screenshot TO pinround_app');
        $this->addSql('GRANT SELECT ON rvw_screenshot TO pinround_readonly');

        $this->addSql(
            'CREATE TABLE rvw_pin (organization_id UUID NOT NULL, id UUID NOT NULL, screenshot_id UUID NOT NULL,'
            .' number INT NOT NULL, x_pct NUMERIC(5, 2) NOT NULL, y_pct NUMERIC(5, 2) NOT NULL, note TEXT NOT NULL,'
            .' verdict TEXT DEFAULT NULL, source TEXT NOT NULL, ai_state TEXT DEFAULT NULL, author_id UUID NOT NULL,'
            .' created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            .' CONSTRAINT rvw_pin_number_check CHECK (number >= 1),'
            .' CONSTRAINT rvw_pin_position_check CHECK (x_pct BETWEEN 0 AND 100 AND y_pct BETWEEN 0 AND 100),'
            // Pin::NOTE_MAX_LENGTH, counted and trimmed as the screenshot's title; a note may be empty.
            ." CONSTRAINT rvw_pin_note_check CHECK (char_length(note) <= 1000 AND note = btrim(note, E' \\t\\n\\r\\x0B')),"
            // The values of PinVerdict, PinSource and AiPinState; only an AI pin has an AI state.
            ." CONSTRAINT rvw_pin_verdict_check CHECK (verdict IN ('fixed', 'still_wrong')),"
            ." CONSTRAINT rvw_pin_source_check CHECK (source IN ('human', 'ai')),"
            ." CONSTRAINT rvw_pin_ai_state_check CHECK (ai_state IN ('suggested', 'kept', 'dismissed')),"
            ." CONSTRAINT rvw_pin_ai_check CHECK ((source = 'ai') = (ai_state IS NOT NULL)),"
            .' CONSTRAINT rvw_pin_screenshot_fkey FOREIGN KEY (organization_id, screenshot_id) REFERENCES rvw_screenshot (organization_id, id) ON DELETE CASCADE)',
        );
        $this->addSql('CREATE UNIQUE INDEX rvw_pin_screenshot_id_number_key ON rvw_pin (organization_id, screenshot_id, number)');
        foreach (TenantTable::enableRls('rvw_pin') as $sql) {
            $this->addSql($sql);
        }
        // No DELETE yet; UPDATE: a verdict on a pin, or keeping or dismissing an AI pin.
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON rvw_pin TO pinround_app');
        $this->addSql('GRANT SELECT ON rvw_pin TO pinround_readonly');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rvw_pin');
        $this->addSql('DROP TABLE rvw_screenshot');
        $this->addSql('DROP TABLE rvw_round');
    }
}
