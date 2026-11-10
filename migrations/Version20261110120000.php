<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * wf_step and wf_rule_set, tenant tables with row-level security: the steps of an organization and its teams, and
 * the rule sets that guard moves. A NULL team_id is the organization's own row (core and company steps, the
 * organization rule set), so both unique indexes are NULLS NOT DISTINCT; a primary key cannot hold coalesce(team_id),
 * hence wf_rule_set has none. No foreign key to org_team, a table of another module.
 */
final class Version20261110120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create wf_step and wf_rule_set with row-level security';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE wf_step (organization_id UUID NOT NULL, id UUID NOT NULL, team_id UUID DEFAULT NULL,'
            .' name TEXT NOT NULL, grp TEXT NOT NULL, core_code TEXT DEFAULT NULL, origin TEXT NOT NULL,'
            .' position INT NOT NULL, archived_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            ." CONSTRAINT wf_step_grp_check CHECK (grp IN ('todo', 'doing', 'check', 'wait', 'done')),"
            ." CONSTRAINT wf_step_core_code_check CHECK (core_code IN ('backlog', 'todo', 'progress', 'review', 'test', 'input', 'hold', 'done')),"
            // The same rules as the Step constructor: a core step has a core code and no team, a company step
            // neither, a team step a team and no core code.
            ." CONSTRAINT wf_step_origin_check CHECK ((origin = 'core' AND core_code IS NOT NULL AND team_id IS NULL)"
            ." OR (origin = 'company' AND core_code IS NULL AND team_id IS NULL)"
            ." OR (origin = 'team' AND core_code IS NULL AND team_id IS NOT NULL)))",
        );
        $this->addSql('CREATE INDEX wf_step_organization_id_team_id_grp_position_idx ON wf_step (organization_id, team_id, grp, position)');
        $this->addSql(
            'CREATE UNIQUE INDEX wf_step_organization_id_team_id_core_code_key ON wf_step (organization_id, team_id, core_code)'
            .' NULLS NOT DISTINCT WHERE (core_code IS NOT NULL)',
        );

        $this->addSql(
            'CREATE TABLE wf_rule_set (organization_id UUID NOT NULL, team_id UUID DEFAULT NULL,'
            .' tester_moves_done BOOLEAN NOT NULL, no_self_review BOOLEAN NOT NULL, review_before_test BOOLEAN NOT NULL)',
        );
        // DbalRuleSets' ON CONFLICT (organization_id, team_id) infers this index; NULLS NOT DISTINCT makes a second
        // organization row (NULL team) a conflict too.
        $this->addSql('CREATE UNIQUE INDEX wf_rule_set_organization_id_team_id_key ON wf_rule_set (organization_id, team_id) NULLS NOT DISTINCT');

        foreach (['wf_step', 'wf_rule_set'] as $table) {
            foreach (TenantTable::enableRls($table) as $sql) {
                $this->addSql($sql);
            }
            $this->addSql(\sprintf('GRANT SELECT ON %s TO pinround_readonly', $table));
            $this->addSql(\sprintf('GRANT SELECT, INSERT, UPDATE ON %s TO pinround_app', $table));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE wf_rule_set');
        $this->addSql('DROP TABLE wf_step');
    }
}
