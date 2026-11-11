<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insight;

use App\Insight\Api\TurnProjector;
use App\Insight\Application\Turn\TicketTurnFacts;
use App\Insight\Application\Turn\TicketTurnSource;
use App\Insight\Infrastructure\Doctrine\DbalTicketTurnSource;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Tenancy\OwnConnectionTransactions;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use App\Tests\Integration\Ticketing\ShopTickets;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketTurnSnapshot;
use App\Workflow\Api\TeamMemberLoad;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The container's TicketTurnSource and TurnProjector on a ticket TicketCommands creates, with Ticketing's real query
 * behind the source, as pinround_app under row-level security.
 */
final class DbalTicketTurnSourceTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000b701';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000b702';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000b711';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000b721';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000b751';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000b752';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000b753';
    private const string RAJ = '0197a3c0-0000-7000-8000-00000000b754';
    private const string SARA = '0197a3c0-0000-7000-8000-00000000b755';
    private const string SECOND_TESTER = '0197a3c0-0000-7000-8000-00000000b756';
    private const string FIRST_OTHER_TICKET = '0197a3c0-0000-7000-8000-00000000b731';
    private const string SECOND_OTHER_TICKET = '0197a3c0-0000-7000-8000-00000000b732';
    private const string THIRD_OTHER_TICKET = '0197a3c0-0000-7000-8000-00000000b733';
    private const string FOURTH_OTHER_TICKET = '0197a3c0-0000-7000-8000-00000000b734';
    private const string BETA_BAKERY_TICKET = '0197a3c0-0000-7000-8000-00000000b735';

    protected function setUp(): void
    {
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
            self::MIA => ['Mia', 'member', 'tester'],
            self::RAJ => ['Raj', 'viewer', 'other'],
        ]);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function theProjectorWritesOmarsBuildTurnForANewTicketOfHis(): void
    {
        $ticketId = $this->createdTicketFor(self::OMAR);
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            'DELETE FROM ins_ticket_turn WHERE ticket_id = ?',
            [$ticketId->toString()],
        ));
        self::assertSame([], $this->storedTurnsOf($ticketId));

        $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?string => $this->projector()->refresh($ticketId));

        self::assertSame(
            [['team_id' => self::SHOP_TEAM, 'project_id' => self::SHOP_PROJECT, 'grp' => 'todo', 'turn_user_id' => self::OMAR, 'kind' => 'build', 'reporter_id' => self::MIA, 'assignee_id' => self::OMAR]],
            $this->storedTurnsOf($ticketId),
        );
    }

    #[Test]
    public function anotherOrganizationGetsNoFactsForTheTicketId(): void
    {
        $ticketId = $this->createdTicketFor(self::OMAR);

        self::assertNotNull($this->factsAs(self::ACME_FOODS, $ticketId));
        self::assertNull($this->factsAs(self::BETA_BAKERY, $ticketId));
    }

    #[Test]
    public function aTicketNoTeamOwnsGetsNoFacts(): void
    {
        $ticketId = $this->createdTicketFor(self::OMAR);
        self::assertNotNull($this->factsAs(self::ACME_FOODS, $ticketId));

        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            'UPDATE tkt_ticket SET team_id = NULL WHERE id = ?',
            [$ticketId->toString()],
        ));

        self::assertNull($this->factsAs(self::ACME_FOODS, $ticketId));
    }

    /**
     * The ticket's own row is a review for Omar too, and Beta Bakery's review for Omar shares nothing with Acme Foods,
     * so counting either would give Omar more than two reviews.
     */
    #[Test]
    public function theLoadsCountOtherTicketsReviewsAndTestsPerPersonButNotTheTicketsOwnTurn(): void
    {
        $ticketId = $this->createdTicketFor(self::OMAR);
        $ticketRows = $this->ticketRows();
        foreach ([self::FIRST_OTHER_TICKET, self::SECOND_OTHER_TICKET, self::THIRD_OTHER_TICKET, self::FOURTH_OTHER_TICKET] as $index => $otherTicketId) {
            $ticketRows->addOf(self::ACME_FOODS, $otherTicketId, self::SHOP_PROJECT, self::MIA, $index + 1);
        }
        $ticketRows->addOf(self::BETA_BAKERY, self::BETA_BAKERY_TICKET, self::SHOP_PROJECT, self::MIA);
        $this->addTurn(self::ACME_FOODS, self::FIRST_OTHER_TICKET, self::OMAR, 'review');
        $this->addTurn(self::ACME_FOODS, self::SECOND_OTHER_TICKET, self::OMAR, 'review');
        $this->addTurn(self::ACME_FOODS, self::THIRD_OTHER_TICKET, self::MIA, 'test');
        $this->addTurn(self::ACME_FOODS, self::FOURTH_OTHER_TICKET, self::LENA, 'build');
        $this->addTurn(self::BETA_BAKERY, self::BETA_BAKERY_TICKET, self::OMAR, 'review');
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            "UPDATE ins_ticket_turn SET grp = 'check', kind = 'review' WHERE ticket_id = ?",
            [$ticketId->toString()],
        ));

        $facts = $this->factsAs(self::ACME_FOODS, $ticketId);

        self::assertNotNull($facts);
        self::assertSame(
            [[self::LENA => [0, 0], self::OMAR => [2, 0]], [self::MIA => [0, 1]]],
            [self::loadsOf($facts->team->developers), self::loadsOf($facts->team->testers)],
        );
    }

    #[Test]
    public function theTicketStateCarriesTheTicketsColumns(): void
    {
        $ticketId = $this->createdTicketFor(self::OMAR);
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            "UPDATE tkt_ticket SET pending_assignee_id = ?, reviewer_id = ?, tester_id = ?, waiting_on_user_id = ?,
                    waiting_on_label = 'Supplier price list', previous_step_id = ?, release = '2027.3', check_reasons = '{risk}'
             WHERE id = ?",
            [self::RAJ, self::LENA, self::SECOND_TESTER, self::SARA, ShopTeamRows::BACKLOG_STEP, $ticketId->toString()],
        ));

        $facts = $this->factsAs(self::ACME_FOODS, $ticketId);

        self::assertNotNull($facts);
        $ticket = $facts->ticket;
        self::assertSame(
            [
                ShopTeamRows::TO_DO_STEP, 'todo', 'todo', self::SHOP_TEAM, self::OMAR, self::LENA, self::SECOND_TESTER, self::RAJ,
                ShopTeamRows::BACKLOG_STEP, self::MIA, self::SARA, 'Supplier price list', 'major', '2027.3',
                $ticketId->toString(), self::SHOP_PROJECT, 'all', true,
            ],
            [
                $ticket->stepId, $ticket->coreCode, $ticket->groupCode, $ticket->teamId?->toString(), $ticket->assigneeId?->toString(),
                $ticket->reviewerId?->toString(), $ticket->testerId?->toString(), $ticket->pendingAssigneeId?->toString(),
                $ticket->previousStepId, $ticket->reporterId?->toString(), $ticket->waitingOnUserId?->toString(),
                $ticket->waitingOnLabel, $ticket->priority, $ticket->release,
                $facts->ticketId->toString(), $facts->projectId, $facts->visibility, $facts->isRiskCheck,
            ],
        );
    }

    /**
     * The SQL names the organization of TenantContext, while the transaction's tenant, which row-level security uses,
     * is another one: what still hides Acme Foods' review turns from Beta Bakery's transaction is row-level security
     * alone. The ticket's snapshot is the one read under Acme Foods, so only the load query runs under Beta Bakery.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsTurnsFromTheLoads(): void
    {
        $ticketId = $this->createdTicketFor(self::OMAR);
        $ticketRows = $this->ticketRows();
        $ticketRows->addOf(self::ACME_FOODS, self::FIRST_OTHER_TICKET, self::SHOP_PROJECT, self::MIA, 1);
        $ticketRows->addOf(self::ACME_FOODS, self::SECOND_OTHER_TICKET, self::SHOP_PROJECT, self::MIA, 2);
        $this->addTurn(self::ACME_FOODS, self::FIRST_OTHER_TICKET, self::OMAR, 'review');
        $this->addTurn(self::ACME_FOODS, self::SECOND_OTHER_TICKET, self::OMAR, 'review');
        $factsUnderAcme = $this->factsAs(self::ACME_FOODS, $ticketId);
        self::assertNotNull($factsUnderAcme);
        self::assertSame([self::LENA => [0, 0], self::OMAR => [2, 0]], self::loadsOf($factsUnderAcme->team->developers));
        $snapshotUnderAcme = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?TicketTurnSnapshot => $this->turnQueries()->factsOf($ticketId));
        self::assertNotNull($snapshotUnderAcme);

        $factsUnderAcmeContext = $this->ownConnectionTransactions()->rolledBackUnderAnotherContext(self::BETA_BAKERY, self::ACME_FOODS, fn (Connection $connection): ?TicketTurnFacts => new DbalTicketTurnSource(self::turnQueriesReturning($snapshotUnderAcme), $connection, $this->tenantContext())->factsFor($ticketId));

        self::assertNotNull($factsUnderAcmeContext);
        self::assertSame([self::LENA => [0, 0], self::OMAR => [0, 0]], self::loadsOf($factsUnderAcmeContext->team->developers));
    }

    /**
     * Ticketing's query is replaced by one that needs no transaction, so it is the source's own check that refuses.
     */
    #[Test]
    public function withNoTransactionItRefusesBeforeAnyQuery(): void
    {
        $ticketId = $this->createdTicketFor(self::OMAR);
        $snapshotUnderAcme = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?TicketTurnSnapshot => $this->turnQueries()->factsOf($ticketId));
        self::assertNotNull($snapshotUnderAcme);
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $debugData = $this->debugData();
        $debugData->reset();

        try {
            new DbalTicketTurnSource(self::turnQueriesReturning($snapshotUnderAcme), $this->connection(), $this->tenantContext())->factsFor($ticketId);
            self::fail('The turn facts were read with no transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame(
                ['Turn facts for the projector are read in a transaction under the organization as tenant; none is open.', []],
                [$refusal->getMessage(), array_filter($debugData->getData(), static fn (mixed $queriesOfOneConnection): bool => [] !== $queriesOfOneConnection)],
            );
        }
    }

    private static function turnQueriesReturning(TicketTurnSnapshot $snapshot): TicketTurnQueries
    {
        return new readonly class($snapshot) implements TicketTurnQueries {
            public function __construct(private TicketTurnSnapshot $snapshot)
            {
            }

            public function factsOf(TicketId $ticketId): TicketTurnSnapshot
            {
                return $this->snapshot;
            }
        };
    }

    private function debugData(): DebugDataHolder
    {
        return self::getContainer()->get('doctrine.debug_data_holder');
    }

    /**
     * @param list<TeamMemberLoad> $members
     *
     * @return array<string, array{int, int}> open reviews and tickets in testing, by user id in user id order
     */
    private static function loadsOf(array $members): array
    {
        $loads = [];
        foreach ($members as $member) {
            $loads[$member->userId->toString()] = [$member->openReviews, $member->ticketsInTesting];
        }
        ksort($loads);

        return $loads;
    }

    private function createdTicketFor(string $assigneeId): TicketId
    {
        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        self::assertInstanceOf(TicketCommands::class, $ticketCommands);

        return new ShopTickets($ticketCommands, $this->transactions(), $this->connection())
            ->createdFor(self::ACME_FOODS, self::SHOP_PROJECT, self::MIA, $assigneeId);
    }

    private function addTurn(string $organizationId, string $ticketId, string $userId, string $kind): void
    {
        $this->transactions()->committedAs($organizationId, fn (): int|string => $this->connection()->executeStatement(
            "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, kind, base_score, waiting_since, visibility, reporter_id)
             VALUES (?, ?, NULL, ?, 'check', ?, ?, 100, '2027-03-15 10:00:00+00', 'all', ?)",
            [$organizationId, $ticketId, self::SHOP_PROJECT, $userId, $kind, self::MIA],
        ));
    }

    private function factsAs(string $organizationId, TicketId $ticketId): ?TicketTurnFacts
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): ?TicketTurnFacts => $this->source()->factsFor($ticketId));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storedTurnsOf(TicketId $ticketId): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllAssociative(
            'SELECT team_id, project_id, grp, turn_user_id, kind, reporter_id, assignee_id FROM ins_ticket_turn WHERE ticket_id = ?',
            [$ticketId->toString()],
        ));
    }

    private function source(): TicketTurnSource
    {
        $source = self::getContainer()->get('test.insight_ticket_turn_source');
        self::assertInstanceOf(TicketTurnSource::class, $source);

        return $source;
    }

    private function turnQueries(): TicketTurnQueries
    {
        $turnQueries = self::getContainer()->get('test.ticketing_ticket_turn_queries');
        self::assertInstanceOf(TicketTurnQueries::class, $turnQueries);

        return $turnQueries;
    }

    private function projector(): TurnProjector
    {
        $projector = self::getContainer()->get('test.insight_turn_projector');
        self::assertInstanceOf(TurnProjector::class, $projector);

        return $projector;
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

    private function ownConnectionTransactions(): OwnConnectionTransactions
    {
        return new OwnConnectionTransactions($this->connection(), $this->tenantContext());
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
