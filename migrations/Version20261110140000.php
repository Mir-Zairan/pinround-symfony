<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives every organization created before SetUpOrganizationWorkflow the eight core steps (CoreStep) and the default
 * organization rule set (RuleSet::defaults()); the values are copied here, so CoreStepBackfillTest compares them.
 * Rows an organization already has are left as they are, so it is safe to run again.
 *
 * Listing every organization is the one read FORCE row-level security cannot allow: no resolver fits it, as a
 * resolver takes one exact key (ADR-0005, data-model.md), and a resolver for all ids would hand pinround_app a
 * permanent list of every tenant. So the statement lifts FORCE on org_organization for the migrator, its owner, only
 * to collect the ids, and forces it again before any row is written. The DO block is one statement: if it fails, the
 * lift rolls back with it, and other sessions never see it. Each organization's rows are then written with that
 * organization as the tenant (bin/check-tenant-setting allows this file for it); wf_step and wf_rule_set keep FORCE.
 */
final class Version20261110140000 extends AbstractMigration
{
    private const string BACKFILL = <<<'SQL'
        DO $backfill$
        DECLARE
            previous_tenant text := current_setting('app.org_id', true);
            organizations uuid[];
            organization uuid;
        BEGIN
            ALTER TABLE org_organization NO FORCE ROW LEVEL SECURITY;
            SELECT coalesce(array_agg(o.organization_id ORDER BY o.organization_id), '{}') INTO organizations FROM org_organization o;
            ALTER TABLE org_organization FORCE ROW LEVEL SECURITY;
            FOREACH organization IN ARRAY organizations LOOP
                PERFORM set_config('app.org_id', organization::text, true);
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
            PERFORM set_config('app.org_id', coalesce(previous_tenant, ''), true);
        END
        $backfill$
        SQL;

    public function getDescription(): string
    {
        return 'Give every existing organization the core steps and the default rule set';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(self::BACKFILL);
    }

    public function down(Schema $schema): void
    {
        // Nothing to undo safely: the rows cannot be told apart from those SetUpOrganizationWorkflow writes.
    }
}
