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
 * team, so the visibility predicate is the full one, not an admin's. A step's count reads all its visible tickets, so
 * when one step holds a large share of the table the planner rightly prefers a sequential scan; the seed spreads the
 * open tickets over many steps, as an organization of several teams has them.
 */
final class TicketListQueryPlanTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000072a1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000072c1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000072d1';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000072b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000072b2';
    private const string DONE_STEP = '0197a3c0-0000-7000-8000-0000000072f0';
    /** Open step n's id is this prefix and n in 12 hex digits. */
    private const string OPEN_STEP_ID_PREFIX = '0197a3c0-0000-7000-8002-';
    private const string FIRST_OPEN_STEP = '0197a3c0-0000-7000-8002-000000000001';
    private const int OPEN_STEP_COUNT = 40;
    private const int TICKET_COUNT = 10_000;

    protected function setUp(): void
    {
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
        ]);
        // As in an organization of several teams, most tickets are done and the open ones spread over many steps: 14 of
        // every 20 are in Done, the others in OPEN_STEP_COUNT company steps of the four open groups.
        $this->asTenantMigrator(static function (Connection $migrator): void {
            $migrator->executeStatement(
                "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Done', 'done', 'done', 'core', 0)",
                [self::ACME_FOODS, self::DONE_STEP],
            );
            $migrator->executeStatement(
                "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position)
                 SELECT CAST(? AS uuid), CAST(? || lpad(to_hex(step_number), 12, '0') AS uuid), NULL, 'Step ' || step_number,
                     (ARRAY['todo', 'doing', 'check', 'wait'])[step_number % 4 + 1], NULL, 'company', step_number
                 FROM generate_series(1, ?) AS step_number",
                [self::ACME_FOODS, self::OPEN_STEP_ID_PREFIX, self::OPEN_STEP_COUNT],
            );
            $migrator->executeStatement(
                "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                     step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at)
                 SELECT CAST(? AS uuid), CAST('0197a3c0-0000-7000-8001-' || lpad(to_hex(number), 12, '0') AS uuid), CAST(? AS uuid), CAST(? AS uuid),
                     number, 'SHOP-' || number, 'Checkout button is hidden', '', 'bug',
                     (ARRAY['blocker', 'critical', 'major', 'normal', 'minor'])[number % 5 + 1], number % 5,
                     CASE WHEN number % 20 < 14 THEN CAST(? AS uuid) ELSE CAST(? || lpad(to_hex(number / 20 % CAST(? AS int) + 1), 12, '0') AS uuid) END,
                     CAST(? AS uuid), CASE WHEN number % 3 = 0 THEN 'team' ELSE 'all' END, '{}', '{}',
                     TIMESTAMPTZ '2027-03-15 08:00:00+00', TIMESTAMPTZ '2027-03-15 08:00:00+00' + number * INTERVAL '1 minute'
                 FROM generate_series(1, ?) AS number",
                [
                    self::ACME_FOODS, self::SHOP_PROJECT, self::SHOP_TEAM, self::DONE_STEP, self::OPEN_STEP_ID_PREFIX,
                    self::OPEN_STEP_COUNT, self::LENA, self::TICKET_COUNT,
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
    public function theFirstPageReadsTicketsThroughAnIndex(): void
    {
        $this->assertReadsTicketsThroughAnIndex($this->planOf(null, null));
    }

    #[Test]
    public function oneProjectsFirstPageReadsTicketsThroughAnIndex(): void
    {
        $this->assertReadsTicketsThroughAnIndex($this->planOf(self::SHOP_PROJECT, null));
    }

    /**
     * After a cursor the step's count is a subquery over tkt_ticket of its own, which must use an index too.
     */
    #[Test]
    public function aNextPageReadsTicketsThroughAnIndex(): void
    {
        $cursor = new Cursor(self::FIRST_OPEN_STEP, 2, new \DateTimeImmutable('2027-03-17 08:00:00 UTC'), new TicketId('0197a3c0-0000-7000-8001-000000000bb8'));

        $this->assertReadsTicketsThroughAnIndex($this->planOf(null, $cursor));
    }

    private function assertReadsTicketsThroughAnIndex(string $plan): void
    {
        self::assertMatchesRegularExpression('/(Index Scan|Index Only Scan|Bitmap Index Scan) (Backward )?using tkt_ticket_\w+ on tkt_ticket|Bitmap Index Scan on tkt_ticket_/', $plan, $plan);
        self::assertStringNotContainsString('Seq Scan on tkt_ticket', $plan, $plan);
    }

    /**
     * The list's SQL and parameters as Omar's page sends them, explained in the same transaction under Acme Foods.
     */
    private function planOf(?string $projectId, ?Cursor $cursor): string
    {
        $debugData = $this->debugData();

        return $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($debugData, $projectId, $cursor): string {
            $visibility = $this->ticketVisibilities()->of(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR));
            $debugData->reset();
            $this->ticketQueries()->list(new ListFilter($visibility, $projectId), $cursor);
            [$sql, $parameters] = self::listQueryOf($debugData);

            $planLines = $this->connection()->fetchFirstColumn('EXPLAIN '.$sql, $parameters);

            return implode("\n", array_map(static fn (mixed $line): string => \is_string($line) ? $line : '', $planLines));
        });
    }

    /**
     * The one query of the captured ones that reads wf_step, which is the list's; it binds its parameters with no
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
                if (\is_string($query['sql'] ?? null) && str_contains($query['sql'], 'FROM wf_step AS step')) {
                    self::assertIsArray($query['params']);

                    /** @var array<string, mixed> $parameters */
                    $parameters = $query['params'];

                    return [$query['sql'], $parameters];
                }
            }
        }
        self::fail('The list sent no query on wf_step.');
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
