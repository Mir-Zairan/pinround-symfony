<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * rvw_notebook, a person's private notebooks of pinned screenshots and scenarios (ADR-0046), with row-level security.
 * The screenshots are one jsonb array of at most 10 {media_id, title, summary, pins}, as rvw_report_draft keeps them;
 * the scenarios one jsonb array of at most 20. No entity maps it, so the schema_filter hides it.
 */
final class Version20261120100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create rvw_notebook, the owner-only notebooks, with row-level security';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE rvw_notebook (organization_id UUID NOT NULL, id UUID NOT NULL, owner_id UUID NOT NULL, title TEXT NOT NULL,'
            .' screenshots JSONB NOT NULL, scenarios JSONB NOT NULL, version INT NOT NULL, created_at TIMESTAMP WITH TIME ZONE NOT NULL,'
            .' updated_at TIMESTAMP WITH TIME ZONE NOT NULL, last_used_at TIMESTAMP WITH TIME ZONE NOT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            .' CONSTRAINT rvw_notebook_title_check CHECK (char_length(title) BETWEEN 1 AND 120),'
            ." CONSTRAINT rvw_notebook_screenshots_check CHECK (jsonb_typeof(screenshots) = 'array' AND jsonb_array_length(screenshots) <= 10),"
            ." CONSTRAINT rvw_notebook_scenarios_check CHECK (jsonb_typeof(scenarios) = 'array' AND jsonb_array_length(scenarios) <= 20),"
            .' CONSTRAINT rvw_notebook_version_check CHECK (version >= 1),'
            .' CONSTRAINT rvw_notebook_updated_at_check CHECK (updated_at >= created_at),'
            .' CONSTRAINT rvw_notebook_last_used_at_check CHECK (last_used_at >= created_at))',
        );
        // The owner's notebooks, last used first, a keyset page at a time; every read names the owner.
        $this->addSql('CREATE INDEX rvw_notebook_owner_id_last_used_at_idx ON rvw_notebook (organization_id, owner_id, last_used_at DESC, id DESC)');

        foreach (TenantTable::enableRls('rvw_notebook') as $statement) {
            $this->addSql($statement);
        }
        // UPDATE for rename, screenshots and scenarios, DELETE for "Delete notebook".
        $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON rvw_notebook TO pinround_app');
        $this->addSql('GRANT SELECT ON rvw_notebook TO pinround_readonly');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rvw_notebook');
    }
}
