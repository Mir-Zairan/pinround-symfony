<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * list_my_tickets (DbalMyTicketQueries::myTicketsOf) reads the turn rows that wait on the caller or name them as
 * assignee; the turn half has ins_ticket_turn_turn_user_id_base_score_idx, and this index serves the assignee half, so
 * the read no longer scans the organization's open turn rows.
 *
 * ins_ticket_turn holds a row per open ticket and is written on every ticket change, so the index is built
 * CONCURRENTLY (data-model.md, migration rules), which cannot run in a transaction: this migration runs without one,
 * and holds nothing else. A failed concurrent build leaves an INVALID index of that name behind and no version row, so
 * up() drops a leftover first and a re-run builds it again. Indexes need no grants, and row-level security is
 * table-wide.
 */
final class Version20261119180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the index of a turn row\'s assignee to ins_ticket_turn for list_my_tickets';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS ins_ticket_turn_assignee_id_idx');
        $this->addSql('CREATE INDEX CONCURRENTLY ins_ticket_turn_assignee_id_idx ON ins_ticket_turn (organization_id, assignee_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY ins_ticket_turn_assignee_id_idx');
    }
}
