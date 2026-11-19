<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * rvw_report_draft, the private report drafts a person's agent prepares and the person sends (ADR-0039, ADR-0040), with
 * row-level security. The screenshots are one jsonb array of at most 10 {media_id, title, summary, pins}. No entity maps
 * it, so the schema_filter hides it.
 */
final class Version20261119190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create rvw_report_draft, the owner-only report drafts, with row-level security';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE rvw_report_draft (organization_id UUID NOT NULL, id UUID NOT NULL, owner_id UUID NOT NULL,'
            .' project_key TEXT NOT NULL, title TEXT NOT NULL, detail TEXT NOT NULL, screenshots JSONB NOT NULL,'
            .' version INT NOT NULL, created_at TIMESTAMP WITH TIME ZONE NOT NULL, updated_at TIMESTAMP WITH TIME ZONE NOT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            // A ticket's title and description caps (tkt_ticket), as Send makes a ticket of them.
            .' CONSTRAINT rvw_report_draft_title_check CHECK (char_length(title) BETWEEN 1 AND 200),'
            .' CONSTRAINT rvw_report_draft_detail_check CHECK (char_length(detail) <= 50000),'
            .' CONSTRAINT rvw_report_draft_project_key_check CHECK (char_length(project_key) BETWEEN 1 AND 20),'
            ." CONSTRAINT rvw_report_draft_screenshots_check CHECK (jsonb_typeof(screenshots) = 'array' AND jsonb_array_length(screenshots) <= 10),"
            .' CONSTRAINT rvw_report_draft_version_check CHECK (version >= 1),'
            .' CONSTRAINT rvw_report_draft_updated_at_check CHECK (updated_at >= created_at))',
        );
        // The owner's "Report drafts" list, newest first; every read names the owner.
        $this->addSql('CREATE INDEX rvw_report_draft_owner_id_updated_at_idx ON rvw_report_draft (organization_id, owner_id, updated_at DESC)');

        foreach (TenantTable::enableRls('rvw_report_draft') as $statement) {
            $this->addSql($statement);
        }
        // UPDATE for the owner's edits, DELETE for Send and "Delete draft".
        $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON rvw_report_draft TO pinround_app');
        $this->addSql('GRANT SELECT ON rvw_report_draft TO pinround_readonly');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rvw_report_draft');
    }
}
