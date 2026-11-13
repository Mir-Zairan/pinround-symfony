<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insight;

use App\Insight\Api\HomeQueries;
use App\Insight\Api\TicketViewer;
use App\Insight\Api\TurnGroup;
use App\Insight\Api\WaitingTicket;
use App\Insight\Infrastructure\Doctrine\DbalWhoseTurnRows;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
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
 * The container's HomeQueries::whoseTurn as pinround_app under row-level security, on turn rows made by hand for tickets
 * everyone may see, so only the grouping, order, caps and project filter decide; WhoseTurnLeakTest covers visibility.
 */
final class DbalWhoseTurnRowsTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000f701';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000f702';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000f711';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000f721';
    private const string OPS_PROJECT = '0197a3c0-0000-7000-8000-00000000f722';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000f751';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000f752';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000f753';
    private const string RAJ = '0197a3c0-0000-7000-8000-00000000f754';
    private const string BETA_BAKERY_TICKET = '0197a3c0-0000-7000-8000-00000000f7bb';
    /** Turn rows bulk-added for one person get ids with this prefix and the row's number as the last four digits. */
    private const string MANY_TICKETS_PREFIX = '0197a3c0-0000-7000-8000-00000f71';

    protected function setUp(): void
    {
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
            self::MIA => ['Mia', 'member', 'tester'],
            self::RAJ => ['Raj', 'viewer', 'other'],
        ]);
        $this->ticketRows()->addKeyedProjectOf(self::ACME_FOODS, self::OPS_PROJECT, null, 'OPS', 1);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRows();
        parent::tearDown();
    }

    /**
     * Added out of order. Lena, the viewer, waits on the newest ticket. Raj waits on seven, added newest first, the
     * oldest of all. Omar on two in SHOP and, in OPS, the oldest of his three. Mia on two tied ones, the later id added
     * first. The Customer label on one whose wait ties with Omar's oldest in SHOP. Beta Bakery's ticket for Omar, the
     * oldest of every one, is another organization's.
     */
    private function addTheWaits(): void
    {
        $this->addTurn(self::ACME_FOODS, 1, self::SHOP_PROJECT, self::OMAR, 'build', '2027-03-12 09:00:00');
        $this->addTurn(self::ACME_FOODS, 2, self::SHOP_PROJECT, self::OMAR, 'review', '2027-03-10 08:00:00');
        $this->addTurn(self::ACME_FOODS, 3, self::OPS_PROJECT, self::OMAR, 'input', '2027-03-05 07:00:00');
        $this->addTurn(self::ACME_FOODS, 5, self::SHOP_PROJECT, self::MIA, 'answer', '2027-03-11 10:00:00');
        $this->addTurn(self::ACME_FOODS, 4, self::SHOP_PROJECT, self::MIA, 'test', '2027-03-11 10:00:00');
        $this->addTurn(self::ACME_FOODS, 6, self::SHOP_PROJECT, self::LENA, 'check', '2027-03-14 08:00:00');
        $this->addTurn(self::ACME_FOODS, 7, self::SHOP_PROJECT, null, 'input', '2027-03-10 08:00:00');
        foreach ([17, 16, 15, 14, 13, 12, 11] as $number) {
            $this->addTurn(self::ACME_FOODS, $number, self::SHOP_PROJECT, self::RAJ, 'decide', \sprintf('2027-03-%02d 06:00:00', $number - 10));
        }
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BETA_BAKERY_TICKET, self::SHOP_PROJECT, self::MIA, 99);
        $this->transactions()->committedAs(self::BETA_BAKERY, fn (): int|string => $this->insertTurn(
            $this->connection(),
            self::BETA_BAKERY,
            self::BETA_BAKERY_TICKET,
            self::SHOP_PROJECT,
            self::OMAR,
            'review',
            '2027-02-01 06:00:00',
        ));
    }

    #[Test]
    public function itGroupsByPersonAndLabelWithTheViewerFirstThenTheOldestWaitAndTheOldestTicketsFirst(): void
    {
        $this->addTheWaits();

        $groups = $this->whoseTurnOf(null, null);

        self::assertNotSame([], $groups);
        self::assertSame(
            [
                [self::LENA, 1, '2027-03-14 08:00:00', ['SHOP-6']],
                [self::RAJ, 7, '2027-03-01 06:00:00', ['SHOP-11', 'SHOP-12', 'SHOP-13', 'SHOP-14', 'SHOP-15']],
                [self::OMAR, 3, '2027-03-05 07:00:00', ['SHOP-3', 'SHOP-2', 'SHOP-1']],
                ['Customer', 1, '2027-03-10 08:00:00', ['SHOP-7']],
                [self::MIA, 2, '2027-03-11 10:00:00', ['SHOP-4', 'SHOP-5']],
            ],
            $groups,
        );
    }

    #[Test]
    public function eachTicketCarriesItsKeyTitleKindAndWait(): void
    {
        $this->addTheWaits();

        $tickets = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->queries()->whoseTurn(self::lena(), null, null)[0]->tickets);

        self::assertSame(
            [[self::ticketIdOf(6), 'SHOP-6', 'Checkout button is hidden', 'check', '2027-03-14 08:00:00']],
            array_map(static fn (WaitingTicket $ticket): array => [
                $ticket->id->toString(),
                $ticket->key,
                $ticket->title,
                $ticket->kind,
                self::utc($ticket->waitingSince),
            ], $tickets),
        );
    }

    /**
     * Omar's OPS ticket, his oldest, leaves both his count and his oldest wait, which then ties with the Customer label's:
     * the person comes first.
     */
    #[Test]
    public function aProjectFilterLeavesOutTheTicketsOfOtherProjectsFromTheCountsTheOldestAndTheRows(): void
    {
        $this->addTheWaits();

        $groups = $this->whoseTurnOf(self::SHOP_PROJECT, null);

        self::assertSame(
            [
                [self::LENA, 1, '2027-03-14 08:00:00', ['SHOP-6']],
                [self::RAJ, 7, '2027-03-01 06:00:00', ['SHOP-11', 'SHOP-12', 'SHOP-13', 'SHOP-14', 'SHOP-15']],
                [self::OMAR, 2, '2027-03-10 08:00:00', ['SHOP-2', 'SHOP-1']],
                ['Customer', 1, '2027-03-10 08:00:00', ['SHOP-7']],
                [self::MIA, 2, '2027-03-11 10:00:00', ['SHOP-4', 'SHOP-5']],
            ],
            $groups,
        );
    }

    #[Test]
    public function aProjectWithNoWaitingTicketHasNoGroup(): void
    {
        $this->addTheWaits();

        self::assertSame([], $this->whoseTurnOf('0197a3c0-0000-7000-8000-00000000f729', null));
    }

    #[Test]
    public function theOpenedGroupShowsUpTo100TicketsAndEveryOtherGroup5(): void
    {
        $this->addManyWaitsOn(self::RAJ, 103);
        $this->addManyWaitsOn(self::OMAR, 7, 200);

        $closed = $this->shownAndCountOf(null);
        $rajOpened = $this->shownAndCountOf(self::RAJ);
        $omarOpened = $this->shownAndCountOf(self::OMAR);

        self::assertSame(
            [
                [self::RAJ => [5, 103], self::OMAR => [5, 7]],
                [self::RAJ => [100, 103], self::OMAR => [5, 7]],
                [self::RAJ => [5, 103], self::OMAR => [7, 7]],
            ],
            [$closed, $rajOpened, $omarOpened],
        );
    }

    #[Test]
    public function theOpenedGroupShowsItsOldestTicketsInOrder(): void
    {
        $this->addManyWaitsOn(self::RAJ, 103);

        $keys = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => array_map(
            static fn (WaitingTicket $ticket): string => $ticket->key,
            $this->queries()->whoseTurn(self::lena(), null, self::RAJ)[0]->tickets,
        ));

        self::assertSame(array_map(static fn (int $number): string => 'SHOP-'.(1000 + $number), range(0, 99)), $keys);
    }

    #[Test]
    public function aLabelGroupCanBeOpened(): void
    {
        foreach (range(1, 6) as $number) {
            $this->addTurn(self::ACME_FOODS, $number, self::SHOP_PROJECT, null, 'input', \sprintf('2027-03-%02d 06:00:00', $number));
        }

        self::assertSame(
            [
                [['Customer', 6, '2027-03-01 06:00:00', ['SHOP-1', 'SHOP-2', 'SHOP-3', 'SHOP-4', 'SHOP-5']]],
                [['Customer', 6, '2027-03-01 06:00:00', ['SHOP-1', 'SHOP-2', 'SHOP-3', 'SHOP-4', 'SHOP-5', 'SHOP-6']]],
            ],
            [$this->whoseTurnOf(null, null), $this->whoseTurnOf(null, 'Customer')],
        );
    }

    #[Test]
    public function itRunsExactlyOneQueryWithAProjectAndAnOpenedGroup(): void
    {
        $this->addTheWaits();

        [$groups, $queryCount] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $debugData = $this->debugData();
            $debugData->reset();
            $groups = $this->queries()->whoseTurn(self::lena(), self::SHOP_PROJECT, self::RAJ);

            return [$groups, self::queryCountOf($debugData)];
        });

        self::assertSame([5, 1], [\count($groups), $queryCount]);
    }

    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsTurns(): void
    {
        $this->addTheWaits();
        self::assertCount(5, $this->whoseTurnOf(null, null));

        $groups = new OwnConnectionTransactions($this->connection(), $this->tenantContext())->rolledBackUnderAnotherContext(
            self::BETA_BAKERY,
            self::ACME_FOODS,
            fn (Connection $connection): array => new DbalWhoseTurnRows($connection, $this->tenantContext())
                ->groupedRowsOf(self::lena(), null, null),
        );

        self::assertSame([], $groups);
    }

    #[Test]
    public function withNoTransactionItRefusesBeforeAnyQuery(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        $this->assertRefusedBeforeAnyQuery(
            fn (): array => $this->queries()->whoseTurn(self::lena(), null, null),
            \LogicException::class,
            'Whose turn is read in a transaction under the organization as tenant; none is open.',
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
                fn (): array => $this->queries()->whoseTurn(self::lena(), null, null),
                TenantNotSet::class,
                'No organization is set for this unit of work.',
            );
        } finally {
            $connection->rollBack();
        }
    }

    #[Test]
    public function aViewerOfAnotherOrganizationThanTheTenantIsRefusedBeforeAnyQuery(): void
    {
        $betaBakeryAdmin = new TicketViewer(new OrganizationId(self::BETA_BAKERY), new UserId(self::RAJ), [], true, false);

        $this->transactions()->rolledBackAs(self::ACME_FOODS, fn () => $this->assertRefusedBeforeAnyQuery(
            fn (): array => $this->queries()->whoseTurn($betaBakeryAdmin, null, null),
            \LogicException::class,
            'Whose turn is read under the viewer\'s own organization as tenant.',
        ));
    }

    #[Test]
    public function aProjectIdThatIsNoCanonicalUuidIsRefusedBeforeAnyQuery(): void
    {
        $this->transactions()->rolledBackAs(self::ACME_FOODS, fn () => $this->assertRefusedBeforeAnyQuery(
            fn (): array => $this->queries()->whoseTurn(self::lena(), strtoupper(self::SHOP_PROJECT), null),
            \InvalidArgumentException::class,
            'Whose turn is read in a project given by its canonical lowercase uuid.',
        ));
    }

    /**
     * @param \Closure(): list<TurnGroup> $read
     * @param class-string<\Throwable>    $refusal
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
     * @return list<array{string, int, string, list<string>}> each group's key, ticket count, oldest wait in UTC and shown
     *                                                        ticket keys, as Acme Foods' Lena reads them
     */
    private function whoseTurnOf(?string $projectId, ?string $openedGroup): array
    {
        $groups = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->queries()->whoseTurn(self::lena(), $projectId, $openedGroup));

        return array_map(static fn (TurnGroup $group): array => [
            $group->groupKey(),
            $group->ticketCount,
            self::utc($group->oldestWaitingSince),
            array_map(static fn (WaitingTicket $ticket): string => $ticket->key, $group->tickets),
        ], $groups);
    }

    /**
     * @return array<string, array{int, int}> each group's shown tickets and ticket count, by group key
     */
    private function shownAndCountOf(?string $openedGroup): array
    {
        $shownAndCount = [];
        foreach ($this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->queries()->whoseTurn(self::lena(), null, $openedGroup)) as $group) {
            $shownAndCount[$group->groupKey()] = [\count($group->tickets), $group->ticketCount];
        }

        return $shownAndCount;
    }

    /**
     * Tickets SHOP-(1000 + first + n) waiting on the person, each a minute later than the one before, added newest first
     * in one transaction.
     */
    private function addManyWaitsOn(string $userId, int $tickets, int $first = 0): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->migrator()->transactional(function (Connection $migrator) use ($userId, $tickets, $first): void {
                foreach (array_reverse(range($first, $first + $tickets - 1)) as $number) {
                    $ticketId = self::MANY_TICKETS_PREFIX.\sprintf('%04d', $number);
                    $migrator->executeStatement(
                        "INSERT INTO tkt_ticket (organization_id, id, project_id, number, key, title, description, type, priority, priority_rank,
                             step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, 'Checkout button is hidden', '', 'bug', 'major', 2, ?, ?, 'all', '{}', '{}', '2027-03-15 10:00:00+00', '2027-03-15 10:00:00+00')",
                        [self::ACME_FOODS, $ticketId, self::SHOP_PROJECT, 1000 + $number, 'SHOP-'.(1000 + $number), ShopTeamRows::TO_DO_STEP, self::MIA],
                    );
                    $this->insertTurn(
                        $migrator,
                        self::ACME_FOODS,
                        $ticketId,
                        self::SHOP_PROJECT,
                        $userId,
                        'build',
                        new \DateTimeImmutable('2027-01-01 00:00:00 UTC')->modify(\sprintf('+%d minutes', $number))->format('Y-m-d H:i:s'),
                    );
                }
            });
        } finally {
            $this->tenantContext()->clear();
        }
    }

    /**
     * A ticket SHOP-<number> and its turn row, waiting on the person, or on the Customer label when there is none.
     */
    private function addTurn(string $organizationId, int $number, string $projectId, ?string $userId, string $kind, string $waitingSince): void
    {
        $ticketId = self::ticketIdOf($number);
        $this->ticketRows()->addOf($organizationId, $ticketId, $projectId, self::MIA, $number);
        $this->transactions()->committedAs($organizationId, fn (): int|string => $this->insertTurn(
            $this->connection(),
            $organizationId,
            $ticketId,
            $projectId,
            $userId,
            $kind,
            $waitingSince,
        ));
    }

    private function insertTurn(Connection $connection, string $organizationId, string $ticketId, string $projectId, ?string $userId, string $kind, string $waitingSince): int|string
    {
        return $connection->executeStatement(
            "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id)
             VALUES (?, ?, ?, ?, 'doing', ?, ?, ?, 100, ?, 'all', ?)",
            [$organizationId, $ticketId, self::SHOP_TEAM, $projectId, $userId, null === $userId ? 'Customer' : null, $kind, $waitingSince.'+00', self::MIA],
        );
    }

    private static function ticketIdOf(int $number): string
    {
        return \sprintf('0197a3c0-0000-7000-8000-00000000f8%02d', $number);
    }

    private static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * A viewer with no role that widens what she sees; every row here is for everyone, so she sees them all.
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
        $this->shopTeamRows()->removeAllOf([self::LENA, self::OMAR, self::MIA, self::RAJ], self::ACME_FOODS, self::BETA_BAKERY);
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
