<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing\Intake;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\ReadsSentSql;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\IntakeCandidateQueries;
use App\Ticketing\Api\TicketVisibility;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The plan of the intake check's candidates (IntakeCandidateQueries::openCandidatesIn) as pinround_app under row-level
 * security: it reads a project's newest open tickets from tkt_ticket_project_id_updated_at_id_open_idx in their order
 * and stops at the limit, with no sort and no sequential scan (performance-and-scale.md: the intake check runs in the
 * report burst). As in an organization that has worked for years, 18,000 of SHOP's 20,000 tickets are closed, a third
 * are team-only, and their update times do not follow the order the rows were written in; the project OPS holds 20,000
 * open tickets the read must pass over. The class vacuums the table first, so every run plans the same table.
 */
final class IntakeCandidateQueryPlanTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f9a01';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000f9a03';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000f9a05';
    private const string OPS_PROJECT = '0197a3c0-0000-7000-8000-0000000f9a06';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f9a11';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f9a12';
    private const string STEP = '0197a3c0-0000-7000-8000-0000000f9a21';
    private const int TICKETS_PER_PROJECT = 20_000;
    private const string INTAKE_INDEX = 'tkt_ticket_project_id_updated_at_id_open_idx';
    private const string SEQUENTIAL_SCANS = 'sequential';
    private const string INDEX_SCANS = 'index';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::ticketRows()->removeAllOf(self::ACME_FOODS);
        self::migrator()->executeStatement('VACUUM tkt_ticket');
        self::ticketRows()->addKeyedProjectOf(self::ACME_FOODS, self::SHOP_PROJECT, self::SHOP_TEAM, 'SHOP', 1);
        self::ticketRows()->addKeyedProjectOf(self::ACME_FOODS, self::OPS_PROJECT, null, 'OPS', 1);
        self::tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            // Of every 10 SHOP tickets 9 are closed; number * 7919 spreads the update times over the minutes out of
            // the numbers' order. OPS's tickets are all open and newer than SHOP's.
            self::migrator()->transactional(static function (Connection $migrator): void {
                $migrator->executeStatement(
                    "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                         step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at, closed_at)
                     SELECT CAST(? AS uuid), CAST('0197a3c0-0000-7000-8009-' || lpad(to_hex(number), 12, '0') AS uuid), CAST(? AS uuid), CAST(? AS uuid),
                         number, 'SHOP-' || number, 'Checkout button is hidden', '', 'bug', 'major', 2, CAST(? AS uuid), CAST(? AS uuid),
                         CASE WHEN number % 3 = 0 THEN 'team' ELSE 'all' END, '{}', '{}', TIMESTAMPTZ '2027-03-01 08:00:00+00',
                         TIMESTAMPTZ '2027-03-01 08:00:00+00' + (number * 7919 % ?) * INTERVAL '1 minute',
                         CASE WHEN number % 10 = 0 THEN NULL ELSE TIMESTAMPTZ '2027-06-01 08:00:00+00' END
                     FROM generate_series(1, ?) AS number",
                    [self::ACME_FOODS, self::SHOP_PROJECT, self::SHOP_TEAM, self::STEP, self::OMAR, self::TICKETS_PER_PROJECT, self::TICKETS_PER_PROJECT],
                );
                $migrator->executeStatement(
                    "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                         step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at)
                     SELECT CAST(? AS uuid), CAST('0197a3c0-0000-7000-800a-' || lpad(to_hex(number), 12, '0') AS uuid), CAST(? AS uuid), NULL,
                         number, 'OPS-' || number, 'Freezer alarm is loud', '', 'bug', 'major', 2, CAST(? AS uuid), CAST(? AS uuid),
                         'all', '{}', '{}', TIMESTAMPTZ '2027-07-01 08:00:00+00', TIMESTAMPTZ '2027-07-01 08:00:00+00' + number * INTERVAL '1 minute'
                     FROM generate_series(1, ?) AS number",
                    [self::ACME_FOODS, self::OPS_PROJECT, self::STEP, self::OMAR, self::TICKETS_PER_PROJECT],
                );
            });
        } finally {
            self::tenantContext()->clear();
        }
        self::migrator()->executeStatement('ANALYZE tkt_ticket');
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::ticketRows()->removeAllOf(self::ACME_FOODS);
        self::migrator()->executeStatement('ANALYZE tkt_ticket');
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
     * @return iterable<string, array{bool}>
     */
    public static function teamOnlyChoices(): iterable
    {
        yield 'team-only left out' => [false];
        yield 'team-only included' => [true];
    }

    #[Test]
    #[DataProvider('teamOnlyChoices')]
    public function theCandidatesAreReadInOrderFromTheOpenTicketsIndexWithNoSortAndNoSequentialScan(bool $includeTeamOnly): void
    {
        [$plan, $scans, $candidateCount] = $this->planAndScansOf($includeTeamOnly);

        self::assertSame(IntakeCandidateQueries::CANDIDATE_LIMIT, $candidateCount, 'The read did not fill the limit, so its plan proves little.');
        self::assertMatchesRegularExpression('/Index Scan using '.self::INTAKE_INDEX.' on tkt_ticket/', $plan, $plan);
        self::assertStringNotContainsString('Sort', $plan, $plan);
        self::assertStringNotContainsString('Seq Scan on tkt_ticket', $plan, $plan);
        self::assertSame(0, $scans[self::SEQUENTIAL_SCANS], $plan);
        self::assertGreaterThan(0, $scans[self::INDEX_SCANS], $plan);
    }

    /**
     * The candidates read as Mia's intake check reads them, as pinround_app under Acme Foods, and their SQL explained
     * in the same transaction.
     *
     * @return array{string, array{sequential: int, index: int}, int} the plan, the scans of tkt_ticket the read made, and
     *                                                                how many candidates it gave
     */
    private function planAndScansOf(bool $includeTeamOnly): array
    {
        $debugData = $this->debugData();

        return $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($debugData, $includeTeamOnly): array {
            $connection = $this->connection();
            self::assertSame(['pinround_app', 'on'], [$connection->fetchOne('SELECT current_user'), $connection->fetchOne('SHOW row_security')]);
            $scansBefore = self::scansOfTicketTable($connection);
            $debugData->reset();
            $candidates = $this->queries()->openCandidatesIn(self::SHOP_PROJECT, self::miaVisibility(), $includeTeamOnly);
            $scansAfter = self::scansOfTicketTable($connection);
            [$sql, $parameters] = self::candidateQueryOf($debugData);

            $planLines = $connection->fetchFirstColumn('EXPLAIN '.$sql, $parameters);

            return [
                implode("\n", array_map(static fn (mixed $line): string => \is_string($line) ? $line : '', $planLines)),
                [
                    self::SEQUENTIAL_SCANS => $scansAfter[self::SEQUENTIAL_SCANS] - $scansBefore[self::SEQUENTIAL_SCANS],
                    self::INDEX_SCANS => $scansAfter[self::INDEX_SCANS] - $scansBefore[self::INDEX_SCANS],
                ],
                \count($candidates),
            ];
        });
    }

    /**
     * The scans of tkt_ticket so far in this transaction, as TicketListQueryPlanTest reads them.
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
     * The one captured query that reads the tickets, which is the candidates'; it binds its parameters with no types,
     * as the SQL casts each one.
     *
     * @return array{string, array<string, mixed>}
     */
    private static function candidateQueryOf(DebugDataHolder $debugData): array
    {
        $candidateQueries = [];
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            self::assertIsArray($queriesOfOneConnection);
            foreach ($queriesOfOneConnection as $query) {
                self::assertIsArray($query);
                if (\is_string($query['sql'] ?? null) && str_contains($query['sql'], 'FROM tkt_ticket t')) {
                    self::assertIsArray($query['params']);

                    /** @var array<string, mixed> $parameters */
                    $parameters = $query['params'];
                    $candidateQueries[] = [$query['sql'], $parameters];
                }
            }
        }
        self::assertCount(1, $candidateQueries, 'The candidates must be read in one query.');

        return $candidateQueries[0];
    }

    private static function miaVisibility(): TicketVisibility
    {
        return TicketVisibility::for(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), [new TeamId(self::SHOP_TEAM)], false, null);
    }

    private function queries(): IntakeCandidateQueries
    {
        $queries = self::getContainer()->get('test.ticketing_intake_candidate_queries');
        \assert($queries instanceof IntakeCandidateQueries);

        return $queries;
    }

    private static function ticketRows(): TicketRows
    {
        return new TicketRows(self::migrator(), self::tenantContext());
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
