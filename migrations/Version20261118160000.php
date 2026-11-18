<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The intake check's candidates (DbalIntakeCandidateQueries::openCandidatesIn): a project's newest open tickets, read
 * from a partial index in their order, so the check stops after its 80 rows instead of sorting every ticket of the
 * project, closed ones included. The predicate is closed_at only: the read with team-only tickets included uses it too.
 *
 * tkt_ticket is the biggest tenant table, so the index is built CONCURRENTLY (data-model.md, migration rules), which
 * cannot run in a transaction: this migration runs without one, and holds nothing else. A failed concurrent build
 * leaves an INVALID index of that name behind and no version row, so up() drops a leftover first and a re-run builds
 * it again. Indexes need no grants, and row-level security is table-wide.
 */
final class Version20261118160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the partial index of a project\'s newest open tickets to tkt_ticket for the intake candidates';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS tkt_ticket_project_id_updated_at_id_open_idx');
        $this->addSql(
            'CREATE INDEX CONCURRENTLY tkt_ticket_project_id_updated_at_id_open_idx'
            .' ON tkt_ticket (organization_id, project_id, updated_at DESC, id DESC) WHERE closed_at IS NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY tkt_ticket_project_id_updated_at_id_open_idx');
    }
}
