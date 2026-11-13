<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insight;

use App\Insight\Api\TurnProjector;
use App\Insight\Application\Turn\TicketTurnFacts;
use App\Insight\Application\Turn\TicketTurnSource;
use App\Insight\Application\Turn\TurnRowResolver;
use App\Insight\Infrastructure\Doctrine\DbalProjectCountsProjector;
use App\Insight\Infrastructure\Doctrine\DbalTeamLoadQueries;
use App\Insight\Infrastructure\Doctrine\DbalTicketTurnSource;
use App\Insight\Infrastructure\Doctrine\DbalTurnProjector;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Tenancy\OwnConnectionTransactions;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use App\Tests\Integration\Ticketing\ShopTickets;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketTurnQueries;
use App\Workflow\Api\NeedsYouScore;
use App\Workflow\Api\TeamMemberLoad;
use App\Workflow\Api\TurnRules;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

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
    private const string CODE_REVIEW_STEP = '0197a3c0-0000-7000-8000-00000000b741';
    private const string DONE_STEP = '0197a3c0-0000-7000-8000-00000000b742';

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

        $factsUnderAcmeContext = $this->ownConnectionTransactions()->rolledBackUnderAnotherContext(self::BETA_BAKERY, self::ACME_FOODS, fn (Connection $connection): ?TicketTurnFacts => new DbalTicketTurnSource(self::turnQueriesReturning($snapshotUnderAcme), new DbalTeamLoadQueries($connection, $this->tenantContext()), $connection)->factsFor($ticketId));

        self::assertNotNull($factsUnderAcmeContext);
        self::assertSame([self::LENA => [0, 0], self::OMAR => [0, 0]], self::loadsOf($factsUnderAcmeContext->team->developers));
    }

    /**
     * @return iterable<string, array{string, string|null, array{string, string|null, string}|null, array{string, bool}|null}>
     *                                                                                                                         the ticket column changed and its new value, then the turn row's group, person
     *                                                                                                                         and kind and the ticket's count placement (visibility, done) expected after it
     */
    public static function ticketChanges(): iterable
    {
        yield 'to do, now restricted to its team' => ['visibility', 'team', ['todo', self::OMAR, 'build'], ['team', false]];
        yield 'in code review' => ['step_id', self::CODE_REVIEW_STEP, ['check', self::LENA, 'review'], ['all', false]];
        yield 'done' => ['step_id', self::DONE_STEP, null, ['all', true]];
        yield 'owned by no team' => ['team_id', null, null, null];
    }

    /**
     * Lena's own ticket shares the count row, so a count that moves must move by Omar's ticket alone. Each change
     * leaves the stored row and count of Omar's ticket stale, so both paths must write something.
     *
     * @param array{string, string|null, string}|null $expectedTurn
     * @param array{string, bool}|null                $expectedPlacement
     */
    #[Test]
    #[DataProvider('ticketChanges')]
    public function refreshFromTheTicketsSnapshotWritesTheTurnRowAndCountThatRefreshWrites(string $column, ?string $value, ?array $expectedTurn, ?array $expectedPlacement): void
    {
        $this->addCodeReviewAndDoneSteps();
        $ticketId = $this->createdTicketFor(self::OMAR);
        $this->createdTicketFor(self::LENA);
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            "UPDATE tkt_ticket SET {$column} = ? WHERE id = ?",
            [$value, $ticketId->toString()],
        ));
        $stale = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->projectedStateOf($ticketId));

        $refreshed = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            $this->projectorAtFixedTime()->refresh($ticketId),
            $this->projectedStateOf($ticketId),
        ]);
        $refreshedFrom = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($ticketId): array {
            $snapshot = $this->turnQueries()->factsOf($ticketId);
            self::assertNotNull($snapshot);

            return [$this->projectorAtFixedTime()->refreshFrom($snapshot), $this->projectedStateOf($ticketId)];
        });
        $refreshedFromGivenWaitingTurns = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($ticketId): array {
            $snapshot = $this->turnQueries()->factsOf($ticketId);
            self::assertNotNull($snapshot);
            $waitingTurns = new DbalTeamLoadQueries($this->connection(), $this->tenantContext())->waitingTurnsOf([...$snapshot->developerIds, ...$snapshot->testerIds], $snapshot->ticketId);

            return [$this->projectorAtFixedTime()->refreshFrom($snapshot, $waitingTurns), $this->projectedStateOf($ticketId)];
        });

        self::assertSame(
            [$expectedTurn, $expectedPlacement],
            [
                [] === $refreshed[1]['turn'] ? null : [$refreshed[1]['turn'][0]['grp'], $refreshed[1]['turn'][0]['turn_user_id'], $refreshed[1]['turn'][0]['kind']],
                [] === $refreshed[1]['placement'] ? null : [$refreshed[1]['placement'][0]['visibility'], $refreshed[1]['placement'][0]['is_done']],
            ],
        );
        self::assertNotSame($stale, $refreshed[1]);
        self::assertSame($refreshed, $refreshedFrom);
        self::assertSame($refreshed, $refreshedFromGivenWaitingTurns);
    }

    /**
     * With the waiting reviews and tests given, as a verb move passes the ones it read, only the count placement and
     * the turn upsert run.
     */
    #[Test]
    public function refreshFromWithGivenWaitingTurnsReadsNoLoads(): void
    {
        $ticketId = $this->createdTicketFor(self::OMAR);
        $debugData = $this->debugData();

        $statements = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($ticketId, $debugData): array {
            $snapshot = $this->turnQueries()->factsOf($ticketId);
            self::assertNotNull($snapshot);
            $debugData->reset();
            $this->projectorAtFixedTime()->refreshFrom($snapshot, []);

            return self::sqlOf($debugData);
        });

        self::assertSame(['INSERT INTO ins_project_count_ticket', 'INSERT INTO ins_ticket_turn'], [
            substr($statements[0] ?? '', 0, \strlen('INSERT INTO ins_project_count_ticket')),
            substr($statements[1] ?? '', 0, \strlen('INSERT INTO ins_ticket_turn')),
        ]);
        self::assertCount(2, $statements);
    }

    /**
     * The snapshot carries the ticket and its team's people, so what is left is the team's waiting reviews and tests,
     * the count placement, which writes nothing more while it stays, and the turn upsert.
     */
    #[Test]
    public function refreshFromAnOpenTicketWhoseCountStaysRunsThreeQueries(): void
    {
        $ticketId = $this->createdTicketFor(self::OMAR);
        $debugData = $this->debugData();

        $statements = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($ticketId, $debugData): array {
            $snapshot = $this->turnQueries()->factsOf($ticketId);
            self::assertNotNull($snapshot);
            $debugData->reset();
            $this->projectorAtFixedTime()->refreshFrom($snapshot);

            return self::sqlOf($debugData);
        });

        self::assertSame(
            ['SELECT turn_user_id, kind, count(*) AS tickets FROM ins_ticket_turn', 'INSERT INTO ins_project_count_ticket', 'INSERT INTO ins_ticket_turn'],
            [
                substr($statements[0] ?? '', 0, \strlen('SELECT turn_user_id, kind, count(*) AS tickets FROM ins_ticket_turn')),
                substr($statements[1] ?? '', 0, \strlen('INSERT INTO ins_project_count_ticket')),
                substr($statements[2] ?? '', 0, \strlen('INSERT INTO ins_ticket_turn')),
            ],
        );
        self::assertCount(3, $statements);
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
            new DbalTicketTurnSource(self::turnQueriesReturning($snapshotUnderAcme), new DbalTeamLoadQueries($this->connection(), $this->tenantContext()), $this->connection())->factsFor($ticketId);
            self::fail('The turn facts were read with no transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame(
                ['Turn facts for the projector are read in a transaction under the organization as tenant; none is open.', []],
                [$refusal->getMessage(), array_filter($debugData->getData(), static fn (mixed $queriesOfOneConnection): bool => [] !== $queriesOfOneConnection)],
            );
        }
    }

    #[Test]
    public function factsFromASnapshotWithNoTransactionAreRefusedBeforeAnyQuery(): void
    {
        $ticketId = $this->createdTicketFor(self::OMAR);
        $snapshotUnderAcme = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?TicketTurnSnapshot => $this->turnQueries()->factsOf($ticketId));
        self::assertNotNull($snapshotUnderAcme);
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $debugData = $this->debugData();
        $debugData->reset();

        try {
            $this->source()->factsFrom($snapshotUnderAcme);
            self::fail('The turn facts were built with no transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame(
                ['Turn facts for the projector are read in a transaction under the organization as tenant; none is open.', []],
                [$refusal->getMessage(), self::sqlOf($debugData)],
            );
        }
    }

    #[Test]
    public function refreshFromWithNoTransactionIsRefusedBeforeAnyQuery(): void
    {
        $ticketId = $this->createdTicketFor(self::OMAR);
        $snapshotUnderAcme = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?TicketTurnSnapshot => $this->turnQueries()->factsOf($ticketId));
        self::assertNotNull($snapshotUnderAcme);
        $storedTurns = $this->storedTurnsOf($ticketId);
        self::assertNotSame([], $storedTurns);
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $debugData = $this->debugData();
        $debugData->reset();

        try {
            $this->projectorAtFixedTime()->refreshFrom($snapshotUnderAcme);
            self::fail('The turn row was refreshed with no transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame(
                ['A turn row is refreshed in the transaction of the change; none is open.', []],
                [$refusal->getMessage(), self::sqlOf($debugData)],
            );
        }
        $this->tenantContext()->clear();
        self::assertSame($storedTurns, $this->storedTurnsOf($ticketId));
    }

    /**
     * refreshFrom trusts the snapshot's ticket id, so what stops a Beta Bakery snapshot from writing under Acme Foods is
     * the composite foreign key of ins_project_count_ticket on tkt_ticket, the first write. Beta Bakery's team and
     * project reuse Acme Foods' ids, so without that key the ticket would be counted in Acme Foods' own count row.
     */
    #[Test]
    public function refreshFromASnapshotOfAnotherOrganizationsTicketWritesNothing(): void
    {
        $this->createdTicketFor(self::OMAR);
        $betaTicketId = $this->betaBakeryTicketFor(self::OMAR);
        $this->transactions()->committedAs(self::BETA_BAKERY, fn (): ?string => $this->projector()->refresh($betaTicketId));
        $betaSnapshot = $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): ?TicketTurnSnapshot => $this->turnQueries()->factsOf($betaTicketId));
        self::assertNotNull($betaSnapshot);
        $acmeBefore = $this->turnStateOfOrganization(self::ACME_FOODS);
        $betaBefore = $this->turnStateOfOrganization(self::BETA_BAKERY);
        self::assertNotSame([], $acmeBefore['turns']);
        self::assertNotSame([], $acmeBefore['placements']);
        self::assertNotSame([], $betaBefore['turns']);
        self::assertNotSame([], $betaBefore['placements']);

        try {
            $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?string => $this->projectorAtFixedTime()->refreshFrom($betaSnapshot));
            self::fail('A Beta Bakery snapshot was projected under Acme Foods.');
        } catch (ForeignKeyConstraintViolationException $refusal) {
            self::assertStringContainsString('ins_project_count_ticket_ticket_fkey', $refusal->getMessage());
        }
        self::getContainer()->get('doctrine')->resetManager();

        self::assertSame($acmeBefore, $this->turnStateOfOrganization(self::ACME_FOODS));
        self::assertSame($betaBefore, $this->turnStateOfOrganization(self::BETA_BAKERY));
    }

    /**
     * A ticket of Beta Bakery, assigned to the person, in a team and project with Acme Foods' ids and To do step, whose
     * lead is Lena and whose developers are Lena and Omar.
     */
    private function betaBakeryTicketFor(string $assigneeId): TicketId
    {
        $shopTeamRows = $this->shopTeamRows();
        $shopTeamRows->add(self::BETA_BAKERY, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, []);
        $shopTeamRows->addMember(self::BETA_BAKERY, self::SHOP_TEAM, self::LENA, 'lead', 'developer');
        $shopTeamRows->addMember(self::BETA_BAKERY, self::SHOP_TEAM, self::OMAR, 'member', 'developer');
        $this->ticketRows()->addWithAudienceOf(self::BETA_BAKERY, self::BETA_BAKERY_TICKET, self::SHOP_PROJECT, 1, 'all', self::SHOP_TEAM, self::MIA, ['assignee_id' => $assigneeId]);
        $this->transactions()->committedAs(self::BETA_BAKERY, fn (): int|string => $this->connection()->executeStatement(
            'UPDATE tkt_ticket SET step_id = ? WHERE id = ?',
            [ShopTeamRows::TO_DO_STEP, self::BETA_BAKERY_TICKET],
        ));

        return new TicketId(self::BETA_BAKERY_TICKET);
    }

    /**
     * @return array{turns: list<array<string, mixed>>, placements: list<array<string, mixed>>, counts: list<array<string, mixed>>}
     */
    private function turnStateOfOrganization(string $organizationId): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => [
            'turns' => $this->connection()->fetchAllAssociative(
                'SELECT organization_id, ticket_id, team_id, project_id, grp, turn_user_id, kind, waiting_since FROM ins_ticket_turn ORDER BY ticket_id',
            ),
            'placements' => $this->connection()->fetchAllAssociative(
                'SELECT organization_id, ticket_id, project_id, team_id, visibility, is_done FROM ins_project_count_ticket ORDER BY ticket_id',
            ),
            'counts' => $this->connection()->fetchAllAssociative(
                'SELECT organization_id, project_id, team_id, visibility, open_count, done_count FROM ins_project_counts ORDER BY project_id, team_id, visibility',
            ),
        ]);
    }

    private function addCodeReviewAndDoneSteps(): void
    {
        $migrator = self::getContainer()->get('doctrine.dbal.migrator_connection');
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $migrator->transactional(static function (Connection $connection): void {
                foreach ([[self::CODE_REVIEW_STEP, 'Code review', 'check', 'review', 2], [self::DONE_STEP, 'Done', 'done', 'done', 3]] as [$stepId, $name, $groupCode, $coreCode, $position]) {
                    $connection->executeStatement(
                        "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, ?, ?, ?, 'core', ?)",
                        [self::ACME_FOODS, $stepId, $name, $groupCode, $coreCode, $position],
                    );
                }
            });
        } finally {
            $this->tenantContext()->clear();
        }
    }

    /**
     * @return array{turn: list<array<string, mixed>>, placement: list<array<string, mixed>>, counts: list<array<string, mixed>>}
     */
    private function projectedStateOf(TicketId $ticketId): array
    {
        return [
            'turn' => $this->connection()->fetchAllAssociative(
                'SELECT team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, check_user_id, visibility,
                        reporter_id, assignee_id, pending_assignee_id, reviewer_id, tester_id, waiting_on_user_id
                 FROM ins_ticket_turn WHERE ticket_id = ?',
                [$ticketId->toString()],
            ),
            'placement' => $this->connection()->fetchAllAssociative(
                'SELECT project_id, team_id, visibility, is_done FROM ins_project_count_ticket WHERE ticket_id = ?',
                [$ticketId->toString()],
            ),
            'counts' => $this->connection()->fetchAllAssociative(
                'SELECT project_id, team_id, visibility, open_count, done_count FROM ins_project_counts ORDER BY project_id, team_id, visibility',
            ),
        ];
    }

    private function projectorAtFixedTime(): DbalTurnProjector
    {
        $turnRules = self::getContainer()->get('test.workflow_turn_rules');
        self::assertInstanceOf(TurnRules::class, $turnRules);
        $needsYouScore = self::getContainer()->get('test.workflow_needs_you_score');
        self::assertInstanceOf(NeedsYouScore::class, $needsYouScore);

        return new DbalTurnProjector(
            $this->source(),
            new TurnRowResolver($turnRules, $needsYouScore),
            new DbalProjectCountsProjector($this->connection(), $this->tenantContext()),
            $this->connection(),
            $this->tenantContext(),
            new MockClock('2027-03-16 09:30:00+00:00'),
        );
    }

    /**
     * @return list<string>
     */
    private static function sqlOf(DebugDataHolder $debugData): array
    {
        $sql = [];
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            self::assertIsArray($queriesOfOneConnection);
            foreach ($queriesOfOneConnection as $query) {
                self::assertIsArray($query);
                self::assertIsString($query['sql']);
                $sql[] = $query['sql'];
            }
        }

        return $sql;
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
