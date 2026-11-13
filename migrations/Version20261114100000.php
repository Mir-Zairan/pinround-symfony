<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * tkt_ticket_list_v, the read-only view Ticketing owns for the one join ADR-0015 allows: Insight's query service reads a
 * turn row's ticket key, title and priority through it. security_invoker makes a reader go through tkt_ticket's own
 * row-level security and grants, as if it read the table; without it the view would run as its owner, the migrator.
 */
final class Version20261114100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the view tkt_ticket_list_v for Insight\'s whose-turn and Home reads';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE VIEW tkt_ticket_list_v WITH (security_invoker = true) AS'
            .' SELECT organization_id, id, key, title, priority, step_id FROM tkt_ticket',
        );
        $this->addSql('GRANT SELECT ON tkt_ticket_list_v TO pinround_app');
        $this->addSql('GRANT SELECT ON tkt_ticket_list_v TO pinround_readonly');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW tkt_ticket_list_v');
    }
}
