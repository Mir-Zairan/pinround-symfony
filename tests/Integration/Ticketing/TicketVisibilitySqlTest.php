<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilityFacts;
use App\Ticketing\Api\TicketVisibilitySql;
use App\Ticketing\Application\RoleTicketVisibilities;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The SQL condition and the PHP check of TicketVisibility agree (ADR-0013): on real tkt_ticket rows, the condition
 * finds exactly the tickets the PHP check lets each viewer see. Each viewer's query runs under both tenants, so the
 * condition alone, not row-level security, must keep the Beta Bakery ticket from a viewer of Acme Foods.
 */
final class TicketVisibilitySqlTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000034a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000034a2';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000034d1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000034c1';
    private const string OTHER_TEAM = '0197a3c0-0000-7000-8000-0000000034c2';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000034b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000034b2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000034b3';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000034b4';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000034b5';

    /**
     * By ticket id: organization, visibility, team, reporter and the other people by column; ticket numbers follow the
     * order. Mia, of the other team, reports most; Omar, in no team, is the one person on each people ticket. Ticket 10
     * is Beta Bakery's, for everyone and with the same team and people ids, so only the organization tells it apart.
     */
    private const array TICKETS = [
        '0197a3c0-0000-7000-8000-0000000034e1' => [self::ACME_FOODS, 'all', self::OTHER_TEAM, self::MIA, []],
        '0197a3c0-0000-7000-8000-0000000034e2' => [self::ACME_FOODS, 'team', self::SHOP_TEAM, self::LENA, []],
        '0197a3c0-0000-7000-8000-0000000034e3' => [self::ACME_FOODS, 'team', self::OTHER_TEAM, self::OMAR, []],
        '0197a3c0-0000-7000-8000-0000000034e4' => [self::ACME_FOODS, 'team', self::OTHER_TEAM, self::MIA, ['assignee_id' => self::OMAR]],
        '0197a3c0-0000-7000-8000-0000000034e5' => [self::ACME_FOODS, 'team', self::OTHER_TEAM, self::MIA, ['pending_assignee_id' => self::OMAR]],
        '0197a3c0-0000-7000-8000-0000000034e6' => [self::ACME_FOODS, 'team', self::OTHER_TEAM, self::MIA, ['reviewer_id' => self::OMAR]],
        '0197a3c0-0000-7000-8000-0000000034e7' => [self::ACME_FOODS, 'team', self::OTHER_TEAM, self::MIA, ['tester_id' => self::OMAR]],
        '0197a3c0-0000-7000-8000-0000000034e8' => [self::ACME_FOODS, 'team', self::OTHER_TEAM, self::MIA, ['waiting_on_user_id' => self::OMAR]],
        '0197a3c0-0000-7000-8000-0000000034e9' => [self::ACME_FOODS, 'team', null, self::MIA, []],
        '0197a3c0-0000-7000-8000-0000000034ea' => [self::BETA_BAKERY, 'all', self::SHOP_TEAM, self::OMAR, []],
    ];

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $number = 1;
        foreach (self::TICKETS as $ticketId => [$organizationId, $visibility, $teamId, $reporterId, $otherPeople]) {
            $this->rows()->addWithAudienceOf($organizationId, $ticketId, self::SHOP_PROJECT, $number++, $visibility, $teamId, $reporterId, $otherPeople);
        }
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    /**
     * @param list<string> $expectedTicketNumbers
     */
    #[Test]
    #[DataProvider('viewers')]
    public function theConditionFindsTheTicketsThePhpCheckShows(TicketVisibility $viewer, array $expectedTicketNumbers): void
    {
        $found = [
            ...$this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->ticketNumbersFound($viewer)),
            ...$this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array => $this->ticketNumbersFound($viewer)),
        ];

        self::assertSame($expectedTicketNumbers, $found);
        self::assertSame($expectedTicketNumbers, self::ticketNumbersShownBy($viewer));
    }

    /**
     * @return iterable<string, array{TicketVisibility, list<string>}>
     */
    public static function viewers(): iterable
    {
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $triager = new UserId(self::LENA);
        yield 'Lena, in the SHOP team and the triager' => [TicketVisibility::for($acmeFoods, new UserId(self::LENA), [new TeamId(self::SHOP_TEAM)], false, $triager), ['1', '2', '9']];
        yield 'Omar, in no team, on six tickets' => [TicketVisibility::for($acmeFoods, new UserId(self::OMAR), [], false, $triager), ['1', '3', '4', '5', '6', '7', '8']];
        yield 'Mia, in the other team' => [TicketVisibility::for($acmeFoods, new UserId(self::MIA), [new TeamId(self::OTHER_TEAM)], false, $triager), ['1', '3', '4', '5', '6', '7', '8', '9']];
        yield 'Raj, a manager without a team role' => [TicketVisibility::for($acmeFoods, new UserId(self::RAJ), [], false, $triager), ['1']];
        yield 'Sara, an admin without a team role' => [TicketVisibility::for($acmeFoods, new UserId(self::SARA), [], true, $triager), ['1', '2', '3', '4', '5', '6', '7', '8', '9']];
        yield 'Lena, in the SHOP team, with no triager in the organization' => [TicketVisibility::for($acmeFoods, new UserId(self::LENA), [new TeamId(self::SHOP_TEAM)], false, null), ['1', '2']];
        yield 'Sara, an admin of Beta Bakery' => [TicketVisibility::for(new OrganizationId(self::BETA_BAKERY), new UserId(self::SARA), [], true, null), ['10']];
    }

    /**
     * Ticket 1 is Acme Foods' only ticket for everyone; ticket 10 is for everyone too, but Beta Bakery's. Run under both
     * tenants, so the condition alone, not row-level security, leaves ticket 10 out.
     */
    #[Test]
    public function theForEveryoneConditionFindsOnlyTheOrganizationsTicketsForEveryone(): void
    {
        $condition = TicketVisibilitySql::forEveryoneIn(new OrganizationId(self::ACME_FOODS), 't');
        $ticketNumbersFound = fn (): array => $this->connection()->fetchFirstColumn(
            'SELECT t.number::text FROM tkt_ticket t WHERE '.$condition->sql().' ORDER BY t.number',
            $condition->parameters(),
        );

        $found = [
            ...$this->transactions()->rolledBackAs(self::ACME_FOODS, $ticketNumbersFound),
            ...$this->transactions()->rolledBackAs(self::BETA_BAKERY, $ticketNumbersFound),
        ];

        self::assertSame(['1'], $found);
    }

    #[Test]
    public function twoConditionsInOneQueryKeepTheirOwnViewers(): void
    {
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $triager = new UserId(self::LENA);
        $omar = TicketVisibilitySql::of(TicketVisibility::for($acmeFoods, new UserId(self::OMAR), [], false, $triager), 'shown');
        $lena = TicketVisibilitySql::of(TicketVisibility::for($acmeFoods, new UserId(self::LENA), [new TeamId(self::SHOP_TEAM)], false, $triager), 'also_shown');

        $found = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn(
            'SELECT shown.number::text FROM tkt_ticket shown WHERE '.$omar->sql()
            .' AND EXISTS (SELECT 1 FROM tkt_ticket also_shown WHERE also_shown.id = shown.id AND '.$lena->sql().') ORDER BY shown.number',
            [...$omar->parameters(), ...$lena->parameters()],
        ));

        self::assertSame(['1'], $found);
    }

    /**
     * Omar is the assignee of ticket 4 and on none of tickets 2 (SHOP team) and 9 (no team; Lena triages), so of the
     * three turn rows only ticket 4's is his to see.
     */
    #[Test]
    public function theConditionFiltersTheTurnReadModelByItsCopiedColumns(): void
    {
        $condition = TicketVisibilitySql::of(TicketVisibility::for(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), [], false, new UserId(self::LENA)), 'tt');

        $found = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($condition): array {
            foreach (['0197a3c0-0000-7000-8000-0000000034e2', '0197a3c0-0000-7000-8000-0000000034e4', '0197a3c0-0000-7000-8000-0000000034e9'] as $ticketId) {
                $this->addTurnRowOf($ticketId);
            }

            return $this->connection()->fetchFirstColumn(
                'SELECT tt.ticket_id FROM ins_ticket_turn tt WHERE '.$condition->sql().' ORDER BY tt.ticket_id',
                $condition->parameters(),
            );
        });

        self::assertSame(['0197a3c0-0000-7000-8000-0000000034e4'], $found);
    }

    /**
     * Another module gets a viewer's predicate from TicketVisibilities alone, read through the real Organization Api
     * from stored rows: Lena leads the SHOP team and is the organization's triager, so she sees the no-team ticket 9;
     * Mia is a member of the other team; Omar has no team role and is on the other team's tickets as reporter or
     * worker; Raj is a Manager with no team role, an outsider to every restricted ticket; Sara is an Admin.
     */
    #[Test]
    public function theViewerFromTicketVisibilitiesSeesWhatTheirStoredRolesAllow(): void
    {
        $visibilities = self::getContainer()->get('test.ticketing_ticket_visibilities');
        self::assertInstanceOf(RoleTicketVisibilities::class, $visibilities);
        $expectedByViewer = [
            self::LENA => ['1', '2', '9'],
            self::MIA => ['1', '3', '4', '5', '6', '7', '8', '9'],
            self::OMAR => ['1', '3', '4', '5', '6', '7', '8'],
            self::RAJ => ['1'],
            self::SARA => ['1', '2', '3', '4', '5', '6', '7', '8', '9'],
        ];

        $foundByViewer = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($visibilities, $expectedByViewer): array {
            $this->addMembershipOf(self::LENA, '{}');
            $this->addMembershipOf(self::MIA, '{}');
            $this->addMembershipOf(self::OMAR, '{}');
            $this->addMembershipOf(self::RAJ, '{manager}');
            $this->addMembershipOf(self::SARA, '{admin}');
            $this->addTeamOf(self::SHOP_TEAM, 'Shop', self::LENA, [self::LENA => 'lead']);
            $this->addTeamOf(self::OTHER_TEAM, 'Web', self::MIA, [self::MIA => 'member']);
            $this->connection()->executeStatement(
                "INSERT INTO org_settings (organization_id, data, created_at) VALUES (?, jsonb_build_object('triager_id', ?::text), '2027-03-15 10:00:00+00')",
                [self::ACME_FOODS, self::LENA],
            );
            $found = [];
            foreach (array_keys($expectedByViewer) as $viewerId) {
                $viewer = $visibilities->of(new OrganizationId(self::ACME_FOODS), new UserId($viewerId));
                $found[$viewerId] = [$this->ticketNumbersFound($viewer), self::ticketNumbersShownBy($viewer)];
            }

            return $found;
        });

        foreach ($expectedByViewer as $viewerId => $expectedTicketNumbers) {
            self::assertSame([$expectedTicketNumbers, $expectedTicketNumbers], $foundByViewer[$viewerId], $viewerId);
        }
    }

    /**
     * @return list<string>
     */
    private function ticketNumbersFound(TicketVisibility $viewer): array
    {
        $condition = TicketVisibilitySql::of($viewer, 't');

        return array_map(
            static fn (mixed $number): string => \is_string($number) ? $number : throw new \UnexpectedValueException('A ticket number reads as text.'),
            $this->connection()->fetchFirstColumn(
                'SELECT t.number::text FROM tkt_ticket t WHERE '.$condition->sql().' ORDER BY t.number',
                $condition->parameters(),
            ),
        );
    }

    /**
     * @return list<string>
     */
    private static function ticketNumbersShownBy(TicketVisibility $viewer): array
    {
        $numbers = [];
        $number = 1;
        foreach (self::TICKETS as [$organizationId, $visibility, $teamId, $reporterId, $otherPeople]) {
            $ticket = new TicketVisibilityFacts(
                new OrganizationId($organizationId),
                $visibility,
                null === $teamId ? null : new TeamId($teamId),
                new UserId($reporterId),
                ...array_map(static fn (string $userId): UserId => new UserId($userId), self::byArgumentName($otherPeople)),
            );
            if ($viewer->isVisible($ticket)) {
                $numbers[] = (string) $number;
            }
            ++$number;
        }

        return $numbers;
    }

    /**
     * The ticket's turn row, with the predicate's columns copied from TICKETS as the turn projector copies them.
     */
    private function addTurnRowOf(string $ticketId): void
    {
        [$organizationId, $visibility, $teamId, $reporterId, $otherPeople] = self::TICKETS[$ticketId];
        $people = ['assignee_id' => null, 'pending_assignee_id' => null, 'reviewer_id' => null, 'tester_id' => null, 'waiting_on_user_id' => null, ...$otherPeople];
        $this->connection()->executeStatement(
            "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, kind, base_score, waiting_since,
                 visibility, reporter_id, assignee_id, pending_assignee_id, reviewer_id, tester_id, waiting_on_user_id)
             VALUES (?, ?, ?, ?, 'doing', ?, 'build', 18, '2027-03-15 10:00:00+00', ?, ?, ?, ?, ?, ?, ?)",
            [$organizationId, $ticketId, $teamId, self::SHOP_PROJECT, $reporterId, $visibility, $reporterId, ...array_values($people)],
        );
    }

    private function addMembershipOf(string $userId, string $orgRoles): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, ?, '2027-03-15 10:00:00+00')",
            [self::ACME_FOODS, $userId, $orgRoles],
        );
    }

    /**
     * @param array<string, string> $teamRolesByUserId
     */
    private function addTeamOf(string $teamId, string $name, string $leadId, array $teamRolesByUserId): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO org_team (organization_id, id, name, slug, lead_user_id, created_at) VALUES (?, ?, ?, ?, ?, '2027-03-15 10:00:00+00')",
            [self::ACME_FOODS, $teamId, $name, strtolower($name), $leadId],
        );
        foreach ($teamRolesByUserId as $userId => $teamRole) {
            $this->connection()->executeStatement(
                "INSERT INTO org_team_member (organization_id, team_id, user_id, team_role, job, created_at) VALUES (?, ?, ?, ?, 'developer', '2027-03-15 10:00:00+00')",
                [self::ACME_FOODS, $teamId, $userId, $teamRole],
            );
        }
    }

    /**
     * @param array<string, string> $peopleByColumn
     *
     * @return array<string, string>
     */
    private static function byArgumentName(array $peopleByColumn): array
    {
        $byArgumentName = [];
        foreach ($peopleByColumn as $column => $userId) {
            $byArgumentName[lcfirst(str_replace('_', '', ucwords($column, '_')))] = $userId;
        }

        return $byArgumentName;
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
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function rows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
