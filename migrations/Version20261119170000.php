<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds asker_id and updated_at to the end of tkt_ticket_list_v for Insight's MyTicketQueries (the MCP tool
 * list_my_tickets): who asked a ticket's open question, which its Now line names, and when it last changed, which
 * orders the list. Existing columns keep their names and places, so the view stays stable for its readers (ADR-0015).
 * CREATE OR REPLACE keeps the view's grants; security_invoker is set again so the replace cannot drop it.
 */
final class Version20261119170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add asker_id and updated_at to the view tkt_ticket_list_v';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE OR REPLACE VIEW tkt_ticket_list_v WITH (security_invoker = true) AS'
            .' SELECT organization_id, id, key, title, priority, step_id, asker_id, updated_at FROM tkt_ticket',
        );
    }

    public function down(Schema $schema): void
    {
        // PostgreSQL cannot drop a view's columns in place.
        $this->addSql('DROP VIEW tkt_ticket_list_v');
        $this->addSql(
            'CREATE VIEW tkt_ticket_list_v WITH (security_invoker = true) AS'
            .' SELECT organization_id, id, key, title, priority, step_id FROM tkt_ticket',
        );
        $this->addSql('GRANT SELECT ON tkt_ticket_list_v TO pinround_app');
        $this->addSql('GRANT SELECT ON tkt_ticket_list_v TO pinround_readonly');
    }
}
