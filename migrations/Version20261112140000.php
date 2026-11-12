<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ins_project_counts, the open and done counts of the Tickets page's project rail, a tenant table with row-level
 * security: one row per project and ticket visibility, so a viewer outside the project's team reads only the 'all' row
 * (data-model.md › Other Insight tables). Version20261112140100 fills it for existing tickets.
 */
final class Version20261112140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ins_project_counts with row-level security';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE ins_project_counts (organization_id UUID NOT NULL, project_id UUID NOT NULL, visibility TEXT NOT NULL,'
            .' open_count INT NOT NULL, done_count INT NOT NULL,'
            .' PRIMARY KEY (organization_id, project_id, visibility),'
            ." CONSTRAINT ins_project_counts_visibility_check CHECK (visibility IN ('all', 'team')),"
            .' CONSTRAINT ins_project_counts_counts_check CHECK (open_count >= 0 AND done_count >= 0),'
            // A project is never removed by the app; the cascade keeps test and retention removals simple.
            .' CONSTRAINT ins_project_counts_project_fkey FOREIGN KEY (organization_id, project_id)'
            .' REFERENCES tkt_project (organization_id, id) ON DELETE CASCADE)',
        );

        foreach (TenantTable::enableRls('ins_project_counts') as $sql) {
            $this->addSql($sql);
        }
        $this->addSql('GRANT SELECT ON ins_project_counts TO pinround_readonly');
        // DELETE: the recount replaces an organization's rows.
        $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON ins_project_counts TO pinround_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ins_project_counts');
    }
}
