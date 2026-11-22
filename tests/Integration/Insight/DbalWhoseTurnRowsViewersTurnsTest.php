<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insight;

use App\Insight\Api\HomeQueries;
use App\Insight\Api\NeedsYouTicket;
use App\Insight\Api\TicketViewer;
use App\Insight\Api\ViewersTurns;
use App\Insight\Api\WaitingTicket;
use App\Insight\Infrastructure\Doctrine\DbalWhoseTurnRows;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\ReadsSentSql;
use App\Tests\Integration\Tenancy\OwnConnectionTransactions;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Workflow\Api\StepQueries;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The container's HomeQueries::viewersTurns, Home's "Needs you now", as pinround_app under row-level security: the
 * tickets of the viewer's own card of HomeQueries::whoseTurn, which it must count alike, in the needs-you order. The
 * waits are counted to NOW, weeks after the real clock's 2026 and in the middle of a day, so a read of the real clock or
 * a wait in part-days ranks differently.
 */
final class DbalWhoseTurnRowsViewersTurnsTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000e901';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000e902';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000e911';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000e921';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000e951';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000e952';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000e953';
    private const string BETA_BAKERY_TICKET = '0197a3c0-0000-7000-8000-00000000e9bb';
    private const int SHOWN = 10;
    private const string NOW = '2027-03-20 18:00:00 UTC';
    /** The To do step TicketRows::addOf puts every ticket in. */
    private const string TICKET_ROWS_STEP = '0197a3c0-0000-7000-8000-00000000f0d0';

    protected function setUp(): void
    {
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
            self::MIA => ['Mia', 'member', 'tester'],
        ]);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        // No ANALYZE after the rows go: statistics of an empty table let the planner pick any index; those of the plan
        // test's 600 rows keep the selective one the cheapest for a later plan check that seeds no rows of its own.
        $this->removeRows();
        parent::tearDown();
    }

    /**
     * Twelve tickets wait on Lena, added out of order; SHOP-4 and SHOP-5 tie on their wait, SHOP-5 added first. Older
     * than all of hers: SHOP-20, a team-only ticket of no team that waits on her but that she may not see; SHOP-21 and
     * SHOP-22, Omar's; SHOP-23, the Customer label's; SHOP-24, Done and assigned to her, so with no turn row; and Beta
     * Bakery's ticket, whose turn row names Lena's id.
     */
    private function addTheWaits(): void
    {
        foreach ([7, 3, 12, 1, 9, 5, 4, 11, 2, 10, 8, 6] as $number) {
            $this->addTurn(self::ACME_FOODS, $number, self::LENA, \sprintf('2027-03-%02d 06:00:00', 5 === $number ? 4 : $number), 'all');
        }
        $this->addTurn(self::ACME_FOODS, 20, self::LENA, '2027-02-01 06:00:00', 'team');
        $this->addTurn(self::ACME_FOODS, 21, self::OMAR, '2027-02-02 06:00:00', 'all');
        $this->addTurn(self::ACME_FOODS, 22, self::OMAR, '2027-03-02 06:00:00', 'all');
        $this->addTurn(self::ACME_FOODS, 23, null, '2027-02-03 06:00:00', 'all');
        $this->ticketRows()->addWithAudienceOf(self::ACME_FOODS, self::ticketIdOf(24), self::SHOP_PROJECT, 24, 'all', self::SHOP_TEAM, self::MIA, ['assignee_id' => self::LENA]);
        $this->ticketRows()->closeOf(self::ACME_FOODS, self::ticketIdOf(24));
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BETA_BAKERY_TICKET, self::SHOP_PROJECT, self::MIA, 99);
        $this->transactions()->committedAs(self::BETA_BAKERY, fn (): int|string => $this->insertTurn(
            $this->connection(),
            self::BETA_BAKERY,
            self::BETA_BAKERY_TICKET,
            self::LENA,
            '2027-01-01 06:00:00',
            'all',
        ));
    }

    /**
     * All twelve have the same base score, so the longer wait scores higher.
     */
    #[Test]
    public function twelveTicketsOfOneBaseScoreGiveTheOldestTenWaitsThenTheLowestIdAndACountOfTwelve(): void
    {
        $this->addTheWaits();

        $viewersTurns = $this->viewersTurnsOf(self::lena(), self::SHOWN);

        self::assertSame(
            [['SHOP-1', 'SHOP-2', 'SHOP-3', 'SHOP-4', 'SHOP-5', 'SHOP-6', 'SHOP-7', 'SHOP-8', 'SHOP-9', 'SHOP-10'], 12],
            [self::keysOf($viewersTurns), $viewersTurns->ticketCount],
        );
    }

    /**
     * SHOP-1 has an open question from Omar and Mia as its assignee; SHOP-2 has neither.
     */
    #[Test]
    public function eachTicketCarriesItsIdKeyTitleKindWaitStepPriorityAskerAndAssignee(): void
    {
        $this->addTheWaits();
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->connection()->executeStatement('UPDATE tkt_ticket SET asked_user_id = ?, asker_id = ? WHERE id = ?', [self::LENA, self::OMAR, self::ticketIdOf(1)]);
            $this->connection()->executeStatement('UPDATE ins_ticket_turn SET assignee_id = ? WHERE ticket_id = ?', [self::MIA, self::ticketIdOf(1)]);
        });

        $tickets = $this->viewersTurnsOf(self::lena(), 2)->tickets;

        self::assertSame(
            [
                [self::ticketIdOf(1), 'SHOP-1', 'Checkout button is hidden', 'build', '2027-03-01 06:00:00', self::TICKET_ROWS_STEP, 'major', self::OMAR, self::MIA],
                [self::ticketIdOf(2), 'SHOP-2', 'Checkout button is hidden', 'build', '2027-03-02 06:00:00', self::TICKET_ROWS_STEP, 'major', null, null],
            ],
            array_map(static fn (NeedsYouTicket $ticket): array => [
                $ticket->id->toString(),
                $ticket->key,
                $ticket->title,
                $ticket->kind,
                $ticket->waitingSince->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                $ticket->stepId,
                $ticket->priority,
                $ticket->askerId?->toString(),
                $ticket->assigneeId?->toString(),
            ], $tickets),
        );
    }

    /**
     * Each score is base_score + LEAST(whole days waited, 60) × 0.5, counted to NOW: SHOP-33 140 + 0.5 (1 day), SHOP-32
     * 50 + 0 (6 hours), then four of 40: SHOP-34 10 + 30 (200 days, capped at 60), SHOP-35 39 + 1 (2 days), SHOP-36 40
     * + 0 (its wait starts 3 days after NOW, which counts no days, not -1.5), then two of 39.5: SHOP-31 10 + 29.5 (59
     * days) and SHOP-37 38 + 1.5 (3 days and 23 hours, which count 3). Ties go to the oldest wait. Added out of order.
     */
    #[Test]
    public function theHighestScoreComesFirstAndATieGoesToTheOldestWait(): void
    {
        $now = self::now();
        foreach ([[36, 40, '+3 days'], [31, 10, '-59 days'], [37, 38, '-3 days -23 hours'], [33, 140, '-1 day'], [35, 39, '-2 days'], [32, 50, '-6 hours'], [34, 10, '-200 days']] as [$number, $baseScore, $waited]) {
            $this->addTurn(self::ACME_FOODS, $number, self::LENA, $now->modify($waited)->format('Y-m-d H:i:s'), 'all', $baseScore);
        }

        $viewersTurns = $this->viewersTurnsOf(self::lena(), self::SHOWN);

        self::assertSame(
            [['SHOP-33', 'SHOP-32', 'SHOP-34', 'SHOP-35', 'SHOP-36', 'SHOP-31', 'SHOP-37'], 7],
            [self::keysOf($viewersTurns), $viewersTurns->ticketCount],
        );
    }

    /**
     * The team-only ticket is left out for Lena only because she may not see it: as an Admin she gets it, first.
     */
    #[Test]
    public function anAdminAlsoGetsTheTeamOnlyTicketThatWaitsOnThem(): void
    {
        $this->addTheWaits();
        $lenaAsAdmin = new TicketViewer(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), [], true, false);

        $viewersTurns = $this->viewersTurnsOf($lenaAsAdmin, self::SHOWN);

        self::assertSame([['SHOP-20', 'SHOP-1'], 13], [\array_slice(self::keysOf($viewersTurns), 0, 2), $viewersTurns->ticketCount]);
    }

    /**
     * All of Lena's tickets have one base score, so the score order is the card's order of the oldest wait first.
     */
    #[Test]
    public function itAgreesWithTheViewersCardOfWhoseTurnWhenTheBaseScoresAreEqual(): void
    {
        $this->addTheWaits();

        [$viewersTurns, $card] = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            $this->queries()->viewersTurns(self::lena(), self::SHOWN, self::now()),
            $this->queries()->whoseTurn(self::lena(), null, self::LENA, null)->groups[0],
        ]);

        self::assertSame(self::LENA, $card->turnUserId?->toString());
        self::assertSame(
            [\array_slice(array_map(static fn (WaitingTicket $ticket): string => $ticket->key, $card->tickets), 0, self::SHOWN), $card->ticketCount],
            [self::keysOf($viewersTurns), $viewersTurns->ticketCount],
        );
    }

    #[Test]
    public function aViewerNothingWaitsOnGetsNoTicketsAndACountOfZero(): void
    {
        $this->addTheWaits();
        $mia = new TicketViewer(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), [], false, false);

        $viewersTurns = $this->viewersTurnsOf($mia, self::SHOWN);

        self::assertSame([[], 0], [$viewersTurns->tickets, $viewersTurns->ticketCount]);
    }

    #[Test]
    public function itRunsExactlyOneQuery(): void
    {
        $this->addTheWaits();

        [$viewersTurns, $queryCount] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $debugData = $this->debugData();
            $debugData->reset();
            $viewersTurns = $this->queries()->viewersTurns(self::lena(), self::SHOWN, self::now());

            return [$viewersTurns, self::queryCountOf($debugData)];
        });

        self::assertSame([10, 1], [\count($viewersTurns->tickets), $queryCount]);
    }

    /**
     * Beside Lena's waits, 600 tickets wait on 60 other people, analyzed so the planner knows how few rows are hers. A
     * table this small still makes a sequential scan cheapest, so it is turned off for the plan, as in
     * TicketTurnTableTest; the plan must then reach her rows through the turn-user index, not the organization alone.
     */
    #[Test]
    public function itReadsTheViewersRowsThroughTheTurnUserIndex(): void
    {
        $this->addTheWaits();
        $this->addWaitsOnOtherPeople(600, 60);

        $plan = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): string {
            $connection = $this->connection();
            self::assertSame(['pinround_app', 'on'], [$connection->fetchOne('SELECT current_user'), $connection->fetchOne('SHOW row_security')]);
            $debugData = $this->debugData();
            $debugData->reset();
            $this->queries()->viewersTurns(self::lena(), self::SHOWN, self::now());
            $queriesOfDefault = $debugData->getData()['default'] ?? null;
            self::assertIsArray($queriesOfDefault);
            $query = $queriesOfDefault[0] ?? null;
            self::assertIsArray($query);
            self::assertIsString($query['sql']);
            self::assertIsArray($query['params']);
            $connection->executeStatement('SET LOCAL enable_seqscan = off');

            /** @var array<string, mixed> $parameters */
            $parameters = $query['params'];

            return implode("\n", array_map(static fn (mixed $line): string => \is_string($line) ? $line : '', $connection->fetchFirstColumn('EXPLAIN '.$query['sql'], $parameters)));
        });

        self::assertMatchesRegularExpression('/(Index Scan|Index Only Scan|Bitmap Index Scan) (using|on) ins_ticket_turn_turn_user_id_base_score_idx/', $plan, $plan);
    }

    /**
     * The SQL and the viewer name Acme Foods while the database tenant is Beta Bakery, so only row-level security hides
     * Acme Foods' turn rows; Beta Bakery's row for Lena's id is of another organization than the SQL's.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsTurns(): void
    {
        $this->addTheWaits();
        self::assertSame(12, $this->viewersTurnsOf(self::lena(), self::SHOWN)->ticketCount);

        $viewersTurns = new OwnConnectionTransactions($this->connection(), $this->tenantContext())->rolledBackUnderAnotherContext(
            self::BETA_BAKERY,
            self::ACME_FOODS,
            fn (Connection $connection): ViewersTurns => new DbalWhoseTurnRows($connection, $this->tenantContext(), self::getContainer()->get(StepQueries::class))
                ->viewersTurnsOf(self::lena(), self::SHOWN, self::now()),
        );

        self::assertSame([[], 0], [$viewersTurns->tickets, $viewersTurns->ticketCount]);
    }

    #[Test]
    public function withNoTransactionItRefusesBeforeAnyQuery(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        $this->assertRefusedBeforeAnyQuery(
            fn (): ViewersTurns => $this->queries()->viewersTurns(self::lena(), self::SHOWN, self::now()),
            'Whose turn is read in a transaction under the organization as tenant; none is open.',
        );
    }

    #[Test]
    public function aViewerOfAnotherOrganizationThanTheTenantIsRefusedBeforeAnyQuery(): void
    {
        $lenaInBetaBakery = new TicketViewer(new OrganizationId(self::BETA_BAKERY), new UserId(self::LENA), [], true, false);

        $this->transactions()->rolledBackAs(self::ACME_FOODS, fn () => $this->assertRefusedBeforeAnyQuery(
            fn (): ViewersTurns => $this->queries()->viewersTurns($lenaInBetaBakery, self::SHOWN, self::now()),
            'Whose turn is read under the viewer\'s own organization as tenant.',
        ));
    }

    /**
     * @param \Closure(): ViewersTurns $read
     */
    private function assertRefusedBeforeAnyQuery(\Closure $read, string $message): void
    {
        $debugData = $this->debugData();
        $debugData->reset();
        try {
            $read();
            self::fail('The read must be refused.');
        } catch (\LogicException $exception) {
            self::assertSame([\LogicException::class, $message], [$exception::class, $exception->getMessage()]);
        }
        self::assertSame(0, self::queryCountOf($debugData), 'No query ran before the refusal.');
    }

    private function viewersTurnsOf(TicketViewer $viewer, int $limit): ViewersTurns
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ViewersTurns => $this->queries()->viewersTurns($viewer, $limit, self::now()));
    }

    /**
     * @return list<string>
     */
    private static function keysOf(ViewersTurns $viewersTurns): array
    {
        return array_map(static fn (NeedsYouTicket $ticket): string => $ticket->key, $viewersTurns->tickets);
    }

    /**
     * A ticket SHOP-<number> of the Shop project and its turn row, waiting on the person, or on the Customer label for
     * null; a team-only row has no team, so only its people, an Admin or the triager may see it.
     *
     * @param 'all'|'team' $visibility
     */
    private function addTurn(string $organizationId, int $number, ?string $userId, string $waitingSince, string $visibility, int $baseScore = 100): void
    {
        $ticketId = self::ticketIdOf($number);
        $this->ticketRows()->addOf($organizationId, $ticketId, self::SHOP_PROJECT, self::MIA, $number);
        $this->transactions()->committedAs($organizationId, fn (): int|string => $this->insertTurn($this->connection(), $organizationId, $ticketId, $userId, $waitingSince, $visibility, $baseScore));
    }

    /**
     * Tickets SHOP-(1000 + n) for n from 1 to $tickets, each waiting on one of $people people by turns; then the turn
     * and ticket tables are analyzed.
     */
    private function addWaitsOnOtherPeople(int $tickets, int $people): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->migrator()->transactional(static function (Connection $migrator) use ($tickets, $people): void {
                $migrator->executeStatement(
                    "INSERT INTO tkt_ticket (organization_id, id, project_id, number, key, title, description, type, priority, priority_rank,
                         step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at)
                     SELECT CAST(? AS uuid), CAST('0197a3c0-0000-7000-8000-00000eb' || lpad(to_hex(number), 5, '0') AS uuid), CAST(? AS uuid),
                         1000 + number, 'SHOP-' || (1000 + number), 'Checkout button is hidden', '', 'bug', 'major', 2, CAST(? AS uuid), CAST(? AS uuid),
                         'all', '{}', '{}', TIMESTAMPTZ '2027-03-15 10:00:00+00', TIMESTAMPTZ '2027-03-15 10:00:00+00'
                     FROM generate_series(1, ?) AS number",
                    [self::ACME_FOODS, self::SHOP_PROJECT, ShopTeamRows::TO_DO_STEP, self::MIA, $tickets],
                );
                $migrator->executeStatement(
                    "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, kind, base_score, waiting_since, visibility, reporter_id)
                     SELECT CAST(? AS uuid), CAST('0197a3c0-0000-7000-8000-00000eb' || lpad(to_hex(number), 5, '0') AS uuid), CAST(? AS uuid), CAST(? AS uuid),
                         'doing', CAST('0197a3c0-0000-7000-8000-0000000ec' || lpad(to_hex(number % ?), 3, '0') AS uuid), 'build', 100,
                         TIMESTAMPTZ '2027-02-01 06:00:00+00' + number * INTERVAL '1 minute', 'all', CAST(? AS uuid)
                     FROM generate_series(1, ?) AS number",
                    [self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, $people, self::MIA, $tickets],
                );
            });
        } finally {
            $this->tenantContext()->clear();
        }
        $this->migrator()->executeStatement('ANALYZE ins_ticket_turn, tkt_ticket');
    }

    private function insertTurn(Connection $connection, string $organizationId, string $ticketId, ?string $userId, string $waitingSince, string $visibility, int $baseScore = 100): int|string
    {
        return $connection->executeStatement(
            "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id)
             VALUES (?, ?, ?, ?, 'doing', ?, ?, 'build', ?, ?, ?, ?)",
            [
                $organizationId,
                $ticketId,
                'team' === $visibility ? null : self::SHOP_TEAM,
                self::SHOP_PROJECT,
                $userId,
                null === $userId ? 'Customer' : null,
                $baseScore,
                $waitingSince.'+00',
                $visibility,
                self::MIA,
            ],
        );
    }

    private static function ticketIdOf(int $number): string
    {
        return \sprintf('0197a3c0-0000-7000-8000-00000000ea%02d', $number);
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    /**
     * A viewer with no role that widens what she sees, so the team-only ticket stays hidden from her.
     */
    private static function lena(): TicketViewer
    {
        return new TicketViewer(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), [], false, false);
    }

    private function queries(): HomeQueries
    {
        $queries = self::getContainer()->get('test.insight_home_queries');
        \assert($queries instanceof HomeQueries);

        return $queries;
    }

    private function removeRows(): void
    {
        $this->shopTeamRows()->removeAllOf([self::LENA, self::OMAR, self::MIA], self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows($this->migrator(), $this->tenantContext());
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

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
