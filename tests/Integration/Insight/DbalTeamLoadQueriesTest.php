<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insight;

use App\Insight\Api\TeamLoadQueries;
use App\Insight\Api\TeamLoads;
use App\Insight\Infrastructure\Doctrine\DbalTeamLoadQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Tenancy\OwnConnectionTransactions;
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
    private const string SIXTH_TICKET = '0197a3c0-0000-7000-8000-00000000d737';
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
        foreach ([self::OWN_TICKET, self::SECOND_TICKET, self::THIRD_TICKET, self::FOURTH_TICKET, self::FIFTH_TICKET, self::SIXTH_TICKET] as $index => $ticketId) {
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
     * which is no load; Raj's review is for someone not asked about; Beta Bakery's review for Omar belongs to another organization.
     */
    #[Test]
    public function itCountsReviewsAndTestsPerAskedPersonLeavingOutTheTicketItselfAndOtherKinds(): void
    {
        $this->addTurn(self::ACME_FOODS, self::OWN_TICKET, self::OMAR, 'review');
        $this->addTurn(self::ACME_FOODS, self::SECOND_TICKET, self::OMAR, 'review');
        $this->addTurn(self::ACME_FOODS, self::THIRD_TICKET, self::OMAR, 'review');
        $this->addTurn(self::ACME_FOODS, self::FOURTH_TICKET, self::MIA, 'test');
        $this->addTurn(self::ACME_FOODS, self::FIFTH_TICKET, self::LENA, 'build');
        $this->addTurn(self::ACME_FOODS, self::SIXTH_TICKET, self::RAJ, 'review');
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

    /**
     * The ticket's own row comes back as its saved turn and is no load; the loads are the other tickets' rows.
     */
    #[Test]
    public function theSavedTurnOfTheTicketComesWithTheLoadsOfOthersAndNoneOfItsOwn(): void
    {
        $this->addTurn(self::ACME_FOODS, self::OWN_TICKET, self::OMAR, 'review');
        $this->addTurn(self::ACME_FOODS, self::SECOND_TICKET, self::OMAR, 'review');
        $this->addTurn(self::ACME_FOODS, self::THIRD_TICKET, self::MIA, 'test');
        $this->addTurn(self::ACME_FOODS, self::SIXTH_TICKET, self::RAJ, 'review');

        $loads = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): TeamLoads => $this->queries()->loadsAndSavedTurnOf(
            [new UserId(self::OMAR), new UserId(self::MIA)],
            new TicketId(self::OWN_TICKET),
        ));

        $waitingTurns = $loads->waitingTurns;
        ksort($waitingTurns);
        self::assertSame([self::OMAR => ['review' => 1], self::MIA => ['test' => 1]], $waitingTurns);
        self::assertSame([self::OMAR, null, 'review'], [$loads->savedTurn?->userId?->toString(), $loads->savedTurn?->label, $loads->savedTurn?->kind]);
    }

    #[Test]
    public function aTicketWithNoTurnRowHasNoSavedTurnAndStillGetsTheLoadsOfOthers(): void
    {
        $this->addTurn(self::ACME_FOODS, self::SECOND_TICKET, self::OMAR, 'review');

        $loads = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): TeamLoads => $this->queries()->loadsAndSavedTurnOf([new UserId(self::OMAR)], new TicketId(self::OWN_TICKET)));

        self::assertSame([[self::OMAR => ['review' => 1]], null], [$loads->waitingTurns, $loads->savedTurn]);
    }

    #[Test]
    public function aSavedTurnOfALabelHasNoUserAndNoPeopleAskedStillReadsIt(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id)
             VALUES (?, ?, NULL, ?, 'wait', NULL, 'Customer', 'input', 100, '2027-03-15 10:00:00+00', 'all', ?)",
            [self::ACME_FOODS, self::OWN_TICKET, self::SHOP_PROJECT, self::MIA],
        ));

        $loads = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): TeamLoads => $this->queries()->loadsAndSavedTurnOf([], new TicketId(self::OWN_TICKET)));

        self::assertSame([[], null, 'Customer', 'input'], [$loads->waitingTurns, $loads->savedTurn?->userId, $loads->savedTurn?->label, $loads->savedTurn?->kind]);
    }

    #[Test]
    public function anotherOrganizationsSavedTurnIsNeverRead(): void
    {
        $this->addTurn(self::BETA_BAKERY, self::BETA_BAKERY_TICKET, self::OMAR, 'review');

        $loads = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): TeamLoads => $this->queries()->loadsAndSavedTurnOf([new UserId(self::OMAR)], new TicketId(self::BETA_BAKERY_TICKET)));

        self::assertSame([[], null], [$loads->waitingTurns, $loads->savedTurn]);
    }

    /**
     * The SQL names the organization of TenantContext, while the transaction's tenant, which row-level security uses,
     * is another one: what still hides Acme Foods' turn rows from Beta Bakery's transaction is row-level security alone.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsSavedTurnAndLoads(): void
    {
        $this->addTurn(self::ACME_FOODS, self::OWN_TICKET, self::OMAR, 'review');
        $this->addTurn(self::ACME_FOODS, self::SECOND_TICKET, self::OMAR, 'review');
        $loadsUnderAcme = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): TeamLoads => $this->queries()->loadsAndSavedTurnOf([new UserId(self::OMAR)], new TicketId(self::OWN_TICKET)));
        self::assertNotNull($loadsUnderAcme->savedTurn);
        self::assertNotSame([], $loadsUnderAcme->waitingTurns);

        $loads = new OwnConnectionTransactions($this->connection(), $this->tenantContext())->rolledBackUnderAnotherContext(
            self::BETA_BAKERY,
            self::ACME_FOODS,
            fn (Connection $connection): TeamLoads => new DbalTeamLoadQueries($connection, $this->tenantContext())
                ->loadsAndSavedTurnOf([new UserId(self::OMAR)], new TicketId(self::OWN_TICKET)),
        );

        self::assertSame([[], null], [$loads->waitingTurns, $loads->savedTurn]);
    }

    /**
     * The transaction is open but no tenant is set: the tenant check refuses before any query, the two reads alike.
     */
    #[Test]
    public function withATransactionButNoTenantItRefusesBeforeAnyQuery(): void
    {
        $this->tenantContext()->reset();
        $connection = $this->connection();
        $connection->beginTransaction();
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();
        $refused = [];

        try {
            foreach ([
                fn () => $this->queries()->waitingTurnsOf([new UserId(self::OMAR)], new TicketId(self::OWN_TICKET)),
                fn () => $this->queries()->loadsAndSavedTurnOf([new UserId(self::OMAR)], new TicketId(self::OWN_TICKET)),
            ] as $read) {
                try {
                    $read();
                } catch (\LogicException $refusal) {
                    $refused[] = $refusal::class;
                }
            }
            $selects = array_filter(array_merge(...array_values($debugData->getData())), static fn (array $query): bool => \is_string($query['sql']) && str_contains($query['sql'], 'ins_ticket_turn'));
        } finally {
            $connection->rollBack();
        }

        self::assertSame([TenantNotSet::class, TenantNotSet::class], $refused);
        self::assertSame([], $selects);
    }

    #[Test]
    public function withNoTransactionItRefusesBeforeAnyQuery(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Waiting turns are read in a transaction under the organization as tenant; none is open.');

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
