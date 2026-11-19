<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Doctrine\TenantSetting;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The plans of search_ticket_ids (data-model.md › Query patterns) over 50,000 tickets of one organization, with the
 * visibility predicate: it reads tkt_ticket through the GIN indexes, never a sequential scan (ADR-0022 › Confirmation).
 *
 * @@, % and similarity() are not LEAKPROOF, so under row-level security the planner skips those indexes; ticket search
 * and the duplicate candidates of Report a problem therefore both run in this SECURITY DEFINER function, owned by
 * pinround_resolver, whose policy on tkt_ticket is USING (true).
 *
 * The titles combine 17 subjects, 13 problems and 11 places, so each word sits in a few percent of the tickets, as in
 * an organization that has reported the same screens for years. 70% of the tickets are closed and were filed against
 * the old app, which their titles say. Every sixth ticket belongs to the Bakery team's project BAKE and only its team
 * may see it, which leaves out Omar; the Shop team's own team-only tickets he sees. Lena leads both teams.
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
    /** The key of the sequential scans in scansOfTicketTable(), which no index name can take. */
    private const string SEQUENTIAL_SCANS = 'sequential scans';
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
            $migrator->executeStatement(
                "INSERT INTO org_team_member (organization_id, team_id, user_id, team_role, job, created_at) VALUES (?, ?, ?, 'lead', 'developer', '2027-03-15 10:00:00+00')",
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

    /**
     * Omar is in the Shop team, so its team-only tickets (a number divisible by 3) are among his matches; Bakery's
     * never are.
     */
    #[Test]
    public function aSearchReadsTicketsThroughTheSearchIndexInsideTheFunction(): void
    {
        [$rows, $scans, $plan] = $this->searchAsOmar('invoice safari');

        self::assertGreaterThan(20, \count($rows));
        foreach (\array_slice($rows, 0, 20) as [, $title]) {
            self::assertStringStartsWith('Invoice PDF', $title);
            self::assertStringContainsString(' in Safari', $title);
        }
        self::assertNotSame([], array_filter($rows, static fn (array $row): bool => 0 === self::shopNumberOf($row[0]) % 3));
        foreach ($rows as [$key]) {
            self::assertStringStartsWith('SHOP-', $key);
        }
        self::assertReadsTicketsThroughIndexes($plan, $scans, ['tkt_ticket_search_idx' => 'search @@']);
    }

    #[Test]
    public function aTitleWithTyposReadsTicketsThroughTheTrigramIndexInsideTheFunction(): void
    {
        [$rows, $scans, $plan] = $this->searchAsOmar('Invocie PDF is hiden in Safary');

        self::assertNotSame([], $rows);
        self::assertSame('Invoice PDF is hidden in Safari', $rows[0][1]);
        foreach ($rows as [$key]) {
            self::assertStringStartsWith('SHOP-', $key);
        }
        self::assertReadsTicketsThroughIndexes($plan, $scans, ['tkt_ticket_title_trgm_idx' => 'title % ']);
    }

    /**
     * Report a problem's duplicate candidates call the function as IntakeCandidateQueries::similarOpenTickets does: no
     * project and only the open steps, so the step list must not steer the planner away from either GIN index.
     */
    #[Test]
    public function duplicateCandidatesOnTheOpenStepsReadTicketsThroughBothIndexesInsideTheFunction(): void
    {
        [$rows, $scans, $plan] = $this->searchAs(self::OMAR, [self::SHOP_TEAM], 'Café menu crashes on Android', '{'.ShopTeamRows::TO_DO_STEP.'}');

        self::assertNotSame([], $rows);
        self::assertSame('Café menu crashes the app on Android', $rows[0][1]);
        foreach ($rows as [$key, $title]) {
            self::assertStringStartsWith('SHOP-', $key);
            self::assertStringNotContainsString(self::CLOSED_MARK, $title);
        }
        self::assertReadsTicketsThroughIndexes($plan, $scans, ['tkt_ticket_search_idx' => 'search @@', 'tkt_ticket_title_trgm_idx' => 'title % ']);
    }

    #[Test]
    public function aKeyInAnyCaseFindsItsTicketFirstThroughTheKeyIndex(): void
    {
        [$rows, $scans, $plan] = $this->searchAsOmar(' shop-41 ');

        self::assertNotSame([], $rows);
        self::assertSame('SHOP-41', $rows[0][0]);
        self::assertReadsTicketsThroughIndexes($plan, $scans, ['tkt_ticket_organization_id_key_key' => 'key = ']);
    }

    /**
     * BAKE-42 is team-only in Bakery: Omar finds nothing by its key, Lena, who leads Bakery, finds it first.
     */
    #[Test]
    public function aKeyOfATicketTheViewerMayNotSeeFindsNothing(): void
    {
        [$rowsOfOmar] = $this->searchAsOmar('bake-42');
        [$rowsOfLena] = $this->searchAs(self::LENA, [self::SHOP_TEAM, self::BAKERY_TEAM], 'bake-42');

        self::assertSame([], $rowsOfOmar);
        self::assertNotSame([], $rowsOfLena);
        self::assertSame('BAKE-42', $rowsOfLena[0][0]);
    }

    /**
     * About 7,000 tickets mention checkout, a sixth of them Bakery's, which Omar may not see. Were the cap taken before
     * the visibility condition, those would take places among the best 1,000 and leave fewer for him. The function
     * returns one past the cap, which says the cap was hit.
     */
    #[Test]
    public function aCommonWordGivesTheViewerTheCapOfTicketsHeMaySee(): void
    {
        [$rows] = $this->searchAsOmar('checkout');

        self::assertCount(1_001, $rows);
        foreach ($rows as [$key]) {
            self::assertStringStartsWith('SHOP-', $key);
        }
    }

    /**
     * Two proofs, as EXPLAIN cannot look inside a function call. The plan: the function's own body, read from pg_proc,
     * explained as pinround_resolver with its search_path and Omar's tenant, which is how the function runs. The call:
     * the scans the real call made, counted by the statistics of the transaction, so a plan the function picks at run
     * time cannot differ unnoticed.
     *
     * @param array<string, int>    $scans                the scans of the call, from scansOfTicketTable()
     * @param array<string, string> $operatorsByIndexName the operator, as EXPLAIN prints it, that each index must serve
     */
    private static function assertReadsTicketsThroughIndexes(string $plan, array $scans, array $operatorsByIndexName): void
    {
        self::assertSame([], self::unusedIndexNamesIn($plan, $operatorsByIndexName), $plan);
        self::assertStringNotContainsString('Seq Scan on tkt_ticket', $plan, $plan);
        self::assertSame(0, $scans[self::SEQUENTIAL_SCANS], $plan);
        foreach (array_keys($operatorsByIndexName) as $indexName) {
            self::assertGreaterThan(0, $scans[$indexName] ?? 0, $indexName);
        }
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
     * @return array{list<array{string, string}>, array<string, int>, string} as searchAs
     */
    private function searchAsOmar(string $query): array
    {
        return $this->searchAs(self::OMAR, [self::SHOP_TEAM], $query);
    }

    /**
     * search_ticket_ids called as pinround_app under Acme Foods with the viewer's visibility, in one rolled-back
     * transaction, and the plan of its body for the same arguments.
     *
     * @param list<string> $teamIds the teams the viewer's TicketVisibility gives, in id order
     * @param ?string      $stepIds the p_step_ids array literal, as '{<uuid>,…}', or null for every step
     *
     * @return array{list<array{string, string}>, array<string, int>, string} the key and title of each ticket found,
     *                                                                        best first, the scans of tkt_ticket the
     *                                                                        call made, and the plan
     */
    private function searchAs(string $viewerId, array $teamIds, string $query, ?string $stepIds = null): array
    {
        $arguments = [...$this->searchArgumentsOf($viewerId, $teamIds), 'query' => $query, 'steps' => $stepIds];
        [$rows, $scans] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($arguments): array {
            $connection = $this->connection();
            self::assertSame(['pinround_app', 'on'], [$connection->fetchOne('SELECT current_user'), $connection->fetchOne('SHOW row_security')]);

            $scansBefore = self::scansOfTicketTable($connection);
            $ticketIds = $connection->fetchFirstColumn(
                'SELECT ticket_id FROM search_ticket_ids(CAST(:org AS uuid), CAST(:query AS text), CAST(:me AS uuid),
                     CAST(:teams AS uuid[]), CAST(:is_admin AS boolean), CAST(:is_triager AS boolean), NULL, CAST(:steps AS uuid[]), NULL)',
                $arguments,
            );
            $scansAfter = self::scansOfTicketTable($connection);

            $ticketsById = [];
            foreach ($connection->fetchAllAssociative(
                'SELECT id, key, title FROM tkt_ticket WHERE organization_id = CAST(:org AS uuid) AND id = ANY(CAST(:ids AS uuid[]))',
                ['org' => self::ACME_FOODS, 'ids' => '{'.implode(',', array_map(self::textOf(...), $ticketIds)).'}'],
            ) as $ticket) {
                $ticketsById[self::textOf($ticket['id'])] = [self::textOf($ticket['key']), self::textOf($ticket['title'])];
            }
            self::assertCount(\count($ticketIds), $ticketsById, 'The function returned an id twice or one the viewer cannot read.');
            $scansOfTheCall = [];
            foreach ($scansAfter as $scanned => $count) {
                $scansOfTheCall[$scanned] = $count - ($scansBefore[$scanned] ?? 0);
            }

            return [array_map(static fn (mixed $ticketId): array => $ticketsById[self::textOf($ticketId)], $ticketIds), $scansOfTheCall];
        });

        return [$rows, $scans, self::planOfTheSearchFunctionBody($arguments)];
    }

    /**
     * The arguments of search_ticket_ids that the viewer's TicketVisibility gives, read under Acme Foods as a request
     * would; the viewer is neither admin nor triager.
     *
     * @param list<string> $teamIds
     *
     * @return array{org: string, me: string, teams: string, is_admin: string, is_triager: string}
     */
    private function searchArgumentsOf(string $viewerId, array $teamIds): array
    {
        $visibility = $this->transactions()->rolledBackAs(
            self::ACME_FOODS,
            fn (): TicketVisibility => $this->ticketVisibilities()->of(new OrganizationId(self::ACME_FOODS), new UserId($viewerId)),
        );
        $arguments = [
            'org' => $visibility->organizationId()->toString(),
            'me' => $visibility->viewerId()->toString(),
            'teams' => '{'.implode(',', array_map(static fn (TeamId $teamId): string => $teamId->toString(), $visibility->teamIds())).'}',
            'is_admin' => $visibility->isAdmin() ? 'true' : 'false',
            'is_triager' => $visibility->isTriager() ? 'true' : 'false',
        ];
        self::assertSame(['{'.implode(',', $teamIds).'}', 'false', 'false'], [$arguments['teams'], $arguments['is_admin'], $arguments['is_triager']]);

        return $arguments;
    }

    /**
     * The scans of tkt_ticket so far in this transaction, sequential ones under SEQUENTIAL_SCANS and those of each
     * index under its name. pg_stat_get_xact_numscans counts what this backend has not reported yet, so a call's own
     * scans are the difference between a count before it and one after.
     *
     * @return array<string, int>
     */
    private static function scansOfTicketTable(Connection $connection): array
    {
        $scans = [];
        foreach ($connection->fetchAllAssociative(
            "SELECT '".self::SEQUENTIAL_SCANS."' AS scanned, pg_stat_get_xact_numscans('public.tkt_ticket'::regclass) AS scans
             UNION ALL
             SELECT i.indexrelid::regclass::text, pg_stat_get_xact_numscans(i.indexrelid)
             FROM pg_index i WHERE i.indrelid = 'public.tkt_ticket'::regclass",
        ) as $row) {
            self::assertIsInt($row['scans']);
            $scans[self::textOf($row['scanned'])] = $row['scans'];
        }

        return $scans;
    }

    /**
     * The body of search_ticket_ids as pg_proc holds it, with the arguments in place of its p_ inputs, explained as
     * the function runs: as pinround_resolver, with the function's search_path, under Acme Foods.
     *
     * @param array{org: string, me: string, teams: string, is_admin: string, is_triager: string, query: string, steps: ?string} $arguments
     */
    private static function planOfTheSearchFunctionBody(array $arguments): string
    {
        $migrator = self::migrator();
        $literals = [
            'p_organization_id' => 'CAST('.$migrator->quote($arguments['org']).' AS uuid)',
            'p_query' => 'CAST('.$migrator->quote($arguments['query']).' AS text)',
            'p_viewer_id' => 'CAST('.$migrator->quote($arguments['me']).' AS uuid)',
            'p_team_ids' => 'CAST('.$migrator->quote($arguments['teams']).' AS uuid[])',
            'p_is_admin' => 'CAST('.$migrator->quote($arguments['is_admin']).' AS boolean)',
            'p_is_triager' => 'CAST('.$migrator->quote($arguments['is_triager']).' AS boolean)',
            'p_project_id' => 'CAST(NULL AS uuid)',
            'p_step_ids' => null === $arguments['steps'] ? 'CAST(NULL AS uuid[])' : 'CAST('.$migrator->quote($arguments['steps']).' AS uuid[])',
            'p_assignee_id' => 'CAST(NULL AS uuid)',
        ];
        $body = $migrator->fetchOne("SELECT prosrc FROM pg_proc WHERE oid = 'public.search_ticket_ids(uuid, text, uuid, uuid[], boolean, boolean, uuid, uuid[], uuid)'::regprocedure");
        self::assertIsString($body);
        $sql = preg_replace_callback(
            '/\bp_[a-z_]+\b/',
            static fn (array $input): string => $literals[$input[0]] ?? self::fail('The body names an unknown input '.$input[0].'.'),
            $body,
        );
        self::assertIsString($sql);

        $migrator->beginTransaction();
        try {
            $migrator->executeStatement('SET LOCAL ROLE pinround_resolver');
            $migrator->executeStatement('SET LOCAL search_path = pg_catalog, public, pg_temp');
            $migrator->fetchOne('SELECT set_config(?, ?, true)', [TenantSetting::NAME, self::ACME_FOODS]);

            return self::planOf($migrator, $sql, []);
        } finally {
            $migrator->rollBack();
        }
    }

    private static function textOf(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private static function shopNumberOf(string $key): int
    {
        self::assertMatchesRegularExpression('/^SHOP-\d+\z/', $key);

        return (int) substr($key, \strlen('SHOP-'));
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
