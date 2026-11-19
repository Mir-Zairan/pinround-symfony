<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insight\Infrastructure\Doctrine;

use App\Insight\Api\MyTicket;
use App\Insight\Api\MyTicketQueries;
use App\Insight\Api\MyTickets;
use App\Insight\Api\TicketViewer;
use App\Insight\Infrastructure\Doctrine\DbalMyTicketQueries;
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
 * The container's MyTicketQueries as pinround_app under row-level security, on tickets and turn rows made by hand that
 * everyone may see, so only the caller's part in the ticket, the order, the limit and has_more decide;
 * MyTicketsLeakTest covers visibility.
 */
final class DbalMyTicketQueriesTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000d4a01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000d4a02';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000d4a11';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000d4a21';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000d4a51';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000d4a52';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000d4a53';
    /** Tickets bulk-added for Lena get ids with this prefix and the ticket's number as the last four digits. */
    private const string MANY_TICKETS_PREFIX = '0197a3c0-0000-7000-8000-00000d4c';

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
     * Added out of order. Lena's turn: SHOP-1, and SHOP-3 and SHOP-4, which tie on their last change, the lower id
     * added first. Assigned to her: SHOP-2, Omar's turn and the newest, and SHOP-5, waiting on the Customer label.
     * Not hers: SHOP-6, Omar's turn and assigned to Mia; SHOP-7, assigned to her but Done, so it has no turn row; SHOP-8,
     * the Customer label's with no assignee. Beta Bakery's ticket, the newest of all, waits on Lena's id there.
     */
    private function addTheTickets(): void
    {
        $this->addTicket(self::ACME_FOODS, 1, '2027-03-10 09:00:00', self::LENA, 'build', null);
        $this->addTicket(self::ACME_FOODS, 3, '2027-03-11 09:00:00', self::LENA, 'test', null);
        $this->addTicket(self::ACME_FOODS, 4, '2027-03-11 09:00:00', self::LENA, 'check', null);
        $this->addTicket(self::ACME_FOODS, 2, '2027-03-12 09:00:00', self::OMAR, 'review', self::LENA, self::MIA);
        $this->addTicket(self::ACME_FOODS, 5, '2027-03-09 09:00:00', null, 'input', self::LENA);
        $this->addTicket(self::ACME_FOODS, 6, '2027-03-13 09:00:00', self::OMAR, 'build', self::MIA);
        $this->addDoneTicketAssignedToLena(7, '2027-03-14 09:00:00');
        $this->addTicket(self::ACME_FOODS, 8, '2027-03-15 09:00:00', null, 'input', null);
        $this->ticketRows()->addProjectOf(self::BETA_BAKERY, self::SHOP_PROJECT);
        $this->addTicket(self::BETA_BAKERY, 9, '2027-03-20 09:00:00', self::LENA, 'build', self::LENA);
    }

    #[Test]
    public function itListsTheTicketsWhoseTurnIsTheViewersOrThatAreAssignedToThemTheLastChangedFirstThenTheHighestId(): void
    {
        $this->addTheTickets();

        $myTickets = $this->myTicketsOf(self::lena(), 50);

        self::assertSame(['SHOP-2', 'SHOP-4', 'SHOP-3', 'SHOP-1', 'SHOP-5'], self::keysOf($myTickets));
        self::assertFalse($myTickets->hasMore);
    }

    #[Test]
    public function eachTicketCarriesItsKeyTitleStepTeamTurnAssigneeAskerAndLastChange(): void
    {
        $this->addTheTickets();

        $tickets = $this->myTicketsOf(self::lena(), 50)->tickets;

        self::assertNotSame([], $tickets);
        self::assertSame(
            [
                [self::ticketIdOf(2), 'SHOP-2', 'Cart total 2 is wrong', ShopTeamRows::TO_DO_STEP, self::SHOP_TEAM, self::OMAR, null, 'review', self::LENA, self::MIA, '2027-03-12 09:00:00'],
                [self::ticketIdOf(4), 'SHOP-4', 'Cart total 4 is wrong', ShopTeamRows::TO_DO_STEP, self::SHOP_TEAM, self::LENA, null, 'check', null, null, '2027-03-11 09:00:00'],
                [self::ticketIdOf(3), 'SHOP-3', 'Cart total 3 is wrong', ShopTeamRows::TO_DO_STEP, self::SHOP_TEAM, self::LENA, null, 'test', null, null, '2027-03-11 09:00:00'],
                [self::ticketIdOf(1), 'SHOP-1', 'Cart total 1 is wrong', ShopTeamRows::TO_DO_STEP, self::SHOP_TEAM, self::LENA, null, 'build', null, null, '2027-03-10 09:00:00'],
                [self::ticketIdOf(5), 'SHOP-5', 'Cart total 5 is wrong', ShopTeamRows::TO_DO_STEP, self::SHOP_TEAM, null, 'Customer', 'input', self::LENA, null, '2027-03-09 09:00:00'],
            ],
            array_map(static fn (MyTicket $ticket): array => [
                $ticket->id->toString(),
                $ticket->key,
                $ticket->title,
                $ticket->stepId,
                $ticket->teamId?->toString(),
                $ticket->turnUserId?->toString(),
                $ticket->turnLabel,
                $ticket->turnKind,
                $ticket->assigneeId?->toString(),
                $ticket->askerId?->toString(),
                $ticket->updatedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ], $tickets),
        );
    }

    #[Test]
    public function anotherPersonGetsOnlyTheirOwnTickets(): void
    {
        $this->addTheTickets();

        $omar = new TicketViewer(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), [], false, false);

        self::assertSame(['SHOP-6', 'SHOP-2'], self::keysOf($this->myTicketsOf($omar, 50)));
    }

    #[Test]
    public function aLimitBelowTheTicketsReturnsTheFirstOnesAndSaysThereAreMore(): void
    {
        $this->addTheTickets();

        $firstFour = $this->myTicketsOf(self::lena(), 4);
        $exactlyFive = $this->myTicketsOf(self::lena(), 5);
        $onlyOne = $this->myTicketsOf(self::lena(), 1);

        self::assertSame(
            [[['SHOP-2', 'SHOP-4', 'SHOP-3', 'SHOP-1'], true], [['SHOP-2', 'SHOP-4', 'SHOP-3', 'SHOP-1', 'SHOP-5'], false], [['SHOP-2'], true]],
            [[self::keysOf($firstFour), $firstFour->hasMore], [self::keysOf($exactlyFive), $exactlyFive->hasMore], [self::keysOf($onlyOne), $onlyOne->hasMore]],
        );
    }

    #[Test]
    public function fiftyOneTicketsGiveTheFiftyLastChangedAndHasMoreInOneQuery(): void
    {
        $this->addManyTicketsOfLena(51);

        [$myTickets, $queryCount] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $debugData = $this->debugData();
            $debugData->reset();
            $myTickets = $this->queries()->myTicketsOf(self::lena(), MyTicketQueries::MAX_TICKETS);

            return [$myTickets, self::queryCountOf($debugData)];
        });

        self::assertSame(array_map(static fn (int $number): string => 'SHOP-'.(1000 + $number), range(50, 1)), self::keysOf($myTickets));
        self::assertTrue($myTickets->hasMore);
        self::assertSame(1, $queryCount);
    }

    /**
     * Mia reported every ticket and asked SHOP-2's open question; only SHOP-6, assigned to her, is hers to work on.
     */
    #[Test]
    public function reportingATicketOrAskingItsQuestionDoesNotListIt(): void
    {
        $this->addTheTickets();
        $mia = new TicketViewer(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), [], false, false);

        self::assertSame(['SHOP-6'], self::keysOf($this->myTicketsOf($mia, 50)));
    }

    #[Test]
    public function aViewerWithNoTicketsGetsAnEmptyListAndNoMore(): void
    {
        $this->addTheTickets();
        $adminWithNoTickets = new TicketViewer(new OrganizationId(self::ACME_FOODS), new UserId('0197a3c0-0000-7000-8000-0000000d4a59'), [], true, false);

        $myTickets = $this->myTicketsOf($adminWithNoTickets, 50);

        self::assertSame([[], false], [$myTickets->tickets, $myTickets->hasMore]);
    }

    #[Test]
    public function aLimitOfZeroOrFiftyOneIsRefusedBeforeAnyQuery(): void
    {
        foreach ([0, 51] as $limit) {
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn () => $this->assertRefusedBeforeAnyQuery(
                fn (): MyTickets => $this->queries()->myTicketsOf(self::lena(), $limit),
                \InvalidArgumentException::class,
                'My tickets are read 1 to 50 at a time.',
            ));
        }
    }

    #[Test]
    public function withNoTransactionItRefusesBeforeAnyQuery(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        $this->assertRefusedBeforeAnyQuery(
            fn (): MyTickets => $this->queries()->myTicketsOf(self::lena(), 50),
            \LogicException::class,
            'My tickets are read in a transaction under the organization as tenant; none is open.',
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
                fn (): MyTickets => $this->queries()->myTicketsOf(self::lena(), 50),
                TenantNotSet::class,
                'No organization is set for this unit of work.',
            );
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * Lena's id waits on a ticket of Beta Bakery too, so a read that ran anyway under Acme Foods would differ from none.
     */
    #[Test]
    public function aViewerOfAnotherOrganizationThanTheTenantIsRefusedBeforeAnyQuery(): void
    {
        $this->addTheTickets();
        $lenaInBetaBakery = new TicketViewer(new OrganizationId(self::BETA_BAKERY), new UserId(self::LENA), [], true, false);

        $this->transactions()->rolledBackAs(self::ACME_FOODS, fn () => $this->assertRefusedBeforeAnyQuery(
            fn (): MyTickets => $this->queries()->myTicketsOf($lenaInBetaBakery, 50),
            \LogicException::class,
            'My tickets are read under the viewer\'s own organization as tenant.',
        ));
    }

    /**
     * The SQL and the viewer name Acme Foods while the database tenant is Beta Bakery, so only row-level security can
     * hide Acme Foods' turn rows and tickets.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsTickets(): void
    {
        $this->addTheTickets();
        self::assertNotSame([], $this->myTicketsOf(self::lena(), 50)->tickets);

        $myTickets = new OwnConnectionTransactions($this->connection(), $this->tenantContext())->rolledBackUnderAnotherContext(
            self::BETA_BAKERY,
            self::ACME_FOODS,
            fn (Connection $connection): MyTickets => new DbalMyTicketQueries($connection, $this->tenantContext())->myTicketsOf(self::lena(), 50),
        );

        self::assertSame([[], false], [$myTickets->tickets, $myTickets->hasMore]);
    }

    /**
     * @param \Closure(): MyTickets    $read
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

    private function myTicketsOf(TicketViewer $viewer, int $limit): MyTickets
    {
        return $this->transactions()->rolledBackAs($viewer->organizationId->toString(), fn (): MyTickets => $this->queries()->myTicketsOf($viewer, $limit));
    }

    /**
     * @return list<string>
     */
    private static function keysOf(MyTickets $myTickets): array
    {
        return array_map(static fn (MyTicket $ticket): string => $ticket->key, $myTickets->tickets);
    }

    /**
     * A ticket SHOP-<number> of the Shop team, reported by Mia, last changed at $updatedAt, and its turn row: waiting on
     * $turnUserId, or on the Customer label for null.
     */
    private function addTicket(string $organizationId, int $number, string $updatedAt, ?string $turnUserId, string $kind, ?string $assigneeId, ?string $askerId = null): void
    {
        $ticketId = self::ticketIdOf($number);
        $this->asTenantMigrator($organizationId, function (Connection $migrator) use ($organizationId, $ticketId, $number, $updatedAt, $turnUserId, $kind, $assigneeId, $askerId): void {
            $this->insertTicket($migrator, $organizationId, $ticketId, 'SHOP-'.$number, 'Cart total '.$number.' is wrong', $updatedAt, $assigneeId, $askerId, null === $askerId ? null : $turnUserId, null);
            $this->insertTurn($migrator, $organizationId, $ticketId, $turnUserId, $kind, $assigneeId);
        });
    }

    /**
     * Done: closed, and with no turn row, as the turn projector leaves a Done ticket.
     */
    private function addDoneTicketAssignedToLena(int $number, string $updatedAt): void
    {
        $this->asTenantMigrator(self::ACME_FOODS, fn (Connection $migrator) => $this->insertTicket(
            $migrator,
            self::ACME_FOODS,
            self::ticketIdOf($number),
            'SHOP-'.$number,
            'Cart total '.$number.' is wrong',
            $updatedAt,
            self::LENA,
            null,
            null,
            $updatedAt,
        ));
    }

    /**
     * Tickets SHOP-(1000 + n) for n from 0, each waiting on Lena and changed a minute after the one before, added newest
     * first in one transaction.
     */
    private function addManyTicketsOfLena(int $tickets): void
    {
        $this->asTenantMigrator(self::ACME_FOODS, function (Connection $migrator) use ($tickets): void {
            foreach (array_reverse(range(0, $tickets - 1)) as $number) {
                $ticketId = self::MANY_TICKETS_PREFIX.\sprintf('%04d', $number);
                $updatedAt = new \DateTimeImmutable('2027-01-01 00:00:00 UTC')->modify(\sprintf('+%d minutes', $number))->format('Y-m-d H:i:s');
                $this->insertTicket($migrator, self::ACME_FOODS, $ticketId, 'SHOP-'.(1000 + $number), 'Checkout button is hidden', $updatedAt, null, null, null, null);
                $this->insertTurn($migrator, self::ACME_FOODS, $ticketId, self::LENA, 'build', null);
            }
        });
    }

    private function insertTicket(Connection $migrator, string $organizationId, string $ticketId, string $key, string $title, string $updatedAt, ?string $assigneeId, ?string $askerId, ?string $askedUserId, ?string $closedAt): void
    {
        $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, visibility, assignee_id, asker_id, asked_user_id, components, check_reasons, created_at, updated_at, closed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, '', 'bug', 'major', 2, ?, ?, 'all', ?, ?, ?, '{}', '{}', '2027-01-01 00:00:00+00', ?, ?)",
            [
                $organizationId,
                $ticketId,
                self::SHOP_PROJECT,
                self::ACME_FOODS === $organizationId ? self::SHOP_TEAM : null,
                (int) substr($key, \strlen('SHOP-')),
                $key,
                $title,
                ShopTeamRows::TO_DO_STEP,
                self::MIA,
                $assigneeId,
                $askerId,
                $askedUserId,
                $updatedAt.'+00',
                null === $closedAt ? null : $closedAt.'+00',
            ],
        );
    }

    private function insertTurn(Connection $migrator, string $organizationId, string $ticketId, ?string $turnUserId, string $kind, ?string $assigneeId): void
    {
        $migrator->executeStatement(
            "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id, assignee_id)
             VALUES (?, ?, ?, ?, 'doing', ?, ?, ?, 100, '2027-01-01 00:00:00+00', 'all', ?, ?)",
            [
                $organizationId,
                $ticketId,
                self::ACME_FOODS === $organizationId ? self::SHOP_TEAM : null,
                self::SHOP_PROJECT,
                $turnUserId,
                null === $turnUserId ? 'Customer' : null,
                $kind,
                self::MIA,
                $assigneeId,
            ],
        );
    }

    /**
     * @param \Closure(Connection): mixed $work
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

    private static function ticketIdOf(int $number): string
    {
        return \sprintf('0197a3c0-0000-7000-8000-0000000d4b%02d', $number);
    }

    /**
     * A viewer with no role that widens what she sees; every ticket here is for everyone, so only her part in it counts.
     */
    private static function lena(): TicketViewer
    {
        return new TicketViewer(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), [], false, false);
    }

    private function queries(): MyTicketQueries
    {
        $queries = self::getContainer()->get('test.insight_my_ticket_queries');
        \assert($queries instanceof MyTicketQueries);

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
