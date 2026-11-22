<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insight;

use App\Insight\Api\TurnWait;
use App\Insight\Api\TurnWaits;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The container's TurnWaits as pinround_app under row-level security. Beta Bakery has a ticket with the same id as
 * Acme Foods' ticket of Lena's turn, whose turn is Ben's.
 */
final class DbalTurnWaitsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000073a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000073a2';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000073d1';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000073b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000073b2';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000073b3';
    private const string LENAS_TURN = '0197a3c0-0000-7000-8000-0000000073e1';
    private const string CUSTOMERS_TURN = '0197a3c0-0000-7000-8000-0000000073e2';
    private const string NO_TURN_ROW = '0197a3c0-0000-7000-8000-0000000073e3';
    private const string STORED_SINCE = '2027-03-15 11:00:00+00';
    private const string EARLIER = '2027-03-06 08:15:30+00:00';

    protected function setUp(): void
    {
        $this->removeRows();
        $ticketRows = new TicketRows($this->migrator(), $this->tenantContext());
        foreach ([self::LENAS_TURN, self::CUSTOMERS_TURN, self::NO_TURN_ROW] as $number => $ticketId) {
            $ticketRows->addOf(self::ACME_FOODS, $ticketId, self::SHOP_PROJECT, self::LENA, $number + 1);
        }
        $ticketRows->addOf(self::BETA_BAKERY, self::LENAS_TURN, self::SHOP_PROJECT, self::BEN);
        $this->addTurn(self::ACME_FOODS, self::LENAS_TURN, self::LENA, null);
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
    public function itGivesThePersonOrLabelOfEachTurnAndWhenItBegan(): void
    {
        $lenasWait = $this->waitAs(self::ACME_FOODS, self::LENAS_TURN);
        $customersWait = $this->waitAs(self::ACME_FOODS, self::CUSTOMERS_TURN);

        self::assertSame([self::LENA, null, '2027-03-15 11:00:00'], self::fieldsOf($lenasWait));
        self::assertSame([null, 'Customer', '2027-03-15 11:00:00'], self::fieldsOf($customersWait));
        self::assertNull($this->waitAs(self::ACME_FOODS, self::NO_TURN_ROW));
        self::assertSame([self::BEN, null, '2027-03-15 11:00:00'], self::fieldsOf($this->waitAs(self::BETA_BAKERY, self::LENAS_TURN)));
    }

    #[Test]
    public function itPutsTheWaitBackWhileTheTurnIsTheSamePersonOrLabel(): void
    {
        $this->restoreAs(self::ACME_FOODS, self::LENAS_TURN, new TurnWait(new UserId(self::LENA), null, new \DateTimeImmutable(self::EARLIER)));
        $this->restoreAs(self::ACME_FOODS, self::CUSTOMERS_TURN, new TurnWait(null, 'Customer', new \DateTimeImmutable(self::EARLIER)));

        self::assertSame('2027-03-06 08:15:30+00', $this->storedSince(self::ACME_FOODS, self::LENAS_TURN));
        self::assertSame('2027-03-06 08:15:30+00', $this->storedSince(self::ACME_FOODS, self::CUSTOMERS_TURN));
        self::assertSame(self::STORED_SINCE, $this->storedSince(self::BETA_BAKERY, self::LENAS_TURN));
    }

    #[Test]
    public function aTurnThatIsSomeoneElsesKeepsItsWait(): void
    {
        $this->restoreAs(self::ACME_FOODS, self::LENAS_TURN, new TurnWait(new UserId(self::OMAR), null, new \DateTimeImmutable(self::EARLIER)));
        $this->restoreAs(self::ACME_FOODS, self::CUSTOMERS_TURN, new TurnWait(null, 'Supplier', new \DateTimeImmutable(self::EARLIER)));

        self::assertSame(self::STORED_SINCE, $this->storedSince(self::ACME_FOODS, self::LENAS_TURN));
        self::assertSame(self::STORED_SINCE, $this->storedSince(self::ACME_FOODS, self::CUSTOMERS_TURN));
    }

    #[Test]
    public function withNoTransactionItRefusesToRestoreAndChangesNothing(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->turnWaits()->restore(new TicketId(self::LENAS_TURN), new TurnWait(new UserId(self::LENA), null, new \DateTimeImmutable(self::EARLIER)));
            self::fail('The restore ran with no transaction open.');
        } catch (\LogicException $refusal) {
            self::assertSame('A turn wait is read and restored in the transaction of the move; none is open.', $refusal->getMessage());
        } finally {
            $this->tenantContext()->clear();
        }

        self::assertSame(self::STORED_SINCE, $this->storedSince(self::ACME_FOODS, self::LENAS_TURN));
    }

    /**
     * @return array{?string, ?string, string}
     */
    private static function fieldsOf(?TurnWait $wait): array
    {
        self::assertNotNull($wait);

        return [$wait->userId?->toString(), $wait->label, $wait->since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
    }

    private function waitAs(string $organizationId, string $ticketId): ?TurnWait
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): ?TurnWait => $this->turnWaits()->waitOf(new TicketId($ticketId)));
    }

    private function restoreAs(string $organizationId, string $ticketId, TurnWait $wait): void
    {
        $this->transactions()->committedAs($organizationId, fn () => $this->turnWaits()->restore(new TicketId($ticketId), $wait));
    }

    private function storedSince(string $organizationId, string $ticketId): string
    {
        $since = $this->transactions()->rolledBackAs($organizationId, fn (): mixed => $this->connection()->fetchOne(
            "SELECT CAST(waiting_since AT TIME ZONE 'UTC' AS text) || '+00' FROM ins_ticket_turn WHERE ticket_id = ?",
            [$ticketId],
        ));
        self::assertIsString($since);

        return $since;
    }

    private function addTurn(string $organizationId, string $ticketId, ?string $turnUserId, ?string $turnLabel): void
    {
        $this->tenantContext()->set(new OrganizationId($organizationId));
        try {
            $this->migrator()->transactional(static fn (Connection $migrator): int|string => $migrator->executeStatement(
                "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id)
                 SELECT organization_id, id, NULL, project_id, 'todo', ?, ?, 'build', 10, ?, 'all', reporter_id FROM tkt_ticket WHERE id = ?",
                [$turnUserId, $turnLabel, self::STORED_SINCE, $ticketId],
            ));
        } finally {
            $this->tenantContext()->clear();
        }
    }

    private function removeRows(): void
    {
        new TicketRows($this->migrator(), $this->tenantContext())->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function turnWaits(): TurnWaits
    {
        $turnWaits = self::getContainer()->get('test.insight_turn_waits');
        self::assertInstanceOf(TurnWaits::class, $turnWaits);

        return $turnWaits;
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
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
