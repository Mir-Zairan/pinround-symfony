<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the reporter, report and close times, and the visibility predicate's columns to the end of tkt_ticket_list_v for
 * Insight's My reports (HomeQueries::myReports): a reporter's tickets, open and done, newest first through the index
 * tkt_ticket_reporter_id_created_at_id_idx, filtered with TicketVisibilitySql on the view itself, as a done ticket has
 * no turn row to filter on. Existing columns keep their names and places, so the view stays stable for its readers
 * (ADR-0015). CREATE OR REPLACE keeps the view's grants; security_invoker is set again so the replace cannot drop it.
 */
final class Version20261121153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the reporter, report and close times and the visibility columns to the view tkt_ticket_list_v';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE OR REPLACE VIEW tkt_ticket_list_v WITH (security_invoker = true) AS'
            .' SELECT organization_id, id, key, title, priority, step_id, asker_id, updated_at,'
            .' reporter_id, created_at, closed_at, team_id, visibility, assignee_id, pending_assignee_id, reviewer_id,'
            .' tester_id, waiting_on_user_id FROM tkt_ticket',
        );
    }

    public function down(Schema $schema): void
    {
        // PostgreSQL cannot drop a view's columns in place.
        $this->addSql('DROP VIEW tkt_ticket_list_v');
        $this->addSql(
            'CREATE VIEW tkt_ticket_list_v WITH (security_invoker = true) AS'
            .' SELECT organization_id, id, key, title, priority, step_id, asker_id, updated_at FROM tkt_ticket',
        );
        $this->addSql('GRANT SELECT ON tkt_ticket_list_v TO pinround_app');
        $this->addSql('GRANT SELECT ON tkt_ticket_list_v TO pinround_readonly');
    }
}
