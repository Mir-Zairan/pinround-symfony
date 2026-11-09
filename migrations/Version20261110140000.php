<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\TenantSetting;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives every organization created before SetUpOrganizationWorkflow the eight core steps (CoreStep) and the default
 * organization rule set (RuleSet::defaults()); the values are copied here, so CoreStepBackfillTest compares them.
 * The migrator owns org_organization but FORCE row-level security hides every row while no tenant is set, so the
 * one statement lifts FORCE on org_organization alone to list the organizations, and puts it back before it ends.
 * wf_step and wf_rule_set keep FORCE: each organization's rows are written with that organization as the tenant.
 * Rows an organization already has are left as they are, so it is safe to run again.
 */
final class Version20261110140000 extends AbstractMigration
{
    private const string BACKFILL = <<<'SQL'
        DO $backfill$
        DECLARE
            previous_tenant text := current_setting('%1$s', true);
            organization uuid;
        BEGIN
            ALTER TABLE org_organization NO FORCE ROW LEVEL SECURITY;
            FOR organization IN SELECT o.organization_id FROM org_organization o ORDER BY o.organization_id LOOP
                PERFORM set_config('%1$s', organization::text, true);
                INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position, archived_at)
                SELECT organization, uuidv7(), NULL, core.name, core.grp, core.code, 'core', core.position, NULL
                FROM (VALUES
                    ('backlog', 'Backlog', 'todo', 0),
                    ('todo', 'To do', 'todo', 1),
                    ('progress', 'In progress', 'doing', 2),
                    ('review', 'Code review', 'check', 3),
                    ('test', 'Testing', 'check', 4),
                    ('input', 'Needs input', 'wait', 5),
                    ('hold', 'On hold', 'wait', 6),
                    ('done', 'Done', 'done', 7)
                ) AS core (code, name, grp, position)
                ON CONFLICT (organization_id, team_id, core_code) WHERE core_code IS NOT NULL DO NOTHING;
                INSERT INTO wf_rule_set (organization_id, team_id, tester_moves_done, no_self_review, review_before_test)
                VALUES (organization, NULL, true, true, true)
                ON CONFLICT (organization_id, team_id) DO NOTHING;
            END LOOP;
            PERFORM set_config('%1$s', coalesce(previous_tenant, ''), true);
            ALTER TABLE org_organization FORCE ROW LEVEL SECURITY;
        END
        $backfill$
        SQL;

    public function getDescription(): string
    {
        return 'Give every existing organization the core steps and the default rule set';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(\sprintf(self::BACKFILL, TenantSetting::NAME));
    }

    public function down(Schema $schema): void
    {
        // Nothing to undo safely: the rows cannot be told apart from those SetUpOrganizationWorkflow writes.
    }
}
