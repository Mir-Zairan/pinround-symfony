<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ins_ticket_turn, Insight's turn read model (ADR-0015), a tenant table with row-level security: one row per open
 * ticket, written by DbalTurnProjector in the transaction of the change. ticket_id has no foreign key yet: tkt_ticket
 * comes in P03.T1, which adds it. Visibility and the people columns are copied from the ticket, so Home applies the
 * visibility predicate without a join. The index names leave out the leading organization_id to stay within
 * PostgreSQL's 63 characters.
 */
final class Version20261110160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ins_ticket_turn with row-level security';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE ins_ticket_turn (organization_id UUID NOT NULL, ticket_id UUID NOT NULL, team_id UUID DEFAULT NULL,'
            .' project_id UUID NOT NULL, grp TEXT NOT NULL, turn_user_id UUID DEFAULT NULL, turn_label TEXT DEFAULT NULL,'
            .' kind TEXT NOT NULL, base_score INT NOT NULL, waiting_since TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' check_user_id UUID DEFAULT NULL, visibility TEXT NOT NULL, reporter_id UUID NOT NULL,'
            .' assignee_id UUID DEFAULT NULL, pending_assignee_id UUID DEFAULT NULL, reviewer_id UUID DEFAULT NULL,'
            .' tester_id UUID DEFAULT NULL, waiting_on_user_id UUID DEFAULT NULL,'
            .' PRIMARY KEY (organization_id, ticket_id),'
            // A Done ticket has no row, so every row is in one of the four open groups.
            ." CONSTRAINT ins_ticket_turn_grp_check CHECK (grp IN ('todo', 'doing', 'check', 'wait')),"
            ." CONSTRAINT ins_ticket_turn_kind_check CHECK (kind IN ('answer', 'check', 'review', 'test', 'assign', 'input', 'decide', 'build')),"
            ." CONSTRAINT ins_ticket_turn_visibility_check CHECK (visibility IN ('all', 'team')),"
            // An open ticket is always somebody's turn: a person, or a label such as Customer, never both.
            .' CONSTRAINT ins_ticket_turn_turn_check CHECK (num_nonnulls(turn_user_id, turn_label) = 1))',
        );
        $this->addSql('CREATE INDEX ins_ticket_turn_turn_user_id_base_score_idx ON ins_ticket_turn (organization_id, turn_user_id, base_score DESC)');
        $this->addSql(
            'CREATE INDEX ins_ticket_turn_check_user_id_base_score_idx ON ins_ticket_turn (organization_id, check_user_id, base_score DESC)'
            .' WHERE (check_user_id IS NOT NULL)',
        );
        $this->addSql('CREATE INDEX ins_ticket_turn_team_id_turn_user_id_waiting_since_idx ON ins_ticket_turn (organization_id, team_id, turn_user_id, waiting_since)');
        $this->addSql('CREATE INDEX ins_ticket_turn_project_id_turn_user_id_waiting_since_idx ON ins_ticket_turn (organization_id, project_id, turn_user_id, waiting_since)');
        $this->addSql('CREATE INDEX ins_ticket_turn_team_id_grp_idx ON ins_ticket_turn (organization_id, team_id, grp)');

        foreach (TenantTable::enableRls('ins_ticket_turn') as $sql) {
            $this->addSql($sql);
        }
        $this->addSql('GRANT SELECT ON ins_ticket_turn TO pinround_readonly');
        // DELETE: the projector removes the row when the ticket reaches Done.
        $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON ins_ticket_turn TO pinround_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ins_ticket_turn');
    }
}
