<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insight;

use App\Insight\Application\Turn\TicketTurnFacts;
use App\Insight\Application\Turn\TurnRowResolver;
use App\Insight\Infrastructure\Doctrine\DbalProjectCountsProjector;
use App\Insight\Infrastructure\Doctrine\DbalTurnProjector;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\NeedsYouScore;
use App\Workflow\Api\ReleasePlacement;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TeamMemberLoad;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\TurnRules;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * DbalTurnProjector on the real table, with Workflow's turn rules and score, as pinround_app under row-level security.
 * The facts come from InMemoryTicketTurnSource; TicketRows adds the tkt_ticket row that ticket_id must point at.
 */
final class DbalTurnProjectorTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000017a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000017b1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000017c1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000017d1';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000017e1';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000017f1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000017f2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000017f3';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000017f4';
    private const string FIRST_REFRESH = '2027-03-15 10:00:00';
    private const string SECOND_REFRESH = '2027-03-18 16:30:00';

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::FIRST_REFRESH.'+00:00');
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $this->ticketRows()->addOf($organizationId, self::TICKET, self::SHOP_PROJECT, self::LENA);
        }
    }

    protected function tearDown(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->source()->reset();
        self::getContainer()->get(TenantContext::class)->reset();
        parent::tearDown();
    }

    #[Test]
    public function aPersonsTurnStoresThePersonTheScoreAndTheColumnsCopiedFromTheTicket(): void
    {
        $note = $this->refreshedAs(self::ACME_FOODS, $this->facts($this->buildingTicket(self::OMAR, priority: 'major', isCheckNeeded: true), visibility: 'team'));

        $score = $this->needsYouScore();
        self::assertNull($note);
        self::assertSame(
            [
                'team_id' => self::SHOP_TEAM,
                'project_id' => self::SHOP_PROJECT,
                'grp' => 'doing',
                'turn_user_id' => self::OMAR,
                'turn_label' => null,
                'kind' => 'build',
                'base_score' => $score->baseScore(NeedsYouKind::Build, 'major', ReleasePlacement::NoRelease, false),
                'waiting_since' => self::FIRST_REFRESH,
                'check_user_id' => self::SARA,
                'visibility' => 'team',
                'reporter_id' => self::MIA,
                'assignee_id' => self::OMAR,
                'pending_assignee_id' => null,
                'reviewer_id' => null,
                'tester_id' => null,
                'waiting_on_user_id' => null,
            ],
            $this->storedRowOf(self::ACME_FOODS),
        );
    }

    #[Test]
    public function aLabelWaiterHasNoTurnPersonAndTheLabel(): void
    {
        $this->refreshedAs(self::ACME_FOODS, $this->facts($this->waitingTicket(label: 'Customer')));

        self::assertSame(
            ['grp' => 'wait', 'turn_user_id' => null, 'turn_label' => 'Customer', 'kind' => 'input'],
            array_intersect_key($this->storedRowOf(self::ACME_FOODS) ?? [], array_flip(['grp', 'turn_user_id', 'turn_label', 'kind'])),
        );
    }

    /**
     * The priority changes too, so the second refresh is seen to update the row while it keeps waiting_since.
     */
    #[Test]
    public function refreshingTheSameTurnAgainKeepsWaitingSinceAndUpdatesTheRest(): void
    {
        $this->refreshedAs(self::ACME_FOODS, $this->facts($this->buildingTicket(self::OMAR, priority: 'minor')));
        $this->clock->modify(self::SECOND_REFRESH.'+00:00');
        $this->refreshedAs(self::ACME_FOODS, $this->facts($this->buildingTicket(self::OMAR, priority: 'blocker')));

        $score = $this->needsYouScore();
        $row = $this->storedRowOf(self::ACME_FOODS);
        self::assertSame(
            [self::FIRST_REFRESH, self::OMAR, $score->baseScore(NeedsYouKind::Build, 'blocker', ReleasePlacement::NoRelease, false)],
            [$row['waiting_since'] ?? null, $row['turn_user_id'] ?? null, $row['base_score'] ?? null],
        );
        self::assertNotSame(
            $score->baseScore(NeedsYouKind::Build, 'minor', ReleasePlacement::NoRelease, false),
            $row['base_score'] ?? null,
        );
    }

    /**
     * @return iterable<string, array{?string, ?string, ?string, ?string}> the person or label before, then after
     */
    public static function turnsThatMove(): iterable
    {
        yield 'to another person' => [self::OMAR, null, self::LENA, null];
        yield 'from a person to a label' => [self::OMAR, null, null, 'Customer'];
        yield 'from a label to a person' => [null, 'Customer', self::OMAR, null];
        yield 'to another label' => [null, 'Customer', null, 'Product owner'];
    }

    #[Test]
    #[DataProvider('turnsThatMove')]
    public function aTurnThatMovesToSomeoneElseStartsWaitingSinceAgain(?string $userBefore, ?string $labelBefore, ?string $userAfter, ?string $labelAfter): void
    {
        $this->refreshedAs(self::ACME_FOODS, $this->facts($this->waitingTicket($userBefore, $labelBefore)));
        $this->clock->modify(self::SECOND_REFRESH.'+00:00');
        $this->refreshedAs(self::ACME_FOODS, $this->facts($this->waitingTicket($userAfter, $labelAfter)));

        self::assertSame(self::SECOND_REFRESH, $this->storedRowOf(self::ACME_FOODS)['waiting_since'] ?? null);
    }

    #[Test]
    public function aTicketThatReachesTheGroupDoneLosesItsRow(): void
    {
        $this->refreshedAs(self::ACME_FOODS, $this->facts($this->buildingTicket(self::OMAR)));
        self::assertNotNull($this->storedRowOf(self::ACME_FOODS));

        $note = $this->refreshedAs(self::ACME_FOODS, $this->facts(new TicketState('step', 'done', 'done', new TeamId(self::SHOP_TEAM), assigneeId: new UserId(self::OMAR), reporterId: new UserId(self::MIA))));

        self::assertNull($note);
        self::assertNull($this->storedRowOf(self::ACME_FOODS));
    }

    #[Test]
    public function aTicketTheSourceNoLongerKnowsLosesItsRow(): void
    {
        $this->refreshedAs(self::ACME_FOODS, $this->facts($this->buildingTicket(self::OMAR)));
        self::assertNotNull($this->storedRowOf(self::ACME_FOODS));

        self::assertNull($this->refreshedAs(self::ACME_FOODS, null));
        self::assertNull($this->storedRowOf(self::ACME_FOODS));
    }

    #[Test]
    public function removingTheRowLeavesAnotherOrganizationsRowForTheSameTicketIdAlone(): void
    {
        $this->refreshedAs(self::BETA_BAKERY, $this->facts($this->buildingTicket(self::OMAR)));
        $this->refreshedAs(self::ACME_FOODS, $this->facts($this->buildingTicket(self::LENA)));

        $this->refreshedAs(self::ACME_FOODS, null);

        self::assertNull($this->storedRowOf(self::ACME_FOODS));
        self::assertSame(self::OMAR, $this->storedRowOf(self::BETA_BAKERY)['turn_user_id'] ?? null);
    }

    #[Test]
    public function aRefreshOutsideATransactionIsRefusedAndWritesNothing(): void
    {
        $this->source()->put($this->facts($this->buildingTicket(self::OMAR)));
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->projector()->refresh(new TicketId(self::TICKET));
            self::fail('The projector refreshed outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('A turn row is refreshed in the transaction of the change; none is open.', $refusal->getMessage());
        } finally {
            $tenantContext->clear();
        }

        self::assertNull($this->storedRowOf(self::ACME_FOODS));
    }

    /**
     * Lena wrote the code and is the team's only developer, so the reviewer choice falls back to her.
     */
    #[Test]
    public function theOverrideNoteOfAFallbackIsReturnedForTheActivity(): void
    {
        $lena = new UserId(self::LENA);
        $inReview = new TicketState('step', 'review', 'check', new TeamId(self::SHOP_TEAM), assigneeId: $lena, reporterId: new UserId(self::MIA));

        $note = $this->refreshedAs(self::ACME_FOODS, $this->facts($inReview, new TeamFacts($lena, 'Lena', [new TeamMemberLoad($lena)])));

        self::assertSame('Lena reviews their own code: no other developer in the team', $note);
        self::assertSame([self::LENA, 'review'], [$this->storedRowOf(self::ACME_FOODS)['turn_user_id'] ?? null, $this->storedRowOf(self::ACME_FOODS)['kind'] ?? null]);
    }

    private function buildingTicket(string $assigneeId, ?string $priority = null, bool $isCheckNeeded = false): TicketState
    {
        return new TicketState(
            'step',
            'progress',
            'doing',
            new TeamId(self::SHOP_TEAM),
            assigneeId: new UserId($assigneeId),
            reporterId: new UserId(self::MIA),
            isCheckNeeded: $isCheckNeeded,
            checkerId: new UserId(self::SARA),
            priority: $priority,
        );
    }

    private function waitingTicket(?string $userId = null, ?string $label = null): TicketState
    {
        return new TicketState(
            'step',
            'input',
            'wait',
            new TeamId(self::SHOP_TEAM),
            reporterId: new UserId(self::MIA),
            waitingOnUserId: null === $userId ? null : new UserId($userId),
            waitingOnLabel: $label,
        );
    }

    private function facts(TicketState $ticket, ?TeamFacts $team = null, string $visibility = 'all'): TicketTurnFacts
    {
        return new TicketTurnFacts(new TicketId(self::TICKET), self::SHOP_PROJECT, $ticket, $team ?? new TeamFacts(new UserId(self::LENA), 'Lena'), $visibility);
    }

    /**
     * Commits one refresh of the ticket as the organization; null facts make the source know no such ticket.
     */
    private function refreshedAs(string $organizationId, ?TicketTurnFacts $facts): ?string
    {
        $source = $this->source();
        null === $facts ? $source->forget(new TicketId(self::TICKET)) : $source->put($facts);

        return $this->transactions()->committedAs($organizationId, fn (): ?string => $this->projector()->refresh(new TicketId(self::TICKET)));
    }

    /**
     * @return array<string, mixed>|null the row without its key, waiting_since in UTC; null when there is none
     */
    private function storedRowOf(string $organizationId): ?array
    {
        $row = $this->transactions()->rolledBackAs($organizationId, fn (): array|false => $this->connection()->fetchAssociative(
            "SELECT team_id, project_id, grp, turn_user_id, turn_label, kind, base_score,
                    to_char(waiting_since AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS waiting_since,
                    check_user_id, visibility, reporter_id, assignee_id, pending_assignee_id, reviewer_id, tester_id,
                    waiting_on_user_id
             FROM ins_ticket_turn WHERE ticket_id = ?",
            [self::TICKET],
        ));

        return false === $row ? null : $row;
    }

    private function projector(): DbalTurnProjector
    {
        return new DbalTurnProjector(
            $this->source(),
            new TurnRowResolver($this->turnRules(), $this->needsYouScore()),
            new DbalProjectCountsProjector($this->connection(), self::getContainer()->get(TenantContext::class)),
            $this->connection(),
            self::getContainer()->get(TenantContext::class),
            $this->clock,
        );
    }

    private function source(): InMemoryTicketTurnSource
    {
        $source = self::getContainer()->get(InMemoryTicketTurnSource::class);
        self::assertInstanceOf(InMemoryTicketTurnSource::class, $source);

        return $source;
    }

    private function turnRules(): TurnRules
    {
        $turnRules = self::getContainer()->get('test.workflow_turn_rules');
        self::assertInstanceOf(TurnRules::class, $turnRules);

        return $turnRules;
    }

    private function needsYouScore(): NeedsYouScore
    {
        $score = self::getContainer()->get('test.workflow_needs_you_score');
        self::assertInstanceOf(NeedsYouScore::class, $score);

        return $score;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
