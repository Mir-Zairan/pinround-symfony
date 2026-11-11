<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * tkt_ticket and tkt_summary, tenant tables with row-level security, and the foreign key of ins_ticket_turn.ticket_id
 * that Version20261110160000 left for this one. The search tsvector of tkt_ticket comes later, with its GIN indexes.
 * tkt_summary has no entity (P08 fills it), so the schema_filter hides it. The index names leave out the leading
 * organization_id to stay within PostgreSQL's 63 characters.
 */
final class Version20261111120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tkt_ticket and tkt_summary with row-level security; link ins_ticket_turn to tkt_ticket';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE tkt_ticket (organization_id UUID NOT NULL, id UUID NOT NULL, project_id UUID NOT NULL,'
            .' number INT NOT NULL, key TEXT NOT NULL, title TEXT NOT NULL, description TEXT NOT NULL, type TEXT NOT NULL,'
            .' priority TEXT NOT NULL, priority_rank SMALLINT NOT NULL, step_id UUID NOT NULL, previous_step_id UUID DEFAULT NULL,'
            .' assignee_id UUID DEFAULT NULL, pending_assignee_id UUID DEFAULT NULL, reviewer_id UUID DEFAULT NULL,'
            .' tester_id UUID DEFAULT NULL, waiting_on_user_id UUID DEFAULT NULL, waiting_on_label TEXT DEFAULT NULL,'
            .' reporter_id UUID NOT NULL, team_id UUID DEFAULT NULL, visibility TEXT NOT NULL, release TEXT DEFAULT NULL,'
            .' parent_id UUID DEFAULT NULL, components TEXT[] NOT NULL, check_reasons TEXT[] NOT NULL,'
            .' checked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, checked_by UUID DEFAULT NULL,'
            .' closed_as_duplicate_of UUID DEFAULT NULL, closed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,'
            .' version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            ." CONSTRAINT tkt_ticket_type_check CHECK (type IN ('bug', 'story', 'task')),"
            ." CONSTRAINT tkt_ticket_priority_check CHECK (priority IN ('blocker', 'critical', 'major', 'normal', 'minor')),"
            // The same map as Priority::rank(), so the rank the lists sort by never drifts from the priority.
            ." CONSTRAINT tkt_ticket_priority_rank_check CHECK (priority_rank = CASE priority WHEN 'blocker' THEN 0"
            ." WHEN 'critical' THEN 1 WHEN 'major' THEN 2 WHEN 'normal' THEN 3 WHEN 'minor' THEN 4 END),"
            ." CONSTRAINT tkt_ticket_visibility_check CHECK (visibility IN ('all', 'team')))",
        );
        $this->addSql('CREATE UNIQUE INDEX tkt_ticket_organization_id_project_id_number_key ON tkt_ticket (organization_id, project_id, number)');
        $this->addSql('CREATE UNIQUE INDEX tkt_ticket_organization_id_key_key ON tkt_ticket (organization_id, key)');
        $this->addSql(
            'CREATE INDEX tkt_ticket_project_id_step_id_priority_rank_updated_at_id_idx'
            .' ON tkt_ticket (organization_id, project_id, step_id, priority_rank, updated_at DESC, id DESC)',
        );
        $this->addSql('CREATE INDEX tkt_ticket_step_id_priority_rank_updated_at_id_idx ON tkt_ticket (organization_id, step_id, priority_rank, updated_at DESC, id DESC)');
        $this->addSql('CREATE INDEX tkt_ticket_reporter_id_created_at_id_idx ON tkt_ticket (organization_id, reporter_id, created_at DESC, id DESC)');
        $this->addSql('CREATE INDEX tkt_ticket_assignee_id_idx ON tkt_ticket (organization_id, assignee_id) WHERE (closed_at IS NULL)');

        foreach (TenantTable::enableRls('tkt_ticket') as $sql) {
            $this->addSql($sql);
        }
        $this->addSql('GRANT SELECT ON tkt_ticket TO pinround_readonly');
        // No DELETE: open tickets are never removed; the retention job removes closed ones as its own role.
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON tkt_ticket TO pinround_app');

        $this->addSql(
            'CREATE TABLE tkt_summary (organization_id UUID NOT NULL, id UUID NOT NULL, ticket_id UUID NOT NULL,'
            .' text TEXT NOT NULL, source TEXT NOT NULL, author_id UUID DEFAULT NULL, model TEXT DEFAULT NULL,'
            .' is_current BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            ." CONSTRAINT tkt_summary_source_check CHECK (source IN ('sync', 'ai', 'explanation', 'human')),"
            .' CONSTRAINT tkt_summary_ticket_fkey FOREIGN KEY (organization_id, ticket_id) REFERENCES tkt_ticket (organization_id, id) ON DELETE CASCADE)',
        );
        $this->addSql('CREATE INDEX tkt_summary_ticket_id_created_at_idx ON tkt_summary (organization_id, ticket_id, created_at DESC)');
        $this->addSql('CREATE UNIQUE INDEX tkt_summary_ticket_id_current_key ON tkt_summary (organization_id, ticket_id) WHERE is_current');

        foreach (TenantTable::enableRls('tkt_summary') as $sql) {
            $this->addSql($sql);
        }
        $this->addSql('GRANT SELECT ON tkt_summary TO pinround_readonly');
        // UPDATE: a new current summary clears is_current on the one before.
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON tkt_summary TO pinround_app');

        $this->addSql(
            'ALTER TABLE ins_ticket_turn ADD CONSTRAINT ins_ticket_turn_ticket_fkey'
            .' FOREIGN KEY (organization_id, ticket_id) REFERENCES tkt_ticket (organization_id, id) ON DELETE CASCADE',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ins_ticket_turn DROP CONSTRAINT ins_ticket_turn_ticket_fkey');
        $this->addSql('DROP TABLE tkt_summary');
        $this->addSql('DROP TABLE tkt_ticket');
    }
}
