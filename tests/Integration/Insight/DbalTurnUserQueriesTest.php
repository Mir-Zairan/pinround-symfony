<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insight;

use App\Insight\Api\TurnUserQueries;
use App\Insight\Infrastructure\Doctrine\DbalTurnUserQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\ReadsSentSql;
use App\Tests\Integration\Tenancy\OwnConnectionTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The container's TurnUserQueries as pinround_app under row-level security. Beta Bakery has a ticket with the same id
 * as one of Acme Foods', whose turn is another person's.
 */
final class DbalTurnUserQueriesTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000072a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000072a2';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000072d1';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000072b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000072b2';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000072b3';
    private const string LENAS_TURN = '0197a3c0-0000-7000-8000-0000000072e1';
    private const string OMARS_TURN = '0197a3c0-0000-7000-8000-0000000072e2';
    private const string CUSTOMERS_TURN = '0197a3c0-0000-7000-8000-0000000072e3';
    private const string NO_TURN_ROW = '0197a3c0-0000-7000-8000-0000000072e4';

    protected function setUp(): void
    {
        $this->removeRows();
        $ticketRows = new TicketRows($this->migrator(), $this->tenantContext());
        foreach ([self::LENAS_TURN, self::OMARS_TURN, self::CUSTOMERS_TURN, self::NO_TURN_ROW] as $number => $ticketId) {
            $ticketRows->addOf(self::ACME_FOODS, $ticketId, self::SHOP_PROJECT, self::LENA, $number + 1);
        }
        $ticketRows->addOf(self::BETA_BAKERY, self::LENAS_TURN, self::SHOP_PROJECT, self::BEN);
        $this->addTurn(self::ACME_FOODS, self::LENAS_TURN, self::LENA, null);
        $this->addTurn(self::ACME_FOODS, self::OMARS_TURN, self::OMAR, null);
        $this->addTurn(self::ACME_FOODS, self::CUSTOMERS_TURN, null, 'Customer');
        $this->addTurn(self::BETA_BAKERY, self::LENAS_TURN, self::BEN, null);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function itGivesTheTurnUserOfEachTicketWithOneAndLeavesOutTheOthers(): void
    {
        $turnUsers = $this->turnUsersAs(self::ACME_FOODS, [self::LENAS_TURN, self::OMARS_TURN, self::CUSTOMERS_TURN, self::NO_TURN_ROW]);

        self::assertSame([self::LENAS_TURN => self::LENA, self::OMARS_TURN => self::OMAR], self::idsOf($turnUsers));
    }

    #[Test]
    public function eachOrganizationReadsOnlyItsOwnTurnRows(): void
    {
        self::assertSame([self::LENAS_TURN => self::BEN], self::idsOf($this->turnUsersAs(self::BETA_BAKERY, [self::LENAS_TURN, self::OMARS_TURN])));
    }

    /**
     * The SQL names the organization of TenantContext, while the transaction's tenant, which row-level security uses,
     * is another one: what still hides Acme Foods' turn rows from Beta Bakery's transaction is row-level security alone.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsTurnRows(): void
    {
        self::assertNotSame([], $this->turnUsersAs(self::ACME_FOODS, [self::OMARS_TURN]));

        $turnUsersUnderAcmeContext = new OwnConnectionTransactions($this->connection(), $this->tenantContext())->rolledBackUnderAnotherContext(
            self::BETA_BAKERY,
            self::ACME_FOODS,
            fn (Connection $connection): array => new DbalTurnUserQueries($connection, $this->tenantContext())
                ->turnUsersOf([new TicketId(self::LENAS_TURN), new TicketId(self::OMARS_TURN)]),
        );

        self::assertSame([], $turnUsersUnderAcmeContext);
    }

    #[Test]
    public function noTicketsRunNoQuery(): void
    {
        $debugData = $this->debugData();
        $debugData->reset();

        self::assertSame([], $this->turnUserQueries()->turnUsersOf([]));
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            self::assertSame([], $queriesOfOneConnection);
        }
    }

    #[Test]
    public function withNoTransactionItRefuses(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Turn users are read in a transaction under the organization as tenant; none is open.');
        $this->turnUserQueries()->turnUsersOf([new TicketId(self::LENAS_TURN)]);
    }

    /**
     * @param list<string> $ticketIds
     *
     * @return array<string, UserId>
     */
    private function turnUsersAs(string $organizationId, array $ticketIds): array
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext())->rolledBackAs(
            $organizationId,
            fn (): array => $this->turnUserQueries()->turnUsersOf(array_map(static fn (string $ticketId): TicketId => new TicketId($ticketId), $ticketIds)),
        );
    }

    /**
     * @param array<string, UserId> $turnUsers
     *
     * @return array<string, string>
     */
    private static function idsOf(array $turnUsers): array
    {
        $ids = array_map(static fn (UserId $userId): string => $userId->toString(), $turnUsers);
        ksort($ids);

        return $ids;
    }

    private function addTurn(string $organizationId, string $ticketId, ?string $turnUserId, ?string $turnLabel): void
    {
        $this->tenantContext()->set(new OrganizationId($organizationId));
        try {
            $this->migrator()->transactional(static fn (Connection $migrator): int|string => $migrator->executeStatement(
                "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id)
                 SELECT organization_id, id, NULL, project_id, 'todo', ?, ?, 'build', 10, '2027-03-15 11:00:00+00', 'all', reporter_id FROM tkt_ticket WHERE id = ?",
                [$turnUserId, $turnLabel, $ticketId],
            ));
        } finally {
            $this->tenantContext()->clear();
        }
    }

    private function removeRows(): void
    {
        new TicketRows($this->migrator(), $this->tenantContext())->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function turnUserQueries(): TurnUserQueries
    {
        $turnUserQueries = self::getContainer()->get('test.insight_turn_user_queries');
        self::assertInstanceOf(TurnUserQueries::class, $turnUserQueries);

        return $turnUserQueries;
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

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
