<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insight\Infrastructure\MyReports;

use App\Insight\Api\MyReportsCounts;
use App\Insight\Api\MyReportsTotals;
use App\Insight\Api\TicketViewer;
use App\Insight\Infrastructure\MyReports\DbalMyReportsCounts;
use App\SharedKernel\Application\TenantContext;
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
 * The container's MyReportsCounts on DbalMyReportsCounts as pinround_app under row-level security, on tickets and turn
 * rows made by hand.
 */
final class DbalMyReportsCountsTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e8a01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000e8a02';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000e8a11';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000e8a21';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000e8a51';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000e8a52';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000e8a53';

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
     * Lena's reports: SHOP-1, a question to her; SHOP-2, a question to her on a team-only ticket of the Shop team, which
     * her viewer here is not in; SHOP-3, a question that waits on Omar; SHOP-4, her turn to test; SHOP-5, Done; SHOP-6,
     * the Customer's turn. Not hers: SHOP-7, Omar's report with a question to Lena; and Beta Bakery's SHOP-9, reported
     * by Lena's id there with a question to her.
     */
    private function addTheReports(): void
    {
        $this->addReport(self::ACME_FOODS, 3, self::LENA, 'all', [self::OMAR, null, 'answer']);
        $this->addReport(self::ACME_FOODS, 7, self::OMAR, 'all', [self::LENA, null, 'answer']);
        $this->addReport(self::ACME_FOODS, 1, self::LENA, 'all', [self::LENA, null, 'answer']);
        $this->addReport(self::ACME_FOODS, 5, self::LENA, 'all', null, '2027-03-15 09:00:00');
        $this->addReport(self::ACME_FOODS, 4, self::LENA, 'all', [self::LENA, null, 'test']);
        $this->addReport(self::ACME_FOODS, 2, self::LENA, 'team', [self::LENA, null, 'answer']);
        $this->addReport(self::ACME_FOODS, 6, self::LENA, 'all', [null, 'Customer', 'input']);
        $this->ticketRows()->addProjectOf(self::BETA_BAKERY, self::SHOP_PROJECT);
        $this->addReport(self::BETA_BAKERY, 9, self::LENA, 'all', [self::LENA, null, 'answer']);
    }

    #[Test]
    public function theBadgeCountsOnlyTheQuestionsThatWaitOnTheReporterInTheirOwnReports(): void
    {
        $this->addTheReports();

        self::assertSame(2, $this->questionsWaitingOn(self::ACME_FOODS, self::LENA));
        self::assertSame(0, $this->questionsWaitingOn(self::ACME_FOODS, self::OMAR), 'Omar\'s report waits on Lena, not on him');
        self::assertSame(0, $this->questionsWaitingOn(self::ACME_FOODS, self::MIA));
        self::assertSame(1, $this->questionsWaitingOn(self::BETA_BAKERY, self::LENA), 'Beta Bakery counts its own question only');
    }

    #[Test]
    public function theTotalsCountEveryReportOfTheViewerAndTheOnesThatNeedTheirAnswer(): void
    {
        $this->addTheReports();

        self::assertEquals(new MyReportsTotals(6, 2), $this->totalsOf(self::lena()));
        self::assertEquals(new MyReportsTotals(1, 0), $this->totalsOf(new TicketViewer(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), [new TeamId(self::SHOP_TEAM)], false, false)));
        self::assertEquals(new MyReportsTotals(0, 0), $this->totalsOf(new TicketViewer(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), [], true, false)));
        self::assertEquals(new MyReportsTotals(1, 1), $this->totalsOf(new TicketViewer(new OrganizationId(self::BETA_BAKERY), new UserId(self::LENA), [], false, false)));
    }

    #[Test]
    public function eachCountIsOneQuery(): void
    {
        $this->addTheReports();

        $queryCounts = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $debugData = $this->debugData();
            $debugData->reset();
            $this->counts()->questionsWaitingOn(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA));
            $badgeQueries = self::queryCountOf($debugData);
            $debugData->reset();
            $this->counts()->totalsOf(self::lena());

            return [$badgeQueries, self::queryCountOf($debugData)];
        });

        self::assertSame([1, 1], $queryCounts);
    }

    /**
     * The SQL names Acme Foods while the database tenant is Beta Bakery, so only row-level security can hide Acme
     * Foods' rows.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsReports(): void
    {
        $this->addTheReports();
        self::assertSame(2, $this->questionsWaitingOn(self::ACME_FOODS, self::LENA));

        $counts = new OwnConnectionTransactions($this->connection(), $this->tenantContext())->rolledBackUnderAnotherContext(
            self::BETA_BAKERY,
            self::ACME_FOODS,
            function (Connection $connection): array {
                $counts = new DbalMyReportsCounts($connection, $this->tenantContext());

                return [$counts->questionsWaitingOn(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA)), $counts->totalsOf(self::lena())];
            },
        );

        self::assertEquals([0, new MyReportsTotals(0, 0)], $counts);
    }

    #[Test]
    public function withNoTransactionBothCountsAreRefusedBeforeAnyQuery(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $message = 'My reports are counted in a transaction under the organization as tenant; none is open.';

        $this->assertRefusedBeforeAnyQuery(fn (): int => $this->counts()->questionsWaitingOn(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA)), $message);
        $this->assertRefusedBeforeAnyQuery(fn (): MyReportsTotals => $this->counts()->totalsOf(self::lena()), $message);
    }

    /**
     * Lena's id has a question waiting in Beta Bakery too, so a count that ran anyway would differ from none.
     */
    #[Test]
    public function anOrganizationOtherThanTheTenantIsRefusedBeforeAnyQuery(): void
    {
        $this->addTheReports();
        $message = 'My reports are counted under the reporter\'s own organization as tenant.';

        $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($message): void {
            $this->assertRefusedBeforeAnyQuery(fn (): int => $this->counts()->questionsWaitingOn(new OrganizationId(self::BETA_BAKERY), new UserId(self::LENA)), $message);
            $this->assertRefusedBeforeAnyQuery(fn (): MyReportsTotals => $this->counts()->totalsOf(new TicketViewer(new OrganizationId(self::BETA_BAKERY), new UserId(self::LENA), [], true, false)), $message);
        });
    }

    /**
     * @param \Closure(): mixed $count
     */
    private function assertRefusedBeforeAnyQuery(\Closure $count, string $message): void
    {
        $debugData = $this->debugData();
        $debugData->reset();
        try {
            $count();
            self::fail('The count must be refused.');
        } catch (\LogicException $exception) {
            self::assertSame([\LogicException::class, $message], [$exception::class, $exception->getMessage()]);
        }
        self::assertSame(0, self::queryCountOf($debugData), 'No query ran before the refusal.');
    }

    private function questionsWaitingOn(string $organizationId, string $reporterId): int
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): int => $this->counts()->questionsWaitingOn(new OrganizationId($organizationId), new UserId($reporterId)));
    }

    private function totalsOf(TicketViewer $viewer): MyReportsTotals
    {
        return $this->transactions()->rolledBackAs($viewer->organizationId->toString(), fn (): MyReportsTotals => $this->counts()->totalsOf($viewer));
    }

    /**
     * Lena in no team and with no role that widens what she sees, so her team-only report counts only as hers.
     */
    private static function lena(): TicketViewer
    {
        return new TicketViewer(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), [], false, false);
    }

    /**
     * A ticket SHOP-<number> of the Shop team in To do, with its turn row (person, label, kind) in the group doing, or
     * none for null.
     *
     * @param 'all'|'team'                         $visibility
     * @param array{?string, ?string, string}|null $turn
     */
    private function addReport(string $organizationId, int $number, string $reporterId, string $visibility, ?array $turn, ?string $closedAt = null): void
    {
        $ticketId = \sprintf('0197a3c0-0000-7000-8000-0000000e8b%02d', $number);
        $teamId = self::ACME_FOODS === $organizationId ? self::SHOP_TEAM : null;
        $this->tenantContext()->set(new OrganizationId($organizationId));
        try {
            $this->migrator()->transactional(static function (Connection $migrator) use ($organizationId, $ticketId, $teamId, $number, $reporterId, $visibility, $turn, $closedAt): void {
                $migrator->executeStatement(
                    "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                         step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at, closed_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, '', 'bug', 'major', 2, ?, ?, ?, '{}', '{}', '2027-03-01 09:00:00+00', '2027-03-02 09:00:00+00', ?)",
                    [$organizationId, $ticketId, self::SHOP_PROJECT, $teamId, $number, 'SHOP-'.$number, 'Cart total '.$number.' is wrong', ShopTeamRows::TO_DO_STEP, $reporterId, $visibility, null === $closedAt ? null : $closedAt.'+00'],
                );
                if (null === $turn) {
                    return;
                }
                [$turnUserId, $turnLabel, $kind] = $turn;
                $migrator->executeStatement(
                    "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id)
                     VALUES (?, ?, ?, ?, 'doing', ?, ?, ?, 100, '2027-03-01 00:00:00+00', ?, ?)",
                    [$organizationId, $ticketId, $teamId, self::SHOP_PROJECT, $turnUserId, $turnLabel, $kind, $visibility, $reporterId],
                );
            });
        } finally {
            $this->tenantContext()->clear();
        }
    }

    private function counts(): MyReportsCounts
    {
        return self::getContainer()->get(MyReportsCounts::class);
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
