<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing\Intake;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\ReadsSentSql;
use App\Tests\Integration\Tenancy\OwnConnectionTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\IntakeCandidate;
use App\Ticketing\Api\IntakeCandidateQueries;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Infrastructure\Doctrine\DbalIntakeCandidateQueries;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * IntakeCandidateQueries as Assist's intake check will get it. In Acme Foods, Mia is in the Shop team. SHOP has 85 open
 * tickets for everyone, SHOP-1 to SHOP-85, written out of order, each updated a minute after the one before it except
 * SHOP-51, updated in the same minute as SHOP-50. Newer than all of them, in this order, are a team-only ticket of Shop
 * (SHOP-86), a team-only ticket of another team that Mia reported (SHOP-87) and a team-only ticket of another team
 * (SHOP-88), which the query gives only with team-only tickets included and only to whom may see them, and the rows it
 * always leaves out: a closed ticket for everyone, a ticket for everyone in the project OPS, and Beta Bakery's ticket in
 * its own project with SHOP's id.
 */
final class IntakeCandidateQueriesTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f8a01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f8a02';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000f8a03';
    private const string OTHER_TEAM = '0197a3c0-0000-7000-8000-0000000f8a04';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000f8a05';
    private const string OPS_PROJECT = '0197a3c0-0000-7000-8000-0000000f8a06';
    private const string UNKNOWN_PROJECT = '0197a3c0-0000-7000-8000-0000000f8a07';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f8a11';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f8a12';
    private const string STEP = '0197a3c0-0000-7000-8000-0000000f8a21';
    private const int OPEN_TICKET_COUNT = 85;
    /** SHOP-51 shares its minute with SHOP-50 and has the higher id, so only the id puts it first. */
    private const int TIED_TICKET = 51;

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->ticketRows()->addKeyedProjectOf(self::ACME_FOODS, self::SHOP_PROJECT, self::SHOP_TEAM, 'SHOP', 1);
        $this->ticketRows()->addKeyedProjectOf(self::ACME_FOODS, self::OPS_PROJECT, null, 'OPS', 1);
        $this->ticketRows()->addKeyedProjectOf(self::BETA_BAKERY, self::SHOP_PROJECT, null, 'SHOP', 1);

        $openNumbers = range(1, self::OPEN_TICKET_COUNT);
        // A fixed order that is neither the numbers' nor their reverse, so the query cannot lean on the heap order.
        usort($openNumbers, static fn (int $left, int $right): int => [$left % 7, $left] <=> [$right % 7, $right]);
        $this->asTenantMigrator(self::ACME_FOODS, static function (Connection $migrator) use ($openNumbers): void {
            foreach ($openNumbers as $number) {
                $minute = self::TIED_TICKET === $number ? self::TIED_TICKET - 1 : $number;
                self::addTicket($migrator, self::ACME_FOODS, self::SHOP_PROJECT, 'SHOP', $number, 'all', self::SHOP_TEAM, self::OMAR, $minute);
            }
            self::addTicket($migrator, self::ACME_FOODS, self::SHOP_PROJECT, 'SHOP', 86, 'team', self::SHOP_TEAM, self::OMAR, 120);
            self::addTicket($migrator, self::ACME_FOODS, self::SHOP_PROJECT, 'SHOP', 87, 'team', self::OTHER_TEAM, self::MIA, 121);
            self::addTicket($migrator, self::ACME_FOODS, self::SHOP_PROJECT, 'SHOP', 88, 'team', self::OTHER_TEAM, self::OMAR, 122);
            self::addTicket($migrator, self::ACME_FOODS, self::SHOP_PROJECT, 'SHOP', 89, 'all', self::SHOP_TEAM, self::OMAR, 123, closed: true);
            self::addTicket($migrator, self::ACME_FOODS, self::OPS_PROJECT, 'OPS', 1, 'all', null, self::OMAR, 124);
        });
        $this->asTenantMigrator(self::BETA_BAKERY, static function (Connection $migrator): void {
            self::addTicket($migrator, self::BETA_BAKERY, self::SHOP_PROJECT, 'SHOP', 90, 'all', null, self::OMAR, 125);
        });
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function theEightyNewestOpenTicketsForEveryoneComeNewestFirstWithTheHigherIdFirstInATie(): void
    {
        $candidates = $this->candidatesIn(self::SHOP_PROJECT, self::miaVisibility(false), false);

        self::assertNotSame([], $candidates);
        self::assertCount(IntakeCandidateQueries::CANDIDATE_LIMIT, $candidates);
        $expectedNumbers = range(self::OPEN_TICKET_COUNT, self::OPEN_TICKET_COUNT - IntakeCandidateQueries::CANDIDATE_LIMIT + 1);
        self::assertSame(
            array_map(static fn (int $number): array => ['SHOP-'.$number, self::titleOf('SHOP', $number)], $expectedNumbers),
            array_map(static fn (IntakeCandidate $candidate): array => [$candidate->key, $candidate->title], $candidates),
        );
    }

    #[Test]
    public function anAdminWhoMaySeeEveryTeamOnlyTicketGetsNoneWhenTeamOnlyIsLeftOut(): void
    {
        $candidates = $this->candidatesIn(self::SHOP_PROJECT, self::miaVisibility(true), false);

        self::assertNotSame([], $candidates);
        $keys = array_map(static fn (IntakeCandidate $candidate): string => $candidate->key, $candidates);
        self::assertSame([], array_intersect(['SHOP-86', 'SHOP-87', 'SHOP-88', 'SHOP-89'], $keys));
        self::assertSame('SHOP-'.self::OPEN_TICKET_COUNT, $keys[0]);
    }

    /**
     * SHOP-87 is another team's, but Mia reported it, so she may see it; SHOP-88 she may not.
     */
    #[Test]
    public function withTeamOnlyIncludedAReporterGetsTheTeamOnlyTicketsTheyMaySeeAndNoOther(): void
    {
        $candidates = $this->candidatesIn(self::SHOP_PROJECT, self::miaVisibility(false), true);

        self::assertNotSame([], $candidates);
        self::assertSame(['SHOP-87', 'SHOP-86', ...self::shopKeysFrom(self::OPEN_TICKET_COUNT, 8)], self::keysOf($candidates));
    }

    #[Test]
    public function withTeamOnlyIncludedAnAdminGetsEveryOpenTeamOnlyTicket(): void
    {
        $candidates = $this->candidatesIn(self::SHOP_PROJECT, self::miaVisibility(true), true);

        self::assertNotSame([], $candidates);
        self::assertSame(['SHOP-88', 'SHOP-87', 'SHOP-86', ...self::shopKeysFrom(self::OPEN_TICKET_COUNT, 9)], self::keysOf($candidates));
    }

    /**
     * The SQL names Acme Foods both times and only the transaction's tenant differs, so without row-level security
     * the read under Beta Bakery would give Acme Foods' tickets too.
     */
    #[Test]
    #[DataProvider('teamOnlyChoices')]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsCandidates(bool $includeTeamOnly): void
    {
        $ownConnectionTransactions = new OwnConnectionTransactions($this->connection(), $this->tenantContext());
        $candidatesOn = fn (Connection $connection): array => new DbalIntakeCandidateQueries($connection, $this->tenantContext())
            ->openCandidatesIn(self::SHOP_PROJECT, self::miaVisibility(true), $includeTeamOnly);

        $underAcmeFoods = $ownConnectionTransactions->rolledBackAs(self::ACME_FOODS, $candidatesOn);
        $underBetaBakery = $ownConnectionTransactions->rolledBackUnderAnotherContext(self::BETA_BAKERY, self::ACME_FOODS, $candidatesOn);

        self::assertNotSame([], $underAcmeFoods);
        self::assertSame([], $underBetaBakery);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function teamOnlyChoices(): iterable
    {
        yield 'team-only left out' => [false];
        yield 'team-only included' => [true];
    }

    #[Test]
    public function aProjectWithFewerTicketsGivesThemAllAndAnUnknownOneNone(): void
    {
        $opsCandidates = $this->candidatesIn(self::OPS_PROJECT, self::miaVisibility(false), false);
        $unknownCandidates = $this->candidatesIn(self::UNKNOWN_PROJECT, self::miaVisibility(false), false);

        self::assertEquals([new IntakeCandidate('OPS-1', self::titleOf('OPS', 1))], $opsCandidates);
        self::assertSame([], $unknownCandidates);
    }

    #[Test]
    public function aMalformedProjectIdIsRefusedBeforeAnyQuery(): void
    {
        [$refusal, $queryCount] = $this->transactions()->committedAs(self::ACME_FOODS, function (): array {
            $this->debugData()->reset();
            try {
                $this->queries()->openCandidatesIn(strtoupper(self::SHOP_PROJECT), self::miaVisibility(false), false);
            } catch (\InvalidArgumentException $refusal) {
                return [$refusal->getMessage(), self::queryCountOf($this->debugData())];
            }
            self::fail('An uppercase project id was read.');
        });

        self::assertSame('An intake candidate\'s project id must be a canonical lowercase uuid.', $refusal);
        self::assertSame(0, $queryCount);
    }

    #[Test]
    public function aReadOutsideATransactionIsRefusedBeforeAnyQuery(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $this->debugData()->reset();
        try {
            $this->queries()->openCandidatesIn(self::SHOP_PROJECT, self::miaVisibility(false), false);
            self::fail('The candidates were read outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Intake candidates are read in a transaction under the organization as tenant; none is open.', $refusal->getMessage());
        } finally {
            $this->tenantContext()->clear();
        }
        self::assertSame(0, self::queryCountOf($this->debugData()));
    }

    #[Test]
    public function aReadInATransactionWithNoTenantIsRefusedBeforeAnyQuery(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        $this->debugData()->reset();
        try {
            $this->queries()->openCandidatesIn(self::SHOP_PROJECT, self::miaVisibility(false), false);
            self::fail('The candidates were read with no tenant.');
        } catch (TenantNotSet $refusal) {
            self::assertSame('No organization is set for this unit of work.', $refusal->getMessage());
            self::assertSame(0, self::queryCountOf($this->debugData()));
        } finally {
            $connection->rollBack();
        }
    }

    #[Test]
    public function aReporterOfAnotherOrganizationThanTheTenantIsRefusedBeforeAnyQuery(): void
    {
        [$refusal, $queryCount] = $this->transactions()->committedAs(self::BETA_BAKERY, function (): array {
            $this->debugData()->reset();
            try {
                $this->queries()->openCandidatesIn(self::SHOP_PROJECT, self::miaVisibility(false), false);
            } catch (\LogicException $refusal) {
                return [$refusal->getMessage(), self::queryCountOf($this->debugData())];
            }
            self::fail('Mia\'s candidates were read under Beta Bakery.');
        });

        self::assertSame('Intake candidates are read under the reporter\'s own organization as tenant.', $refusal);
        self::assertSame(0, $queryCount);
    }

    /**
     * @return list<IntakeCandidate>
     */
    private function candidatesIn(string $projectId, TicketVisibility $reporter, bool $includeTeamOnly): array
    {
        return $this->transactions()->committedAs(
            self::ACME_FOODS,
            fn (): array => $this->queries()->openCandidatesIn($projectId, $reporter, $includeTeamOnly),
        );
    }

    /**
     * @param list<IntakeCandidate> $candidates
     *
     * @return list<string>
     */
    private static function keysOf(array $candidates): array
    {
        return array_map(static fn (IntakeCandidate $candidate): string => $candidate->key, $candidates);
    }

    /**
     * @return list<string> SHOP-<from> down to SHOP-<to>
     */
    private static function shopKeysFrom(int $from, int $to): array
    {
        return array_map(static fn (int $number): string => 'SHOP-'.$number, range($from, $to));
    }

    private static function miaVisibility(bool $isAdmin): TicketVisibility
    {
        return TicketVisibility::for(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), [new TeamId(self::SHOP_TEAM)], $isAdmin, null);
    }

    private static function addTicket(Connection $migrator, string $organizationId, string $projectId, string $projectKey, int $number, string $visibility, ?string $teamId, string $reporterId, int $minute, bool $closed = false): void
    {
        $updatedAt = \sprintf('2027-03-01 %02d:%02d:00+00', 9 + intdiv($minute, 60), $minute % 60);
        $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, team_id, visibility, components, check_reasons, created_at, updated_at, closed_at)
             VALUES (?, ?, ?, ?, ?, ?, '', 'bug', 'major', 2, ?, ?, ?, ?, '{}', '{}', '2027-03-01 08:00:00+00', ?, ?)",
            [
                $organizationId,
                self::ticketIdOf($organizationId, $projectKey, $number),
                $projectId,
                $number,
                $projectKey.'-'.$number,
                self::titleOf($projectKey, $number),
                self::STEP,
                $reporterId,
                $teamId,
                $visibility,
                $updatedAt,
                $closed ? $updatedAt : null,
            ],
        );
    }

    /**
     * The id grows with the number, so in a tie of updated_at the higher number has the higher id.
     */
    private static function ticketIdOf(string $organizationId, string $projectKey, int $number): string
    {
        $space = match (true) {
            self::BETA_BAKERY === $organizationId => 'b',
            'OPS' === $projectKey => 'c',
            default => 'a',
        };

        return \sprintf('0197a3c0-0000-7000-8%s00-%012x', $space, $number);
    }

    private static function titleOf(string $projectKey, int $number): string
    {
        return \sprintf('Cart total is wrong in %s case %d', $projectKey, $number);
    }

    /**
     * @param \Closure(Connection): void $work
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

    private function queries(): IntakeCandidateQueries
    {
        $queries = self::getContainer()->get('test.ticketing_intake_candidate_queries');
        \assert($queries instanceof IntakeCandidateQueries);

        return $queries;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager')->getConnection();
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), $this->tenantContext());
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }
}
