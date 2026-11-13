<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insight;

use App\Insight\Api\TicketViewer;
use App\Insight\Api\WaitingTurnCount;
use App\Insight\Api\WaitingTurnCounts;
use App\Insight\Application\Turn\TicketTurnFacts;
use App\Insight\Application\Turn\TurnRowResolver;
use App\Insight\Infrastructure\Doctrine\DbalProjectCountsProjector;
use App\Insight\Infrastructure\Doctrine\DbalTurnProjector;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\ReadsSentSql;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Workflow\Api\NeedsYouScore;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\TurnRules;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The container's WaitingTurnCounts as pinround_app under row-level security, on turn rows made by hand for tickets
 * everyone may see, so only the people, team and project filters decide; WaitingTurnCountsLeakTest covers visibility.
 */
final class DbalWaitingTurnCountsTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000e801';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000e802';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000e811';
    private const string OPS_TEAM = '0197a3c0-0000-7000-8000-00000000e812';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000e821';
    private const string OPS_PROJECT = '0197a3c0-0000-7000-8000-00000000e822';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000e851';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000e852';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000e853';
    private const string RAJ = '0197a3c0-0000-7000-8000-00000000e854';
    private const array TICKETS = [
        '0197a3c0-0000-7000-8000-00000000e831',
        '0197a3c0-0000-7000-8000-00000000e832',
        '0197a3c0-0000-7000-8000-00000000e833',
        '0197a3c0-0000-7000-8000-00000000e834',
        '0197a3c0-0000-7000-8000-00000000e835',
        '0197a3c0-0000-7000-8000-00000000e836',
        '0197a3c0-0000-7000-8000-00000000e837',
    ];
    private const string BETA_BAKERY_TICKET = '0197a3c0-0000-7000-8000-00000000e83b';

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
        foreach (self::TICKETS as $index => $ticketId) {
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
     * Added out of order, with Mia's two waits tied: Omar waits on a build, a review and, in another team and project,
     * an input, whose time is the oldest; Mia on a test and an answer; Raj, not asked, on a decision; a Customer label on
     * one; Beta Bakery's review for Omar is another organization's. Lena has nothing waiting.
     */
    private function addTheWaits(): void
    {
        $this->addTurn(self::ACME_FOODS, self::TICKETS[0], self::SHOP_TEAM, self::SHOP_PROJECT, 'doing', self::OMAR, 'build', '2027-03-12 09:00:00');
        $this->addTurn(self::ACME_FOODS, self::TICKETS[1], self::SHOP_TEAM, self::SHOP_PROJECT, 'check', self::OMAR, 'review', '2027-03-10 08:00:00');
        $this->addTurn(self::ACME_FOODS, self::TICKETS[2], self::OPS_TEAM, self::OPS_PROJECT, 'wait', self::OMAR, 'input', '2027-03-05 07:00:00');
        $this->addTurn(self::ACME_FOODS, self::TICKETS[3], self::SHOP_TEAM, self::SHOP_PROJECT, 'check', self::MIA, 'test', '2027-03-11 10:00:00');
        $this->addTurn(self::ACME_FOODS, self::TICKETS[4], self::SHOP_TEAM, self::OPS_PROJECT, 'wait', self::MIA, 'answer', '2027-03-11 10:00:00');
        $this->addTurn(self::ACME_FOODS, self::TICKETS[5], self::SHOP_TEAM, self::SHOP_PROJECT, 'wait', self::RAJ, 'decide', '2027-03-01 06:00:00');
        $this->addTurn(self::ACME_FOODS, self::TICKETS[6], self::SHOP_TEAM, self::SHOP_PROJECT, 'wait', null, 'input', '2027-03-01 06:00:00');
        $this->addTurn(self::BETA_BAKERY, self::BETA_BAKERY_TICKET, self::SHOP_TEAM, self::SHOP_PROJECT, 'check', self::OMAR, 'review', '2027-02-01 06:00:00');
    }

    #[Test]
    public function itCountsEveryKindPerAskedPersonWithTheOldestWaitInTheAskedOrder(): void
    {
        $this->addTheWaits();

        $counts = $this->countsOf([self::OMAR, self::LENA, self::MIA], null, null);

        self::assertNotSame([], $counts);
        self::assertSame(
            [self::OMAR => [3, '2027-03-05 07:00:00'], self::LENA => [0, null], self::MIA => [2, '2027-03-11 10:00:00']],
            $counts,
        );
    }

    #[Test]
    public function aTeamFilterLeavesOutTheTicketsOfOtherTeams(): void
    {
        $this->addTheWaits();

        self::assertSame(
            [self::OMAR => [2, '2027-03-10 08:00:00'], self::MIA => [2, '2027-03-11 10:00:00']],
            $this->countsOf([self::OMAR, self::MIA], self::SHOP_TEAM, null),
        );
    }

    #[Test]
    public function aProjectFilterLeavesOutTheTicketsOfOtherProjects(): void
    {
        $this->addTheWaits();

        self::assertSame(
            [self::OMAR => [2, '2027-03-10 08:00:00'], self::MIA => [1, '2027-03-11 10:00:00']],
            $this->countsOf([self::OMAR, self::MIA], null, self::SHOP_PROJECT),
        );
    }

    #[Test]
    public function aTeamAndAProjectFilterTogetherKeepOnlyTheTicketsOfBoth(): void
    {
        $this->addTheWaits();

        self::assertSame(
            [self::OMAR => [0, null], self::MIA => [1, '2027-03-11 10:00:00']],
            $this->countsOf([self::OMAR, self::MIA], self::SHOP_TEAM, self::OPS_PROJECT),
        );
    }

    #[Test]
    public function aPersonAskedTwiceIsCountedOnce(): void
    {
        $this->addTheWaits();

        self::assertSame([self::MIA => [2, '2027-03-11 10:00:00']], $this->countsOf([self::MIA, self::MIA], null, null));
    }

    /**
     * Projected as the projector does it: a build turn for Omar counts, and once the ticket reaches Done its row is
     * gone, so it counts no more.
     */
    #[Test]
    public function aTicketThatReachesDoneIsNoLongerCounted(): void
    {
        $building = new TicketState('step', 'progress', 'doing', new TeamId(self::SHOP_TEAM), assigneeId: new UserId(self::OMAR), reporterId: new UserId(self::MIA));
        $this->projectedAs($building);
        self::assertSame([self::OMAR => [1, '2027-03-15 10:00:00']], $this->countsOf([self::OMAR], null, null));

        $this->projectedAs(new TicketState('step', 'done', 'done', new TeamId(self::SHOP_TEAM), assigneeId: new UserId(self::OMAR), reporterId: new UserId(self::MIA)));

        self::assertSame([self::OMAR => [0, null]], $this->countsOf([self::OMAR], null, null));
    }

    #[Test]
    public function itRunsExactlyOneQueryForManyPeopleAndBothFilters(): void
    {
        $this->addTheWaits();

        $queryCount = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): int {
            $debugData = $this->debugData();
            $debugData->reset();
            $this->queries()->waitingCountsOf(self::lena(), [new UserId(self::OMAR), new UserId(self::MIA), new UserId(self::LENA)], new TeamId(self::SHOP_TEAM), self::SHOP_PROJECT);

            return self::queryCountOf($debugData);
        });

        self::assertSame(1, $queryCount);
    }

    #[Test]
    public function noPersonIsCountedWithoutAQuery(): void
    {
        $this->addTheWaits();

        [$counts, $queryCount] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $debugData = $this->debugData();
            $debugData->reset();

            return [$this->queries()->waitingCountsOf(self::lena(), [], null, null), self::queryCountOf($debugData)];
        });

        self::assertSame([[], 0], [$counts, $queryCount]);
    }

    #[Test]
    public function withNoTransactionItRefusesBeforeAnyQuery(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        $this->assertRefusedBeforeAnyQuery(
            fn (): array => $this->queries()->waitingCountsOf(self::lena(), [new UserId(self::OMAR)], null, null),
            \LogicException::class,
            'Waiting turns are counted in a transaction under the organization as tenant; none is open.',
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
                fn (): array => $this->queries()->waitingCountsOf(self::lena(), [new UserId(self::OMAR)], null, null),
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
            fn (): array => $this->queries()->waitingCountsOf($betaBakeryAdmin, [new UserId(self::OMAR)], null, null),
            \LogicException::class,
            'Waiting turns are counted under the viewer\'s own organization as tenant.',
        ));
    }

    #[Test]
    public function aProjectIdThatIsNoCanonicalUuidIsRefusedBeforeAnyQuery(): void
    {
        $this->transactions()->rolledBackAs(self::ACME_FOODS, fn () => $this->assertRefusedBeforeAnyQuery(
            fn (): array => $this->queries()->waitingCountsOf(self::lena(), [new UserId(self::OMAR)], null, strtoupper(self::SHOP_PROJECT)),
            \InvalidArgumentException::class,
            'Waiting turns are counted in a project given by its canonical lowercase uuid.',
        ));
    }

    /**
     * @param \Closure(): array<string, WaitingTurnCount> $count
     * @param class-string<\Throwable>                    $refusal
     */
    private function assertRefusedBeforeAnyQuery(\Closure $count, string $refusal, string $message): void
    {
        $debugData = $this->debugData();
        $debugData->reset();
        try {
            $count();
            self::fail('The count must be refused.');
        } catch (\LogicException|\InvalidArgumentException $exception) {
            self::assertSame([$refusal, $message], [$exception::class, $exception->getMessage()]);
        }
        self::assertSame(0, self::queryCountOf($debugData), 'No query ran before the refusal.');
    }

    /**
     * @param list<string> $userIds
     *
     * @return array<string, array{int, string|null}> each person's count and oldest wait in UTC, as Acme Foods' Lena reads them
     */
    private function countsOf(array $userIds, ?string $teamId, ?string $projectId): array
    {
        $counts = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->queries()->waitingCountsOf(
            self::lena(),
            array_map(static fn (string $userId): UserId => new UserId($userId), $userIds),
            null === $teamId ? null : new TeamId($teamId),
            $projectId,
        ));

        return array_map(
            static fn (WaitingTurnCount $count): array => [$count->tickets, $count->oldestWaitingSince?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
            $counts,
        );
    }

    /**
     * A viewer with no role that widens what she sees; every row here is for everyone, so she counts them all.
     */
    private static function lena(): TicketViewer
    {
        return new TicketViewer(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), [], false, false);
    }

    private function addTurn(string $organizationId, string $ticketId, string $teamId, string $projectId, string $group, ?string $userId, string $kind, string $waitingSince): void
    {
        $this->transactions()->committedAs($organizationId, fn (): int|string => $this->connection()->executeStatement(
            "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 100, ?, 'all', ?)",
            [$organizationId, $ticketId, $teamId, $projectId, $group, $userId, null === $userId ? 'Customer' : null, $kind, $waitingSince.'+00', self::MIA],
        ));
    }

    private function projectedAs(TicketState $ticket): void
    {
        $source = self::getContainer()->get(InMemoryTicketTurnSource::class);
        self::assertInstanceOf(InMemoryTicketTurnSource::class, $source);
        $source->put(new TicketTurnFacts(new TicketId(self::TICKETS[0]), self::SHOP_PROJECT, $ticket, new TeamFacts(new UserId(self::LENA), 'Lena'), 'all'));
        $turnRules = self::getContainer()->get('test.workflow_turn_rules');
        $needsYouScore = self::getContainer()->get('test.workflow_needs_you_score');
        self::assertInstanceOf(TurnRules::class, $turnRules);
        self::assertInstanceOf(NeedsYouScore::class, $needsYouScore);
        $projector = new DbalTurnProjector(
            $source,
            new TurnRowResolver($turnRules, $needsYouScore),
            new DbalProjectCountsProjector($this->connection(), $this->tenantContext()),
            $this->connection(),
            $this->tenantContext(),
            new MockClock('2027-03-15 10:00:00+00:00'),
        );

        $this->transactions()->committedAs(self::ACME_FOODS, static fn (): ?string => $projector->refresh(new TicketId(self::TICKETS[0])));
    }

    private function queries(): WaitingTurnCounts
    {
        $queries = self::getContainer()->get('test.insight_waiting_turn_counts');
        \assert($queries instanceof WaitingTurnCounts);

        return $queries;
    }

    private function removeRows(): void
    {
        $this->shopTeamRows()->removeAllOf([self::LENA, self::OMAR, self::MIA, self::RAJ], self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }

    private function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
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
