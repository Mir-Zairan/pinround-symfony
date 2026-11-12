<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insight;

use App\Insight\Api\TeamLoadQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The container's TeamLoadQueries as pinround_app under row-level security, on turn rows made by hand.
 */
final class DbalTeamLoadQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000d701';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000d702';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000d711';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000d721';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000d751';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000d752';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000d753';
    private const string RAJ = '0197a3c0-0000-7000-8000-00000000d754';
    private const string OWN_TICKET = '0197a3c0-0000-7000-8000-00000000d731';
    private const string SECOND_TICKET = '0197a3c0-0000-7000-8000-00000000d732';
    private const string THIRD_TICKET = '0197a3c0-0000-7000-8000-00000000d733';
    private const string FOURTH_TICKET = '0197a3c0-0000-7000-8000-00000000d734';
    private const string FIFTH_TICKET = '0197a3c0-0000-7000-8000-00000000d736';
    private const string BETA_BAKERY_TICKET = '0197a3c0-0000-7000-8000-00000000d735';

    protected function setUp(): void
    {
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
            self::MIA => ['Mia', 'member', 'tester'],
            self::RAJ => ['Raj', 'viewer', 'other'],
        ]);
        $ticketRows = $this->ticketRows();
        foreach ([self::OWN_TICKET, self::SECOND_TICKET, self::THIRD_TICKET, self::FOURTH_TICKET, self::FIFTH_TICKET] as $index => $ticketId) {
            $ticketRows->addOf(self::ACME_FOODS, $ticketId, self::SHOP_PROJECT, self::MIA, $index + 1);
        }
        $ticketRows->addOf(self::BETA_BAKERY, self::BETA_BAKERY_TICKET, self::SHOP_PROJECT, self::MIA);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRows();
        parent::tearDown();
    }

    /**
     * Omar has two other reviews and the asked ticket's own, which is left out; Mia one test; Lena only a build turn,
     * which is no load; Beta Bakery's review for Omar belongs to another organization.
     */
    #[Test]
    public function itCountsReviewsAndTestsPerAskedPersonLeavingOutTheTicketItselfAndOtherKinds(): void
    {
        $this->addTurn(self::ACME_FOODS, self::OWN_TICKET, self::OMAR, 'review');
        $this->addTurn(self::ACME_FOODS, self::SECOND_TICKET, self::OMAR, 'review');
        $this->addTurn(self::ACME_FOODS, self::THIRD_TICKET, self::OMAR, 'review');
        $this->addTurn(self::ACME_FOODS, self::FOURTH_TICKET, self::MIA, 'test');
        $this->addTurn(self::ACME_FOODS, self::FIFTH_TICKET, self::LENA, 'build');
        $this->addTurn(self::BETA_BAKERY, self::BETA_BAKERY_TICKET, self::OMAR, 'review');

        $waitingTurns = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->queries()->waitingTurnsOf(
            [new UserId(self::LENA), new UserId(self::OMAR), new UserId(self::MIA)],
            new TicketId(self::OWN_TICKET),
        ));

        ksort($waitingTurns);
        self::assertSame([self::OMAR => ['review' => 2], self::MIA => ['test' => 1]], $waitingTurns);
    }

    #[Test]
    public function anEmptyListOfPeopleReadsNothingAndRunsNoQuery(): void
    {
        $this->addTurn(self::ACME_FOODS, self::SECOND_TICKET, self::OMAR, 'review');

        $waitingTurns = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->queries()->waitingTurnsOf([], new TicketId(self::OWN_TICKET)));

        self::assertSame([], $waitingTurns);
    }

    #[Test]
    public function withNoTransactionItRefusesBeforeAnyQuery(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Waiting turns are read in a transaction under the organization as tenant; none is open.');

        $this->queries()->waitingTurnsOf([new UserId(self::OMAR)], new TicketId(self::OWN_TICKET));
    }

    private function addTurn(string $organizationId, string $ticketId, string $userId, string $kind): void
    {
        $this->transactions()->committedAs($organizationId, fn (): int|string => $this->connection()->executeStatement(
            "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, kind, base_score, waiting_since, visibility, reporter_id)
             VALUES (?, ?, NULL, ?, 'check', ?, ?, 100, '2027-03-15 10:00:00+00', 'all', ?)",
            [$organizationId, $ticketId, self::SHOP_PROJECT, $userId, $kind, self::MIA],
        ));
    }

    private function queries(): TeamLoadQueries
    {
        $queries = self::getContainer()->get('test.insight_team_load_queries');
        self::assertInstanceOf(TeamLoadQueries::class, $queries);

        return $queries;
    }

    private function removeRows(): void
    {
        $this->shopTeamRows()->removeAllOf([self::LENA, self::OMAR, self::MIA, self::RAJ], self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
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
