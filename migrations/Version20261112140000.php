<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ins_project_counts, the open and done counts of the Tickets page's project rail, and ins_project_count_ticket, the
 * count row each ticket is in, both tenant tables with row-level security (data-model.md › Other Insight tables). A
 * count row is per project, ticket team and visibility, so a viewer reads a 'team' row only for their own team.
 * DbalTurnProjector moves a ticket between count rows; Version20261112140100 fills both for existing tickets.
 */
final class Version20261112140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ins_project_counts and ins_project_count_ticket with row-level security';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE ins_project_counts (organization_id UUID NOT NULL, project_id UUID NOT NULL, team_id UUID NOT NULL,'
            .' visibility TEXT NOT NULL, open_count INT NOT NULL, done_count INT NOT NULL,'
            .' PRIMARY KEY (organization_id, project_id, team_id, visibility),'
            ." CONSTRAINT ins_project_counts_visibility_check CHECK (visibility IN ('all', 'team')),"
            .' CONSTRAINT ins_project_counts_counts_check CHECK (open_count >= 0 AND done_count >= 0),'
            // A project is never removed by the app; the cascade keeps test and retention removals simple.
            .' CONSTRAINT ins_project_counts_project_fkey FOREIGN KEY (organization_id, project_id)'
            .' REFERENCES tkt_project (organization_id, id) ON DELETE CASCADE)',
        );
        $this->addSql(
            'CREATE TABLE ins_project_count_ticket (organization_id UUID NOT NULL, ticket_id UUID NOT NULL,'
            .' project_id UUID NOT NULL, team_id UUID NOT NULL, visibility TEXT NOT NULL, is_done BOOLEAN NOT NULL,'
            .' PRIMARY KEY (organization_id, ticket_id),'
            ." CONSTRAINT ins_project_count_ticket_visibility_check CHECK (visibility IN ('all', 'team')),"
            // As ins_ticket_turn's: the app never removes a ticket, so the counts it leaves are a test and retention matter.
            .' CONSTRAINT ins_project_count_ticket_ticket_fkey FOREIGN KEY (organization_id, ticket_id)'
            .' REFERENCES tkt_ticket (organization_id, id) ON DELETE CASCADE)',
        );

        foreach (['ins_project_counts', 'ins_project_count_ticket'] as $table) {
            foreach (TenantTable::enableRls($table) as $sql) {
                $this->addSql($sql);
            }
            $this->addSql(\sprintf('GRANT SELECT ON %s TO pinround_readonly', $table));
        }
        // No DELETE: a count row stays, at zero, when its last ticket leaves it.
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON ins_project_counts TO pinround_app');
        // DELETE: a ticket that loses its team is counted nowhere.
        $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON ins_project_count_ticket TO pinround_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ins_project_count_ticket');
        $this->addSql('DROP TABLE ins_project_counts');
    }
}
