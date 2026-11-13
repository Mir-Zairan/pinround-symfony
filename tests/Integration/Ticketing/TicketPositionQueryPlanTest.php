<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\TicketPosition;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The plan of the ticket page's position (TicketQueries::positionOf) over 10,000 tickets of one organization, as
 * pinround_app under row-level security: it finds the ticket by its key and reads its neighbours and counts through
 * an index, never a sequential scan of tkt_ticket (performance-and-scale.md). The data is TicketListQueryPlanTest's:
 * Omar is a member of the Shop team; 70% of the tickets are in Done, the open ones in four core steps; Beta Bakery's
 * 40,000 tickets share the table, and the class vacuums it first, so every run plans the same table.
 */
final class TicketPositionQueryPlanTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000076a1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000076c1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000076d1';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000076b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000076b2';
    private const string IN_PROGRESS_STEP = '0197a3c0-0000-7000-8000-0000000076f1';
    private const string CODE_REVIEW_STEP = '0197a3c0-0000-7000-8000-0000000076f2';
    private const string NEEDS_INPUT_STEP = '0197a3c0-0000-7000-8000-0000000076f3';
    private const string DONE_STEP = '0197a3c0-0000-7000-8000-0000000076f0';
    private const int TICKET_COUNT = 10_000;
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000077a2';
    private const string BAKERY_TEAM = '0197a3c0-0000-7000-8000-0000000077c2';
    private const string BAKERY_PROJECT = '0197a3c0-0000-7000-8000-0000000077d2';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000077b2';
    private const int OTHER_ORGANIZATION_TICKET_COUNT = 40_000;
    private const string SEARCH_TEXT = 'refund';
    /** Number 5015: number % 20 = 15 puts it in To do, number % 40 = 15 makes it match the search. */
    private const string TO_DO_TICKET = 'SHOP-5015';
    /** Number 5001 is in Done, the step of 7,000 tickets. */
    private const string DONE_TICKET = 'SHOP-5001';
    /** Number 5018 is in Code review; Needs input, the step before it, holds 500. */
    private const string CODE_REVIEW_TICKET = 'SHOP-5018';
    private const string SEQUENTIAL_SCANS = 'sequential';
    private const string INDEX_SCANS = 'index';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::removeRows();
        self::migrator()->executeStatement('VACUUM tkt_ticket, wf_step');
        self::shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
        ]);
        self::shopTeamRows()->add(self::BETA_BAKERY, self::BAKERY_TEAM, self::BAKERY_PROJECT, self::BEN, [
            self::BEN => ['Ben', 'lead', 'developer'],
        ]);
        self::asTenantMigrator(self::BETA_BAKERY, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at)
             SELECT CAST(? AS uuid), CAST('0197a3c0-0000-7000-8008-' || lpad(to_hex(number), 12, '0') AS uuid), CAST(? AS uuid), CAST(? AS uuid),
                 number, 'SHOP-' || number, 'Bread slicer is jammed', '', 'bug', 'normal', 3, CAST(? AS uuid), CAST(? AS uuid), 'all', '{}', '{}',
                 TIMESTAMPTZ '2027-03-15 08:00:00+00', TIMESTAMPTZ '2027-03-15 08:00:00+00' + number * INTERVAL '1 minute'
             FROM generate_series(1, ?) AS number",
            [self::BETA_BAKERY, self::BAKERY_PROJECT, self::BAKERY_TEAM, ShopTeamRows::TO_DO_STEP, self::BEN, self::OTHER_ORGANIZATION_TICKET_COUNT],
        ));
        // Of every 20 tickets 14 are in Done, 3 in To do and 1 in each of In progress, Code review and Needs input; 2 of
        // every 40 match the search "refund", 250 in To do and 250 in In progress.
        self::asTenantMigrator(self::ACME_FOODS, static function (Connection $migrator): void {
            foreach ([
                [self::IN_PROGRESS_STEP, 'In progress', 'doing', 'progress', 2],
                [self::CODE_REVIEW_STEP, 'Code review', 'check', 'review', 3],
                [self::NEEDS_INPUT_STEP, 'Needs input', 'wait', 'input', 5],
                [self::DONE_STEP, 'Done', 'done', 'done', 7],
            ] as $step) {
                $migrator->executeStatement(
                    "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, ?, ?, ?, 'core', ?)",
                    [self::ACME_FOODS, ...$step],
                );
            }
            $migrator->executeStatement(
                "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                     step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at)
                 SELECT CAST(? AS uuid), CAST('0197a3c0-0000-7000-8007-' || lpad(to_hex(number), 12, '0') AS uuid), CAST(? AS uuid), CAST(? AS uuid),
                     number, 'SHOP-' || number,
                     CASE WHEN number % 40 IN (15, 17) THEN 'Refund email is late' ELSE 'Checkout button is hidden' END, '', 'bug',
                     (ARRAY['blocker', 'critical', 'major', 'normal', 'minor'])[number % 5 + 1], number % 5,
                     CASE WHEN number % 20 < 14 THEN CAST(? AS uuid) WHEN number % 20 < 17 THEN CAST(? AS uuid)
                         WHEN number % 20 = 17 THEN CAST(? AS uuid) WHEN number % 20 = 18 THEN CAST(? AS uuid) ELSE CAST(? AS uuid) END,
                     CAST(? AS uuid), CASE WHEN number % 3 = 0 THEN 'team' ELSE 'all' END, '{}', '{}',
                     TIMESTAMPTZ '2027-03-15 08:00:00+00', TIMESTAMPTZ '2027-03-15 08:00:00+00' + number * INTERVAL '1 minute'
                 FROM generate_series(1, ?) AS number",
                [
                    self::ACME_FOODS, self::SHOP_PROJECT, self::SHOP_TEAM, self::DONE_STEP, ShopTeamRows::TO_DO_STEP, self::IN_PROGRESS_STEP,
                    self::CODE_REVIEW_STEP, self::NEEDS_INPUT_STEP, self::LENA, self::TICKET_COUNT,
                ],
            );
        });
        self::migrator()->executeStatement('ANALYZE tkt_ticket, wf_step');
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
    public function aPositionInThePlainOpenListReadsTicketsThroughAnIndex(): void
    {
        [$plan, $scans, $position] = $this->planAndScansOf(self::TO_DO_TICKET, null, false, null);

        $this->assertReadsTicketsThroughAnIndex($plan, $scans);
        self::assertTrue($position->isTotalCapped, 'To do holds 1,500 tickets, past the cap.');
    }

    /**
     * Done holds 7,000 of the 10,000 tickets, the step most likely to tempt the planner into a sequential scan.
     */
    #[Test]
    public function aPositionInDoneOfOneProjectReadsTicketsThroughAnIndex(): void
    {
        [$plan, $scans] = $this->planAndScansOf(self::DONE_TICKET, self::SHOP_PROJECT, true, null);

        $this->assertReadsTicketsThroughAnIndex($plan, $scans);
    }

    #[Test]
    public function aPositionNextToAnEarlierStepReadsTicketsThroughAnIndex(): void
    {
        [$plan, $scans, $position] = $this->planAndScansOf(self::CODE_REVIEW_TICKET, null, false, null);

        $this->assertReadsTicketsThroughAnIndex($plan, $scans);
        self::assertNotNull($position->previousKey);
    }

    /**
     * The plan shows search_ticket_ids only as a Function Scan, so the scans its body made are counted from the
     * statistics too.
     */
    #[Test]
    public function aSearchPositionReadsTicketsThroughAnIndexInsideTheFunctionAndInTheJoinBack(): void
    {
        [$plan, $scans] = $this->planAndScansOf(self::TO_DO_TICKET, null, false, self::SEARCH_TEXT);

        $this->assertReadsTicketsThroughAnIndex($plan, $scans);
    }

    /**
     * @param array{sequential: int, index: int} $scans
     */
    private function assertReadsTicketsThroughAnIndex(string $plan, array $scans): void
    {
        self::assertMatchesRegularExpression('/(Index Scan|Index Only Scan|Bitmap Index Scan) (Backward )?using tkt_ticket_\w+ on tkt_ticket|Bitmap Index Scan on tkt_ticket_/', $plan, $plan);
        self::assertStringNotContainsString('Seq Scan on tkt_ticket', $plan, $plan);
        self::assertSame(0, $scans[self::SEQUENTIAL_SCANS], $plan);
        self::assertGreaterThan(0, $scans[self::INDEX_SCANS], $plan);
    }

    /**
     * The position read as Omar's ticket page reads it, as pinround_app under Acme Foods, and its SQL explained in the
     * same transaction.
     *
     * @return array{string, array{sequential: int, index: int}, TicketPosition} the plan, the scans of tkt_ticket the
     *                                                                           read made, and the position it found
     */
    private function planAndScansOf(string $key, ?string $projectId, bool $showsDone, ?string $searchText): array
    {
        $debugData = $this->debugData();

        return $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($debugData, $key, $projectId, $showsDone, $searchText): array {
            $connection = $this->connection();
            self::assertSame(['pinround_app', 'on'], [$connection->fetchOne('SELECT current_user'), $connection->fetchOne('SHOW row_security')]);
            $visibility = $this->ticketVisibilities()->of(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR));
            $scansBefore = self::scansOfTicketTable($connection);
            $debugData->reset();
            $position = $this->ticketQueries()->positionOf($key, new ListFilter($visibility, $projectId, $showsDone, $searchText));
            $scansAfter = self::scansOfTicketTable($connection);
            self::assertNotNull($position, 'The ticket has no position, so its plan proves little.');
            [$sql, $parameters] = self::positionQueryOf($debugData);

            $planLines = $connection->fetchFirstColumn('EXPLAIN '.$sql, $parameters);

            return [
                implode("\n", array_map(static fn (mixed $line): string => \is_string($line) ? $line : '', $planLines)),
                [
                    self::SEQUENTIAL_SCANS => $scansAfter[self::SEQUENTIAL_SCANS] - $scansBefore[self::SEQUENTIAL_SCANS],
                    self::INDEX_SCANS => $scansAfter[self::INDEX_SCANS] - $scansBefore[self::INDEX_SCANS],
                ],
                $position,
            ];
        });
    }

    /**
     * The scans of tkt_ticket so far in this transaction, a SECURITY DEFINER function's included, as
     * TicketListQueryPlanTest reads them.
     *
     * @return array{sequential: int, index: int}
     */
    private static function scansOfTicketTable(Connection $connection): array
    {
        $scans = $connection->fetchAssociative(
            "SELECT pg_stat_get_xact_numscans('public.tkt_ticket'::regclass) AS sequential,
                 (SELECT sum(pg_stat_get_xact_numscans(i.indexrelid)) FROM pg_index i WHERE i.indrelid = 'public.tkt_ticket'::regclass)::bigint AS index",
        );
        self::assertIsArray($scans);
        self::assertIsInt($scans[self::SEQUENTIAL_SCANS]);
        self::assertIsInt($scans[self::INDEX_SCANS]);

        return [self::SEQUENTIAL_SCANS => $scans[self::SEQUENTIAL_SCANS], self::INDEX_SCANS => $scans[self::INDEX_SCANS]];
    }

    /**
     * The one captured query that reads the tickets, which is the position's.
     *
     * @return array{string, array<string, mixed>}
     */
    private static function positionQueryOf(DebugDataHolder $debugData): array
    {
        $positionQueries = [];
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            self::assertIsArray($queriesOfOneConnection);
            foreach ($queriesOfOneConnection as $query) {
                self::assertIsArray($query);
                if (\is_string($query['sql'] ?? null) && str_contains($query['sql'], 'tkt_ticket AS ticket')) {
                    self::assertIsArray($query['params']);

                    /** @var array<string, mixed> $parameters */
                    $parameters = $query['params'];
                    $positionQueries[] = [$query['sql'], $parameters];
                }
            }
        }
        self::assertCount(1, $positionQueries, 'The position must read the tickets in one query.');

        return $positionQueries[0];
    }

    /**
     * @param \Closure(Connection): mixed $work
     */
    private static function asTenantMigrator(string $organizationId, \Closure $work): void
    {
        self::tenantContext()->set(new OrganizationId($organizationId));
        try {
            self::migrator()->transactional($work);
        } finally {
            self::tenantContext()->clear();
        }
    }

    private function ticketQueries(): TicketQueries
    {
        $ticketQueries = self::getContainer()->get('test.ticketing_ticket_queries');
        \assert($ticketQueries instanceof TicketQueries);

        return $ticketQueries;
    }

    private function ticketVisibilities(): TicketVisibilities
    {
        $ticketVisibilities = self::getContainer()->get('test.ticketing_ticket_visibilities');
        \assert($ticketVisibilities instanceof TicketVisibilities);

        return $ticketVisibilities;
    }

    private function debugData(): DebugDataHolder
    {
        return self::getContainer()->get('doctrine.debug_data_holder');
    }

    private static function removeRows(): void
    {
        self::shopTeamRows()->removeAllOf([self::LENA, self::OMAR, self::BEN], self::ACME_FOODS, self::BETA_BAKERY);
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
