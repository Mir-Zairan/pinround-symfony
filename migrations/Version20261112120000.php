<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * search_ticket_ids, the ticket search of ADR-0022 as a SECURITY DEFINER function (data-model.md › Query patterns).
 * Under row-level security the planner skips the GIN indexes of tkt_ticket, since @@ and % are not LEAKPROOF
 * (TicketSearchQueryPlanTest). The function runs as pinround_resolver, whose resolver_read policy is USING (true), so
 * no policy stands between the planner and the indexes; the body keeps to the caller's organization and the viewer's
 * visibility itself.
 *
 * Contract: ids only, best first (the exact key, then ts_rank * 0.6 + similarity * 0.4, then id), at most 1,000.
 * The visibility condition comes before that cap, so tickets the viewer may not see never push a visible match out.
 * p_organization_id must equal the tenant of the transaction (app.org_id): a caller that passes another
 * organization, or sets none, gets no row.
 *
 * The visibility condition is the one of TicketVisibilitySql, written out once here since a migration must not change
 * with src/; HandWrittenVisibilityConditions::ALLOWED lists this file and TicketSearchFunctionLeakTest proves both
 * give the same tickets.
 */
final class Version20261112120000 extends AbstractMigration
{
    private const string SIGNATURE = 'public.search_ticket_ids(uuid, text, uuid, uuid[], boolean, boolean)';

    /** The columns the body matches on or returns; never returned: title and search, which only match. */
    private const array READ_COLUMNS = [
        'organization_id', 'id', 'key', 'title', 'search', 'visibility', 'team_id', 'reporter_id', 'assignee_id',
        'pending_assignee_id', 'reviewer_id', 'tester_id', 'waiting_on_user_id',
    ];

    public function getDescription(): string
    {
        return 'Create search_ticket_ids, the ticket search as a SECURITY DEFINER function that can use the GIN indexes';
    }

    public function up(Schema $schema): void
    {
        foreach (TenantTable::allowResolverRead('tkt_ticket', self::READ_COLUMNS) as $sql) {
            $this->addSql($sql);
        }

        // upper(btrim(p_query)) finds the key through its btree index; keys are upper case by tkt_project_key_check.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION public.search_ticket_ids(p_organization_id uuid, p_query text, p_viewer_id uuid,
                p_team_ids uuid[], p_is_admin boolean, p_is_triager boolean)
            RETURNS TABLE (ticket_id uuid) LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp AS $resolver$
            SELECT t.id
            FROM public.tkt_ticket t
            WHERE t.organization_id = p_organization_id
              AND p_organization_id = NULLIF(current_setting('app.org_id', true), '')::uuid
              AND (t.search @@ websearch_to_tsquery('simple', public.tkt_unaccent(p_query))
                   OR t.title % p_query
                   OR t.key = upper(btrim(p_query)))
              AND (t.visibility = 'all'
                   OR t.team_id = ANY(p_team_ids)
                   OR p_viewer_id IN (t.reporter_id, t.assignee_id, t.pending_assignee_id,
                                      t.reviewer_id, t.tester_id, t.waiting_on_user_id)
                   OR p_is_admin
                   OR (t.team_id IS NULL AND p_is_triager))
            ORDER BY t.key = upper(btrim(p_query)) DESC,
                     ts_rank(t.search, websearch_to_tsquery('simple', public.tkt_unaccent(p_query))) * 0.6
                         + similarity(t.title, p_query) * 0.4 DESC,
                     t.id DESC
            LIMIT 1000
            $resolver$
            SQL);
        // Before the owner change, as in Version20261106140000: the migrator does not inherit pinround_resolver's rights.
        $this->addSql(\sprintf('REVOKE EXECUTE ON FUNCTION %s FROM PUBLIC', self::SIGNATURE));
        $this->addSql(\sprintf('GRANT EXECUTE ON FUNCTION %s TO pinround_app', self::SIGNATURE));
        $this->addSql('GRANT CREATE ON SCHEMA public TO pinround_resolver');
        $this->addSql(\sprintf('ALTER FUNCTION %s OWNER TO pinround_resolver', self::SIGNATURE));
        $this->addSql('REVOKE CREATE ON SCHEMA public FROM pinround_resolver');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SET ROLE pinround_resolver');
        $this->addSql(\sprintf('DROP FUNCTION %s', self::SIGNATURE));
        $this->addSql('RESET ROLE');
        $this->addSql('DROP POLICY resolver_read ON tkt_ticket');
        $this->addSql(\sprintf('REVOKE SELECT (%s) ON tkt_ticket FROM pinround_resolver', implode(', ', self::READ_COLUMNS)));
    }
}
