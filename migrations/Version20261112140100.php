<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fills ins_project_count_ticket and ins_project_counts for every existing ticket as DbalTurnProjector would: a ticket
 * with a team is counted under its project, team and visibility, as done when its step's group is done; a ticket with
 * no team is counted nowhere. Safe to run again: each organization's rows are replaced.
 *
 * Listing every organization needs FORCE row-level security lifted on org_organization for the migrator, its owner,
 * as Version20261110140000 explains; it is forced again before any row is written. Each organization's rows are then
 * counted and written with that organization as the tenant (bin/check-tenant-setting allows this file for it), and
 * the caller's tenant setting is put back at the end.
 */
final class Version20261112140100 extends AbstractMigration
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
                DELETE FROM ins_project_count_ticket WHERE organization_id = organization;
                DELETE FROM ins_project_counts WHERE organization_id = organization;
                INSERT INTO ins_project_count_ticket (organization_id, ticket_id, project_id, team_id, visibility, is_done)
                SELECT ticket.organization_id, ticket.id, ticket.project_id, ticket.team_id, ticket.visibility, step.grp = 'done'
                FROM tkt_ticket AS ticket
                JOIN wf_step AS step ON step.organization_id = ticket.organization_id AND step.id = ticket.step_id
                WHERE ticket.organization_id = organization AND ticket.team_id IS NOT NULL;
                INSERT INTO ins_project_counts (organization_id, project_id, team_id, visibility, open_count, done_count)
                SELECT counted.organization_id, counted.project_id, counted.team_id, counted.visibility,
                    count(*) FILTER (WHERE NOT counted.is_done), count(*) FILTER (WHERE counted.is_done)
                FROM ins_project_count_ticket AS counted
                WHERE counted.organization_id = organization
                GROUP BY counted.organization_id, counted.project_id, counted.team_id, counted.visibility;
            END LOOP;
            PERFORM set_config('app.org_id', coalesce(previous_tenant, ''), true);
        END
        $backfill$
        SQL;

    public function getDescription(): string
    {
        return 'Count every existing ticket into ins_project_count_ticket and ins_project_counts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(self::BACKFILL);
    }

    public function down(Schema $schema): void
    {
        // Nothing to undo: Version20261112140000's down drops the tables.
    }
}
