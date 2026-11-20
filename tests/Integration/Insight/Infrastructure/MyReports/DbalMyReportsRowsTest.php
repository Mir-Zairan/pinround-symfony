<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insight\Infrastructure\MyReports;

use App\Insight\Api\HomeQueries;
use App\Insight\Api\MyReport;
use App\Insight\Api\MyReportsCursor;
use App\Insight\Api\MyReportsPage;
use App\Insight\Api\TicketViewer;
use App\Insight\Infrastructure\MyReports\DbalMyReportsRows;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\ReadsSentSql;
use App\Tests\Integration\Tenancy\OwnConnectionTransactions;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The container's HomeQueries::myReports on DbalMyReportsRows as pinround_app under row-level security, on tickets and
 * turn rows made by hand: which tickets are the viewer's reports, their order, their facts and the keyset pages.
 */
final class DbalMyReportsRowsTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e7a01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000e7a02';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000e7a11';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000e7a21';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000e7a51';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000e7a52';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000e7a53';
    /** Reports bulk-added for Lena get ids with this prefix and their n as the last four digits. */
    private const string MANY_REPORTS_PREFIX = '0197a3c0-0000-7000-8000-00000e7c';

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
        $this->removeRows();
        parent::tearDown();
    }

    /**
     * Added out of order. Lena's reports: SHOP-2, team-only in the Shop team, which her viewer here is not in; SHOP-3 and
     * SHOP-4, made at the same second, SHOP-3 (the lower id) added first; SHOP-1, Omar's turn; SHOP-5, Done with no turn
     * row; SHOP-8, the oldest, an open question to her. Not hers: SHOP-6, Omar's report for everyone and the newer of
     * his; SHOP-7, Omar's team-only report, which she can neither see nor did report; and Beta Bakery's SHOP-9, the
     * newest of all, reported by Lena's id there.
     */
    private function addTheReports(): void
    {
        $this->addReport(self::ACME_FOODS, 6, '2027-03-13 09:00:00', self::OMAR, 'all', null);
        $this->addReport(self::ACME_FOODS, 3, '2027-03-11 09:00:00', self::LENA, 'all', [self::MIA, null, 'test']);
        $this->addReport(self::ACME_FOODS, 1, '2027-03-10 09:00:00', self::LENA, 'all', [self::OMAR, null, 'build']);
        $this->addReport(self::ACME_FOODS, 8, '2027-03-08 09:00:00', self::LENA, 'all', [self::LENA, null, 'answer']);
        $this->addReport(self::ACME_FOODS, 4, '2027-03-11 09:00:00', self::LENA, 'all', [self::OMAR, null, 'answer']);
        $this->addReport(self::ACME_FOODS, 7, '2027-03-14 09:00:00', self::OMAR, 'team', null);
        $this->addReport(self::ACME_FOODS, 2, '2027-03-12 09:00:00', self::LENA, 'team', [null, 'Customer', 'input']);
        $this->addReport(self::ACME_FOODS, 5, '2027-03-09 09:00:00', self::LENA, 'all', null, '2027-03-15 09:00:00');
        $this->ticketRows()->addProjectOf(self::BETA_BAKERY, self::SHOP_PROJECT);
        $this->addReport(self::BETA_BAKERY, 9, '2027-03-20 09:00:00', self::LENA, 'all', null);
    }

    #[Test]
    public function itListsOnlyTheViewersOwnReportsTeamOnlyOnesIncludedNewestFirstThenTheHighestId(): void
    {
        $this->addTheReports();

        $page = $this->myReportsOf(self::lena(), null);

        self::assertSame(['SHOP-2', 'SHOP-4', 'SHOP-3', 'SHOP-1', 'SHOP-5', 'SHOP-8'], self::keysOf($page));
        self::assertNull($page->nextCursor);
    }

    #[Test]
    public function eachReportCarriesItsTicketFactsAndItsTurnRow(): void
    {
        $this->addTheReports();

        $reports = $this->myReportsOf(self::lena(), null)->reports;

        self::assertNotSame([], $reports);
        self::assertSame(
            [
                [self::ticketIdOf(2), 'SHOP-2', 'Cart total 2 is wrong', ShopTeamRows::TO_DO_STEP, '2027-03-12 09:00:00', '2027-03-12 10:30:00', null, 'doing', null, 'Customer', 'input', false],
                [self::ticketIdOf(4), 'SHOP-4', 'Cart total 4 is wrong', ShopTeamRows::TO_DO_STEP, '2027-03-11 09:00:00', '2027-03-11 10:30:00', null, 'doing', self::OMAR, null, 'answer', false],
                [self::ticketIdOf(3), 'SHOP-3', 'Cart total 3 is wrong', ShopTeamRows::TO_DO_STEP, '2027-03-11 09:00:00', '2027-03-11 10:30:00', null, 'doing', self::MIA, null, 'test', false],
                [self::ticketIdOf(1), 'SHOP-1', 'Cart total 1 is wrong', ShopTeamRows::TO_DO_STEP, '2027-03-10 09:00:00', '2027-03-10 10:30:00', null, 'doing', self::OMAR, null, 'build', false],
                [self::ticketIdOf(5), 'SHOP-5', 'Cart total 5 is wrong', ShopTeamRows::TO_DO_STEP, '2027-03-09 09:00:00', '2027-03-09 10:30:00', '2027-03-15 09:00:00', null, null, null, null, false],
                [self::ticketIdOf(8), 'SHOP-8', 'Cart total 8 is wrong', ShopTeamRows::TO_DO_STEP, '2027-03-08 09:00:00', '2027-03-08 10:30:00', null, 'doing', self::LENA, null, 'answer', true],
            ],
            array_map(static fn (MyReport $report): array => [
                $report->ticketId->toString(),
                $report->key,
                $report->title,
                $report->stepId,
                self::utc($report->reportedAt),
                self::utc($report->updatedAt),
                null === $report->closedAt ? null : self::utc($report->closedAt),
                $report->turnGroup,
                $report->turnUserId?->toString(),
                $report->turnLabel,
                $report->turnKind,
                $report->isQuestionToReporter,
            ], $reports),
        );
    }

    /**
     * Omar is in the Shop team, so he may see Lena's team-only SHOP-2 and all her other reports; My reports still lists
     * only the ones he reported, his team-only SHOP-7 included.
     */
    #[Test]
    public function anotherReporterGetsOnlyTheirOwnReportsNotTheOnesTheyMaySee(): void
    {
        $this->addTheReports();
        $omar = new TicketViewer(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), [new TeamId(self::SHOP_TEAM)], false, false);

        self::assertSame(['SHOP-7', 'SHOP-6'], self::keysOf($this->myReportsOf($omar, null)));
    }

    #[Test]
    public function aViewerWhoReportedNothingGetsAnEmptyPageWithNoCursor(): void
    {
        $this->addTheReports();
        $miaAsAdmin = new TicketViewer(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), [], true, false);

        $page = $this->myReportsOf($miaAsAdmin, null);

        self::assertSame([[], null], [$page->reports, $page->nextCursor]);
    }

    /**
     * Three reports share each minute, so the first page ends inside a tie (SHOP-1251 and SHOP-1250 on it, SHOP-1249
     * on the next), and they are added in a scrambled order.
     */
    #[Test]
    public function threeHundredReportsComeFiftyAPageThroughTheCursorWithNoGapAndNoRepeat(): void
    {
        $this->addManyReportsOfLena(300);

        $first = $this->myReportsOf(self::lena(), null);
        self::assertNotNull($first->nextCursor);
        $second = $this->myReportsOf(self::lena(), $first->nextCursor);

        self::assertSame(self::manyReportKeys(299, 250), self::keysOf($first));
        self::assertSame([self::manyReportIdOf(250), '2027-01-01 01:23:00'], [$first->nextCursor->ticketId->toString(), self::utc($first->nextCursor->reportedAt)]);
        self::assertSame(self::manyReportKeys(249, 200), self::keysOf($second));
    }

    #[Test]
    public function walkingEveryPageGivesAllThreeHundredReportsOnceInOrderAndTheLastPageHasNoCursor(): void
    {
        $this->addManyReportsOfLena(300);

        $keys = [];
        $pageSizes = [];
        $cursor = null;
        do {
            $page = $this->myReportsOf(self::lena(), $cursor);
            $keys = [...$keys, ...self::keysOf($page)];
            $pageSizes[] = \count($page->reports);
            $cursor = $page->nextCursor;
        } while (null !== $cursor && \count($pageSizes) < 7);

        self::assertSame([[50, 50, 50, 50, 50, 50], null], [$pageSizes, $cursor]);
        self::assertSame(self::manyReportKeys(299, 0), $keys);
    }

    #[Test]
    public function fiftyReportsAreOnePageWithNoCursorAndFiftyOneGiveACursor(): void
    {
        $this->addManyReportsOfLena(50);
        $fifty = $this->myReportsOf(self::lena(), null);
        $this->addManyReportsOfLena(1, 50);
        $fiftyOne = $this->myReportsOf(self::lena(), null);

        self::assertSame([50, null], [\count($fifty->reports), $fifty->nextCursor]);
        self::assertSame([self::manyReportKeys(50, 1), self::manyReportIdOf(1)], [self::keysOf($fiftyOne), $fiftyOne->nextCursor?->ticketId->toString()]);
    }

    #[Test]
    public function aPageWithACursorIsReadInOneQuery(): void
    {
        $this->addManyReportsOfLena(120);
        $first = $this->myReportsOf(self::lena(), null);

        [$page, $queryCount] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($first): array {
            $debugData = $this->debugData();
            $debugData->reset();
            $page = $this->queries()->myReports(self::lena(), $first->nextCursor);

            return [$page, self::queryCountOf($debugData)];
        });

        self::assertSame([self::manyReportKeys(69, 20), 1], [self::keysOf($page), $queryCount]);
    }

    /**
     * The SQL and the viewer name Acme Foods while the database tenant is Beta Bakery, so only row-level security can
     * hide Acme Foods' tickets.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsReports(): void
    {
        $this->addTheReports();
        self::assertNotSame([], $this->myReportsOf(self::lena(), null)->reports);

        $reports = new OwnConnectionTransactions($this->connection(), $this->tenantContext())->rolledBackUnderAnotherContext(
            self::BETA_BAKERY,
            self::ACME_FOODS,
            fn (Connection $connection): array => new DbalMyReportsRows($connection, $this->tenantContext())->reportsOf(self::lena(), null, 51),
        );

        self::assertSame([], $reports);
    }

    #[Test]
    public function withNoTransactionItRefusesBeforeAnyQuery(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        $this->assertRefusedBeforeAnyQuery(
            fn (): MyReportsPage => $this->queries()->myReports(self::lena(), null),
            \LogicException::class,
            'My reports are read in a transaction under the organization as tenant; none is open.',
        );
    }

    #[Test]
    public function withATransactionButNoTenantItRefusesBeforeAnyQuery(): void
    {
        $this->tenantContext()->reset();
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $this->assertRefusedBeforeAnyQuery(
                fn (): MyReportsPage => $this->queries()->myReports(self::lena(), null),
                TenantNotSet::class,
                'No organization is set for this unit of work.',
            );
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * Lena's id reported a ticket of Beta Bakery too, so a read that ran anyway would differ from none.
     */
    #[Test]
    public function aViewerOfAnotherOrganizationThanTheTenantIsRefusedBeforeAnyQuery(): void
    {
        $this->addTheReports();
        $lenaInBetaBakery = new TicketViewer(new OrganizationId(self::BETA_BAKERY), new UserId(self::LENA), [], true, false);

        $this->transactions()->rolledBackAs(self::ACME_FOODS, fn () => $this->assertRefusedBeforeAnyQuery(
            fn (): MyReportsPage => $this->queries()->myReports($lenaInBetaBakery, null),
            \LogicException::class,
            'My reports are read under the viewer\'s own organization as tenant.',
        ));
    }

    #[Test]
    public function aLimitOfZeroIsRefusedBeforeAnyQuery(): void
    {
        $this->transactions()->rolledBackAs(self::ACME_FOODS, fn () => $this->assertRefusedBeforeAnyQuery(
            fn (): array => new DbalMyReportsRows($this->connection(), $this->tenantContext())->reportsOf(self::lena(), null, 0),
            \InvalidArgumentException::class,
            'My reports are read at least one at a time.',
        ));
    }

    /**
     * @param \Closure(): mixed        $read
     * @param class-string<\Throwable> $refusal
     */
    private function assertRefusedBeforeAnyQuery(\Closure $read, string $refusal, string $message): void
    {
        $debugData = $this->debugData();
        $debugData->reset();
        try {
            $read();
            self::fail('The read must be refused.');
        } catch (\LogicException|\InvalidArgumentException $exception) {
            self::assertSame([$refusal, $message], [$exception::class, $exception->getMessage()]);
        }
        self::assertSame(0, self::queryCountOf($debugData), 'No query ran before the refusal.');
    }

    /**
     * Reads the page twice: as planned, and with index scans off. The index tkt_ticket_reporter_id_created_at_id_idx
     * returns rows in the page's order by itself, so only the sorted sequential scan shows an ORDER BY or a keyset
     * that leans on the index's order; both reads must give the same page.
     */
    private function myReportsOf(TicketViewer $viewer, ?MyReportsCursor $after): MyReportsPage
    {
        $read = fn (): MyReportsPage => $this->queries()->myReports($viewer, $after);
        $planned = $this->transactions()->rolledBackAs($viewer->organizationId->toString(), $read);
        $sequential = $this->transactions()->rolledBackAs($viewer->organizationId->toString(), function () use ($read): MyReportsPage {
            $this->connection()->executeStatement('SET LOCAL enable_indexscan = off');
            $this->connection()->executeStatement('SET LOCAL enable_indexonlyscan = off');
            $this->connection()->executeStatement('SET LOCAL enable_bitmapscan = off');

            return $read();
        });
        self::assertEquals($planned, $sequential, 'The page is the same with or without the index.');

        return $planned;
    }

    /**
     * @return list<string>
     */
    private static function keysOf(MyReportsPage $page): array
    {
        return array_map(static fn (MyReport $report): string => $report->key, $page->reports);
    }

    /**
     * A ticket SHOP-<number> of the Shop team in To do, made at $createdAt and last changed 90 minutes later, with its
     * turn row (person, label, kind) in the group doing, or none for null.
     *
     * @param 'all'|'team'                         $visibility
     * @param array{?string, ?string, string}|null $turn
     */
    private function addReport(string $organizationId, int $number, string $createdAt, string $reporterId, string $visibility, ?array $turn, ?string $closedAt = null): void
    {
        $ticketId = self::ticketIdOf($number);
        $this->asTenantMigrator($organizationId, function (Connection $migrator) use ($organizationId, $ticketId, $number, $createdAt, $reporterId, $visibility, $turn, $closedAt): void {
            $this->insertTicket($migrator, $organizationId, $ticketId, $number, 'Cart total '.$number.' is wrong', $createdAt, $reporterId, $visibility, $closedAt);
            if (null === $turn) {
                return;
            }
            [$turnUserId, $turnLabel, $kind] = $turn;
            $migrator->executeStatement(
                "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id)
                 VALUES (?, ?, ?, ?, 'doing', ?, ?, ?, 100, '2027-03-01 00:00:00+00', ?, ?)",
                [$organizationId, $ticketId, self::teamOf($organizationId), self::SHOP_PROJECT, $turnUserId, $turnLabel, $kind, $visibility, $reporterId],
            );
        });
    }

    /**
     * Lena's reports SHOP-(1000 + n) for n from $first, each three made in the same minute (n / 3 minutes after
     * 2027-01-01 00:00 UTC), added in the scrambled order n * 7 mod $count in one transaction, with no turn row.
     */
    private function addManyReportsOfLena(int $count, int $first = 0): void
    {
        $this->asTenantMigrator(self::ACME_FOODS, function (Connection $migrator) use ($count, $first): void {
            foreach (range(0, $count - 1) as $position) {
                $number = $first + ($position * 7) % $count;
                $createdAt = new \DateTimeImmutable('2027-01-01 00:00:00 UTC')->modify(\sprintf('+%d minutes', intdiv($number, 3)))->format('Y-m-d H:i:s');
                $this->insertTicket($migrator, self::ACME_FOODS, self::manyReportIdOf($number), 1000 + $number, 'Checkout button is hidden', $createdAt, self::LENA, 'all', null);
            }
        });
    }

    private function insertTicket(Connection $migrator, string $organizationId, string $ticketId, int $number, string $title, string $createdAt, string $reporterId, string $visibility, ?string $closedAt): void
    {
        $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at, closed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, '', 'bug', 'major', 2, ?, ?, ?, '{}', '{}', ?, CAST(? AS timestamptz) + INTERVAL '90 minutes', ?)",
            [
                $organizationId,
                $ticketId,
                self::SHOP_PROJECT,
                self::teamOf($organizationId),
                $number,
                'SHOP-'.$number,
                $title,
                ShopTeamRows::TO_DO_STEP,
                $reporterId,
                $visibility,
                $createdAt.'+00',
                $createdAt.'+00',
                null === $closedAt ? null : $closedAt.'+00',
            ],
        );
    }

    /**
     * @param \Closure(Connection): void $work
     */
    private function asTenantMigrator(string $organizationId, \Closure $work): void
    {
        $this->tenantContext()->set(new OrganizationId($organizationId));
        try {
            $this->migrator()->transactional($work);
        } finally {
            $this->tenantContext()->clear();
        }
    }

    /**
     * Beta Bakery has no Shop team; its ticket belongs to no team.
     */
    private static function teamOf(string $organizationId): ?string
    {
        return self::ACME_FOODS === $organizationId ? self::SHOP_TEAM : null;
    }

    /**
     * @return list<string> the keys SHOP-(1000 + n) for n from $from down to $to
     */
    private static function manyReportKeys(int $from, int $to): array
    {
        return array_map(static fn (int $number): string => 'SHOP-'.(1000 + $number), range($from, $to));
    }

    private static function manyReportIdOf(int $number): string
    {
        return self::MANY_REPORTS_PREFIX.\sprintf('%04d', $number);
    }

    private static function ticketIdOf(int $number): string
    {
        return \sprintf('0197a3c0-0000-7000-8000-0000000e7b%02d', $number);
    }

    private static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Lena in no team and with no role that widens what she sees, so her team-only report shows only as hers.
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
