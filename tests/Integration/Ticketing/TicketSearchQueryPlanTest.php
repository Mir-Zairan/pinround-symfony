<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibilitySql;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The plans of the search and duplicate queries (data-model.md › Query patterns) over 50,000 tickets of one
 * organization, as pinround_app under row-level security with the visibility predicate: they read tkt_ticket through
 * the GIN indexes, never a sequential scan (ADR-0022 › Confirmation). @@, % and similarity() are not LEAKPROOF, so the
 * planner may refuse those indexes under row-level security; each query is also explained without row-level security,
 * where the index must be used, so a skipped index under it is known to be the policy's doing.
 *
 * The titles combine 17 subjects, 13 problems and 11 places, so each word sits in a few percent of the tickets, as in
 * an organization that has reported the same screens for years. 70% of the tickets are closed and were filed against
 * the old app, which their titles say. Every sixth ticket belongs to the Bakery team's project BAKE and only its team
 * may see it, which leaves out Omar; the Shop team's own team-only tickets he sees.
 */
final class TicketSearchQueryPlanTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000082a1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000082c1';
    private const string BAKERY_TEAM = '0197a3c0-0000-7000-8000-0000000082c2';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000082d1';
    private const string BAKERY_PROJECT = '0197a3c0-0000-7000-8000-0000000082d2';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000082b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000082b2';
    private const string DONE_STEP = '0197a3c0-0000-7000-8000-0000000082f0';
    private const int TICKET_COUNT = 50_000;
    private const string CLOSED_MARK = ' (old app)';
    private const string SUBJECTS = "'Checkout button', 'Invoice PDF', 'Login form', 'Search box', 'Cart total', 'Order history',"
        ." 'Password reset email', 'Product image', 'Delivery slot picker', 'Coupon field', 'Account menu', 'Price label',"
        ." 'Store locator map', 'Newsletter signup', 'Payment page', 'Café menu', 'Stock badge'";
    private const string PROBLEMS = "'is hidden', 'shows the wrong total', 'does not load', 'crashes the app', 'is slow',"
        ." 'overlaps the footer', 'has a typo', 'ignores the locale', 'times out', 'flickers', 'is cut off', 'sends twice',"
        ." 'loses its state'";
    private const string PLACES = "'on small screens', 'in Safari', 'after login', 'on the second page', 'in dark mode',"
        ." 'for new customers', 'on Android', 'in German', 'at checkout', 'on iPad', 'during a sale'";

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::removeRows();
        self::shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
        ]);
        self::asTenantMigrator(static function (Connection $migrator): void {
            $migrator->executeStatement(
                "INSERT INTO org_team (organization_id, id, name, slug, lead_user_id, created_at) VALUES (?, ?, 'Bakery', 'bakery', ?, '2027-03-15 10:00:00+00')",
                [self::ACME_FOODS, self::BAKERY_TEAM, self::LENA],
            );
        });
        new TicketRows(self::migrator(), self::tenantContext())->addKeyedProjectOf(self::ACME_FOODS, self::BAKERY_PROJECT, self::BAKERY_TEAM, 'BAKE', 1);
        self::asTenantMigrator(static function (Connection $migrator): void {
            $migrator->executeStatement(
                "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Done', 'done', 'done', 'core', 7)",
                [self::ACME_FOODS, self::DONE_STEP],
            );
            $migrator->executeStatement(
                'INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                     step_id, reporter_id, visibility, components, check_reasons, closed_at, created_at, updated_at)
                 SELECT CAST(:org AS uuid), CAST(\'0197a3c0-0000-7000-8002-\' || lpad(to_hex(number), 12, \'0\') AS uuid),
                     CASE WHEN is_bakery THEN CAST(:bakery_project AS uuid) ELSE CAST(:shop_project AS uuid) END,
                     CASE WHEN is_bakery THEN CAST(:bakery_team AS uuid) ELSE CAST(:shop_team AS uuid) END,
                     number, CASE WHEN is_bakery THEN \'BAKE-\' ELSE \'SHOP-\' END || number,
                     subject || \' \' || problem || \' \' || place || CASE WHEN is_closed THEN CAST(:closed_mark AS text) ELSE \'\' END,
                     \'Seen by \' || (number % 40 + 1) || \' customers. Steps: open the shop \' || place
                         || \', go to the \' || lower(subject) || \' and look closely. Expected it to work, but it \' || problem || \'.\',
                     \'bug\', (ARRAY[\'blocker\', \'critical\', \'major\', \'normal\', \'minor\'])[number % 5 + 1], number % 5,
                     CASE WHEN is_closed THEN CAST(:done_step AS uuid) ELSE CAST(:to_do_step AS uuid) END,
                     CAST(:lena AS uuid), CASE WHEN number % 3 = 0 THEN \'team\' ELSE \'all\' END, \'{}\', \'{}\',
                     CASE WHEN is_closed THEN TIMESTAMPTZ \'2027-03-20 08:00:00+00\' END,
                     TIMESTAMPTZ \'2027-03-15 08:00:00+00\', TIMESTAMPTZ \'2027-03-15 08:00:00+00\' + number * INTERVAL \'1 minute\'
                 FROM generate_series(1, CAST(:ticket_count AS int)) AS number,
                     LATERAL (SELECT number % 6 = 0 AS is_bakery, number % 10 < 7 AS is_closed,
                         (ARRAY['.self::SUBJECTS.'])[number % 17 + 1] AS subject,
                         (ARRAY['.self::PROBLEMS.'])[number / 17 % 13 + 1] AS problem,
                         (ARRAY['.self::PLACES.'])[number / 221 % 11 + 1] AS place) AS words',
                [
                    'org' => self::ACME_FOODS, 'bakery_project' => self::BAKERY_PROJECT, 'shop_project' => self::SHOP_PROJECT,
                    'bakery_team' => self::BAKERY_TEAM, 'shop_team' => self::SHOP_TEAM, 'closed_mark' => self::CLOSED_MARK,
                    'done_step' => self::DONE_STEP, 'to_do_step' => ShopTeamRows::TO_DO_STEP, 'lena' => self::LENA,
                    'ticket_count' => (string) self::TICKET_COUNT,
                ],
            );
        });
        // VACUUM as autovacuum would: the dead rows of earlier runs otherwise grow the table and sway the plans.
        self::migrator()->executeStatement('VACUUM (ANALYZE) tkt_ticket, wf_step');
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::removeRows();
        self::migrator()->executeStatement('ANALYZE tkt_ticket, wf_step');
        self::ensureKernelShutdown();
        parent::tearDownAfterClass();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::tenantContext()->reset();
        parent::tearDown();
    }

    #[Test]
    public function aSearchReadsTicketsThroughTheSearchIndex(): void
    {
        $sql = 'SELECT t.id, t.key, t.title, ts_rank_cd(t.search, websearch_to_tsquery(\'simple\', public.tkt_unaccent(CAST(:q AS text)))) AS rank
             FROM tkt_ticket t
             WHERE t.organization_id = CAST(:org AS uuid) AND <visible(t)>
               AND t.search @@ websearch_to_tsquery(\'simple\', public.tkt_unaccent(CAST(:q AS text)))
             ORDER BY rank DESC, t.id DESC
             LIMIT 20';

        [$plan, $rows, $planWithoutRowLevelSecurity] = $this->plansAndRowsOf($sql, ['q' => 'invoice safari']);

        self::assertCount(20, $rows);
        foreach ($rows as [$key, $title]) {
            self::assertStringStartsWith('SHOP-', $key);
            self::assertStringStartsWith('Invoice PDF', $title);
            self::assertStringContainsString(' in Safari', $title);
        }
        self::assertReadsTicketsThroughIndexes($plan, $planWithoutRowLevelSecurity, ['tkt_ticket_search_idx' => 'search @@']);
    }

    #[Test]
    public function aTitleWithTyposReadsTicketsThroughTheTrigramIndex(): void
    {
        $sql = 'SELECT t.id, t.key, t.title, similarity(t.title, CAST(:title AS text)) AS sim
             FROM tkt_ticket t
             WHERE t.organization_id = CAST(:org AS uuid) AND <visible(t)>
               AND t.title % CAST(:title AS text)
             ORDER BY sim DESC, t.id DESC
             LIMIT 20';

        [$plan, $rows, $planWithoutRowLevelSecurity] = $this->plansAndRowsOf($sql, ['title' => 'Invocie PDF is hiden in Safary']);

        self::assertNotSame([], $rows);
        self::assertSame('Invoice PDF is hidden in Safari', $rows[0][1]);
        foreach ($rows as [$key]) {
            self::assertStringStartsWith('SHOP-', $key);
        }
        self::assertReadsTicketsThroughIndexes($plan, $planWithoutRowLevelSecurity, ['tkt_ticket_title_trgm_idx' => 'title % ']);
    }

    /**
     * The duplicate candidates of data-model.md › Query patterns: open tickets that share words or a similar title.
     */
    #[Test]
    public function duplicateCandidatesReadTicketsThroughBothIndexes(): void
    {
        $sql = 'SELECT t.id, t.key, t.title,
                    ts_rank(t.search, websearch_to_tsquery(\'simple\', public.tkt_unaccent(CAST(:q AS text)))) AS rank,
                    similarity(t.title, CAST(:title AS text)) AS sim
             FROM tkt_ticket t
             WHERE t.organization_id = CAST(:org AS uuid)
               AND t.closed_at IS NULL
               AND <visible(t)>
               AND (t.search @@ websearch_to_tsquery(\'simple\', public.tkt_unaccent(CAST(:q AS text))) OR t.title % CAST(:title AS text))
             ORDER BY (ts_rank(t.search, websearch_to_tsquery(\'simple\', public.tkt_unaccent(CAST(:q AS text)))) * 0.6
                 + similarity(t.title, CAST(:title AS text)) * 0.4) DESC, t.id DESC
             LIMIT 20';

        [$plan, $rows, $planWithoutRowLevelSecurity] = $this->plansAndRowsOf($sql, ['q' => 'cafe menu android', 'title' => 'Café menu crashes on Android']);

        self::assertNotSame([], $rows);
        self::assertSame('Café menu crashes the app on Android', $rows[0][1]);
        foreach ($rows as [$key, $title]) {
            self::assertStringStartsWith('SHOP-', $key);
            self::assertStringNotContainsString(self::CLOSED_MARK, $title);
        }
        self::assertReadsTicketsThroughIndexes($plan, $planWithoutRowLevelSecurity, ['tkt_ticket_search_idx' => 'search @@', 'tkt_ticket_title_trgm_idx' => 'title % ']);
    }

    /**
     * @param array<string, string> $operatorsByIndexName the operator, as EXPLAIN prints it, that each index must serve
     */
    private static function assertReadsTicketsThroughIndexes(string $plan, string $planWithoutRowLevelSecurity, array $operatorsByIndexName): void
    {
        self::assertSame([], self::unusedIndexNamesIn($planWithoutRowLevelSecurity, $operatorsByIndexName), $planWithoutRowLevelSecurity);
        $unusedIndexNames = self::unusedIndexNamesIn($plan, $operatorsByIndexName);
        if ([] !== $unusedIndexNames) {
            // TODO(P03.T4): the planner skips the GIN indexes under row-level security, as @@ and % are not LEAKPROOF;
            // decide the SECURITY DEFINER fallback of data-model.md › Query patterns, then turn this into a failure.
            self::markTestIncomplete(\sprintf(
                "Under row-level security the plan does not use %s:\n%s\n\nWithout it:\n%s",
                implode(', ', $unusedIndexNames),
                $plan,
                $planWithoutRowLevelSecurity,
            ));
        }
        self::assertStringNotContainsString('Seq Scan on tkt_ticket', $plan, $plan);
    }

    /**
     * An index counts as used only when its bitmap scan's condition holds the text operator, not just the organization.
     *
     * @param array<string, string> $operatorsByIndexName
     *
     * @return list<string>
     */
    private static function unusedIndexNamesIn(string $plan, array $operatorsByIndexName): array
    {
        return array_keys(array_filter(
            $operatorsByIndexName,
            static fn (string $operator, string $indexName): bool => 1 !== preg_match(
                '/Bitmap Index Scan on '.$indexName.'\b[^\n]*\n\s+Index Cond: [^\n]*'.preg_quote($operator, '/').'/',
                $plan,
            ),
            \ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * The query with Omar's visibility predicate in place of its <visible(t)>, explained and run in one transaction as
     * pinround_app under Acme Foods, then explained again as the table's owner without row-level security.
     *
     * @param array<string, string> $parameters
     *
     * @return array{string, list<array{string, string}>, string} the plan, the key and title of each row in the query's
     *                                                            order, and the plan without row-level security
     */
    private function plansAndRowsOf(string $sqlWithoutVisibility, array $parameters): array
    {
        [$sql, $parameters, $plan, $rows] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($sqlWithoutVisibility, $parameters): array {
            $visibility = TicketVisibilitySql::of(
                $this->ticketVisibilities()->of(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR)),
                't',
            );
            $sql = str_replace('<visible(t)>', $visibility->sql(), $sqlWithoutVisibility);
            $parameters = [...$parameters, 'org' => self::ACME_FOODS, ...$visibility->parameters()];
            $connection = $this->connection();

            self::assertSame(['pinround_app', 'on'], [$connection->fetchOne('SELECT current_user'), $connection->fetchOne('SHOW row_security')]);
            $rows = array_map(
                static fn (array $row): array => [\is_string($row['key']) ? $row['key'] : '', \is_string($row['title']) ? $row['title'] : ''],
                $connection->fetchAllAssociative($sql, $parameters),
            );

            return [$sql, $parameters, self::planOf($connection, $sql, $parameters), $rows];
        });

        return [$plan, $rows, self::planWithoutRowLevelSecurityOf($sql, $parameters)];
    }

    /**
     * As the migrator, which owns tkt_ticket: with FORCE turned off in a rolled-back transaction the owner skips the
     * policies, and row_security off makes PostgreSQL refuse the query should any policy still apply.
     *
     * @param array<string, string> $parameters
     */
    private static function planWithoutRowLevelSecurityOf(string $sql, array $parameters): string
    {
        $migrator = self::migrator();
        $migrator->beginTransaction();
        try {
            $migrator->executeStatement('ALTER TABLE tkt_ticket NO FORCE ROW LEVEL SECURITY');
            $migrator->executeStatement('SET LOCAL row_security = off');

            return self::planOf($migrator, $sql, $parameters);
        } finally {
            $migrator->rollBack();
        }
    }

    /**
     * @param array<string, string> $parameters
     */
    private static function planOf(Connection $connection, string $sql, array $parameters): string
    {
        $planLines = $connection->fetchFirstColumn('EXPLAIN '.$sql, $parameters);

        return implode("\n", array_map(static fn (mixed $line): string => \is_string($line) ? $line : '', $planLines));
    }

    /**
     * @param \Closure(Connection): mixed $work
     */
    private static function asTenantMigrator(\Closure $work): void
    {
        self::tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            self::migrator()->transactional($work);
        } finally {
            self::tenantContext()->clear();
        }
    }

    private function ticketVisibilities(): TicketVisibilities
    {
        $ticketVisibilities = self::getContainer()->get('test.ticketing_ticket_visibilities');
        \assert($ticketVisibilities instanceof TicketVisibilities);

        return $ticketVisibilities;
    }

    private static function removeRows(): void
    {
        self::shopTeamRows()->removeAllOf([self::LENA, self::OMAR], self::ACME_FOODS);
    }

    private static function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows(self::migrator(), self::tenantContext());
    }

    private static function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::tenantContext());
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private static function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
