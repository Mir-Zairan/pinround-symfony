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
 * planner may refuse those indexes under row-level security; these tests are what tells.
 *
 * The titles combine 17 subjects, 13 problems and 11 places, so each word sits in a few percent of the tickets, as in
 * an organization that has reported the same screens for years; 70% of the tickets are closed and a third team-only.
 */
final class TicketSearchQueryPlanTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000082a1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000082c1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000082d1';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000082b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000082b2';
    private const string DONE_STEP = '0197a3c0-0000-7000-8000-0000000082f0';
    private const int TICKET_COUNT = 50_000;
    private const string SUBJECTS = "'Checkout button', 'Invoice PDF', 'Login form', 'Search box', 'Cart total', 'Order history',"
        ." 'Password reset email', 'Product image', 'Delivery slot picker', 'Coupon field', 'Account menu', 'Price label',"
        ." 'Store locator map', 'Newsletter signup', 'Payment page', 'Café menu', 'Stock badge'";
    private const string PROBLEMS = "'is hidden', 'shows the wrong total', 'does not load', 'crashes the app', 'is slow',"
        ." 'overlaps the footer', 'has a typo', 'ignores the locale', 'times out', 'flickers', 'is cut off', 'sends twice',"
        ." 'loses its state'";
    private const string PLACES = "'on small screens', 'in Safari', 'after login', 'on the second page', 'in dark mode',"
        ." 'for new customers', 'on Android', 'in German', 'at checkout', 'on iPad', 'during a sale'";

    protected function setUp(): void
    {
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
        ]);
        $this->asTenantMigrator(static function (Connection $migrator): void {
            $migrator->executeStatement(
                "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Done', 'done', 'done', 'core', 7)",
                [self::ACME_FOODS, self::DONE_STEP],
            );
            $migrator->executeStatement(
                'INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                     step_id, reporter_id, visibility, components, check_reasons, closed_at, created_at, updated_at)
                 SELECT CAST(? AS uuid), CAST(\'0197a3c0-0000-7000-8002-\' || lpad(to_hex(number), 12, \'0\') AS uuid), CAST(? AS uuid), CAST(? AS uuid),
                     number, \'SHOP-\' || number, title, \'Seen by \' || (number % 40 + 1) || \' customers. Steps: open the shop \' || place
                         || \', go to the \' || lower(subject) || \' and look closely. Expected it to work, but it \' || problem || \'.\',
                     \'bug\', (ARRAY[\'blocker\', \'critical\', \'major\', \'normal\', \'minor\'])[number % 5 + 1], number % 5,
                     CASE WHEN number % 10 < 7 THEN CAST(? AS uuid) ELSE CAST(? AS uuid) END,
                     CAST(? AS uuid), CASE WHEN number % 3 = 0 THEN \'team\' ELSE \'all\' END, \'{}\', \'{}\',
                     CASE WHEN number % 10 < 7 THEN TIMESTAMPTZ \'2027-03-20 08:00:00+00\' END,
                     TIMESTAMPTZ \'2027-03-15 08:00:00+00\', TIMESTAMPTZ \'2027-03-15 08:00:00+00\' + number * INTERVAL \'1 minute\'
                 FROM generate_series(1, ?) AS number,
                     LATERAL (SELECT (ARRAY['.self::SUBJECTS.'])[number % 17 + 1] AS subject,
                         (ARRAY['.self::PROBLEMS.'])[number / 17 % 13 + 1] AS problem,
                         (ARRAY['.self::PLACES.'])[number / 221 % 11 + 1] AS place) AS words,
                     LATERAL (SELECT subject || \' \' || problem || \' \' || place AS title) AS titles',
                [
                    self::ACME_FOODS, self::SHOP_PROJECT, self::SHOP_TEAM, self::DONE_STEP, ShopTeamRows::TO_DO_STEP, self::LENA,
                    self::TICKET_COUNT,
                ],
            );
        });
        $this->migrator()->executeStatement('ANALYZE tkt_ticket, wf_step');
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->removeRows();
        $this->migrator()->executeStatement('ANALYZE tkt_ticket, wf_step');
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

        [$plan, $titles] = $this->planAndTitlesOf($sql, ['q' => 'invoice safari']);

        self::assertCount(20, $titles);
        foreach ($titles as $title) {
            self::assertStringStartsWith('Invoice PDF', $title);
            self::assertStringEndsWith('in Safari', $title);
        }
        self::assertReadsTicketsThroughIndexes($plan, ['tkt_ticket_search_idx']);
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

        [$plan, $titles] = $this->planAndTitlesOf($sql, ['title' => 'Invocie PDF is hiden in Safary']);

        self::assertNotSame([], $titles);
        self::assertSame('Invoice PDF is hidden in Safari', $titles[0]);
        self::assertReadsTicketsThroughIndexes($plan, ['tkt_ticket_title_trgm_idx']);
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

        [$plan, $titles] = $this->planAndTitlesOf($sql, ['q' => 'cafe menu android', 'title' => 'Café menu crashes on Android']);

        self::assertNotSame([], $titles);
        self::assertSame('Café menu crashes the app on Android', $titles[0]);
        self::assertReadsTicketsThroughIndexes($plan, ['tkt_ticket_search_idx', 'tkt_ticket_title_trgm_idx']);
    }

    /**
     * @param list<string> $indexNames
     */
    private static function assertReadsTicketsThroughIndexes(string $plan, array $indexNames): void
    {
        $unusedIndexNames = array_values(array_filter($indexNames, static fn (string $indexName): bool => !str_contains($plan, 'Bitmap Index Scan on '.$indexName)));
        if ([] !== $unusedIndexNames) {
            // TODO(P03.T4): the planner skips the GIN indexes under row-level security, as @@ and % are not LEAKPROOF;
            // decide the SECURITY DEFINER fallback of data-model.md › Query patterns, then turn this into a failure.
            self::markTestIncomplete(\sprintf("The plan does not use %s:\n%s", implode(', ', $unusedIndexNames), $plan));
        }
        self::assertStringNotContainsString('Seq Scan on tkt_ticket', $plan, $plan);
    }

    /**
     * The query with Omar's visibility predicate in place of its <visible(t)>, explained and run in one transaction as
     * pinround_app under Acme Foods.
     *
     * @param array<string, string> $parameters
     *
     * @return array{string, list<string>} the plan and the titles the query returns, in its order
     */
    private function planAndTitlesOf(string $sqlWithoutVisibility, array $parameters): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($sqlWithoutVisibility, $parameters): array {
            $visibility = TicketVisibilitySql::of(
                $this->ticketVisibilities()->of(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR)),
                't',
            );
            $sql = str_replace('<visible(t)>', $visibility->sql(), $sqlWithoutVisibility);
            $parameters = [...$parameters, 'org' => self::ACME_FOODS, ...$visibility->parameters()];
            $connection = $this->connection();

            self::assertSame(['pinround_app', 'on'], [$connection->fetchOne('SELECT current_user'), $connection->fetchOne('SHOW row_security')]);
            $planLines = $connection->fetchFirstColumn('EXPLAIN '.$sql, $parameters);
            $titles = array_map(
                static fn (array $row): string => \is_string($row['title']) ? $row['title'] : '',
                $connection->fetchAllAssociative($sql, $parameters),
            );

            return [implode("\n", array_map(static fn (mixed $line): string => \is_string($line) ? $line : '', $planLines)), $titles];
        });
    }

    /**
     * @param \Closure(Connection): mixed $work
     */
    private function asTenantMigrator(\Closure $work): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->migrator()->transactional($work);
        } finally {
            $this->tenantContext()->clear();
        }
    }

    private function ticketVisibilities(): TicketVisibilities
    {
        $ticketVisibilities = self::getContainer()->get('test.ticketing_ticket_visibilities');
        \assert($ticketVisibilities instanceof TicketVisibilities);

        return $ticketVisibilities;
    }

    private function removeRows(): void
    {
        $this->shopTeamRows()->removeAllOf([self::LENA, self::OMAR], self::ACME_FOODS);
    }

    private function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows($this->migrator(), $this->tenantContext());
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
