<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\Cursor;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The plan of the tickets list over 10,000 tickets of one organization, as pinround_app under row-level security: it
 * reads tkt_ticket through an index, never a sequential scan (performance-and-scale.md). Omar is a member of the Shop
 * team, so the visibility predicate is the full one, not an admin's. As in an organization that has worked for years,
 * 70% of the tickets are in Done and the open ones sit in four core steps, so each step holds a large share of the table.
 *
 * As in production, the table holds other organizations too: Beta Bakery's 40,000 tickets. With Acme Foods alone, a
 * sequential scan of its 10,000 rows is the cheaper plan for a step's count, and the plan then hung on how many dead
 * rows earlier test classes had left in the table; the class vacuums it first, so every run plans the same table.
 */
final class TicketListQueryPlanTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000072a1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000072c1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000072d1';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000072b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000072b2';
    private const string IN_PROGRESS_STEP = '0197a3c0-0000-7000-8000-0000000072f1';
    private const string CODE_REVIEW_STEP = '0197a3c0-0000-7000-8000-0000000072f2';
    private const string NEEDS_INPUT_STEP = '0197a3c0-0000-7000-8000-0000000072f3';
    private const string DONE_STEP = '0197a3c0-0000-7000-8000-0000000072f0';
    private const int TICKET_COUNT = 10_000;
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000073a2';
    private const string BAKERY_TEAM = '0197a3c0-0000-7000-8000-0000000073c2';
    private const string BAKERY_PROJECT = '0197a3c0-0000-7000-8000-0000000073d2';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000073b2';
    private const int OTHER_ORGANIZATION_TICKET_COUNT = 40_000;

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
             SELECT CAST(? AS uuid), CAST('0197a3c0-0000-7000-8003-' || lpad(to_hex(number), 12, '0') AS uuid), CAST(? AS uuid), CAST(? AS uuid),
                 number, 'SHOP-' || number, 'Bread slicer is jammed', '', 'bug', 'normal', 3, CAST(? AS uuid), CAST(? AS uuid), 'all', '{}', '{}',
                 TIMESTAMPTZ '2027-03-15 08:00:00+00', TIMESTAMPTZ '2027-03-15 08:00:00+00' + number * INTERVAL '1 minute'
             FROM generate_series(1, ?) AS number",
            [self::BETA_BAKERY, self::BAKERY_PROJECT, self::BAKERY_TEAM, ShopTeamRows::TO_DO_STEP, self::BEN, self::OTHER_ORGANIZATION_TICKET_COUNT],
        ));
        // Of every 20 tickets 14 are in Done, 3 in To do and 1 in each of In progress, Code review and Needs input.
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
                 SELECT CAST(? AS uuid), CAST('0197a3c0-0000-7000-8001-' || lpad(to_hex(number), 12, '0') AS uuid), CAST(? AS uuid), CAST(? AS uuid),
                     number, 'SHOP-' || number, 'Checkout button is hidden', '', 'bug',
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
    public function theFirstPageReadsTicketsThroughAnIndex(): void
    {
        $this->assertReadsTicketsThroughAnIndex($this->planOf(null, false, null));
    }

    #[Test]
    public function theFirstPageWithDoneReadsTicketsThroughAnIndex(): void
    {
        $this->assertReadsTicketsThroughAnIndex($this->planOf(null, true, null));
    }

    #[Test]
    public function oneProjectsFirstPageWithDoneReadsTicketsThroughAnIndex(): void
    {
        $this->assertReadsTicketsThroughAnIndex($this->planOf(self::SHOP_PROJECT, true, null));
    }

    /**
     * Done holds 7,000 of the 10,000 tickets, the step most likely to tempt the planner into a sequential scan.
     */
    #[Test]
    public function aNextPageOfDoneReadsTicketsThroughAnIndex(): void
    {
        $cursor = new Cursor(self::DONE_STEP, 2, new \DateTimeImmutable('2027-03-17 08:02:00 UTC'), new TicketId('0197a3c0-0000-7000-8001-000000000b42'));

        $this->assertReadsTicketsThroughAnIndex($this->planOf(null, true, $cursor));
    }

    private function assertReadsTicketsThroughAnIndex(string $plan): void
    {
        self::assertMatchesRegularExpression('/(Index Scan|Index Only Scan|Bitmap Index Scan) (Backward )?using tkt_ticket_\w+ on tkt_ticket|Bitmap Index Scan on tkt_ticket_/', $plan, $plan);
        self::assertStringNotContainsString('Seq Scan on tkt_ticket', $plan, $plan);
    }

    /**
     * The list's SQL and parameters as Omar's page sends them, explained in the same transaction under Acme Foods.
     */
    private function planOf(?string $projectId, bool $showsDone, ?Cursor $cursor): string
    {
        $debugData = $this->debugData();

        return $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($debugData, $projectId, $showsDone, $cursor): string {
            $visibility = $this->ticketVisibilities()->of(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR));
            $debugData->reset();
            $this->ticketQueries()->list(new ListFilter($visibility, $projectId, $showsDone), $cursor);
            [$sql, $parameters] = self::listQueryOf($debugData);

            $planLines = $this->connection()->fetchFirstColumn('EXPLAIN '.$sql, $parameters);

            return implode("\n", array_map(static fn (mixed $line): string => \is_string($line) ? $line : '', $planLines));
        });
    }

    /**
     * The one query of the captured ones that reads the tickets, which is the list's; it binds its parameters with no
     * types, as the SQL casts each one.
     *
     * @return array{string, array<string, mixed>}
     */
    private static function listQueryOf(DebugDataHolder $debugData): array
    {
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            self::assertIsArray($queriesOfOneConnection);
            foreach ($queriesOfOneConnection as $query) {
                self::assertIsArray($query);
                if (\is_string($query['sql'] ?? null) && str_contains($query['sql'], 'FROM tkt_ticket AS ticket')) {
                    self::assertIsArray($query['params']);

                    /** @var array<string, mixed> $parameters */
                    $parameters = $query['params'];

                    return [$query['sql'], $parameters];
                }
            }
        }
        self::fail('The list sent no query on tkt_ticket.');
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
