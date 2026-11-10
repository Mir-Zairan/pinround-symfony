<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Infrastructure\Doctrine\CloseOnFailedBeginConnection;
use App\SharedKernel\Infrastructure\Doctrine\TenantFilter;
use App\SharedKernel\Infrastructure\Doctrine\TenantMiddleware;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\ProjectSummary;
use App\Ticketing\Infrastructure\Doctrine\DbalProjectQueries;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalProjectQueries as pinround_app on committed tkt_project rows, in a transaction under a tenant, as inside a
 * request. Ids, names and insertion order are chosen so that ordering by any of them gives another order than by key.
 */
final class DbalProjectQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000006201';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000006202';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000006211';
    private const string MOBILE_TEAM = '0197a3c0-0000-7000-8000-000000006212';
    private const string EMPTY_TEAM = '0197a3c0-0000-7000-8000-000000006213';

    private const string SHOP = '0197a3c0-0000-7000-8000-000000006221';
    private const string CART = '0197a3c0-0000-7000-8000-000000006222';
    private const string APP = '0197a3c0-0000-7000-8000-000000006223';
    private const string IOS = '0197a3c0-0000-7000-8000-000000006224';
    private const string OPS = '0197a3c0-0000-7000-8000-000000006225';
    private const string DATA = '0197a3c0-0000-7000-8000-000000006226';
    private const string BETA_BREAD = '0197a3c0-0000-7000-8000-000000006231';
    private const string BETA_CAKE = '0197a3c0-0000-7000-8000-000000006232';

    private const array OPEN_TICKETS = [
        self::SHOP => 4,
        self::CART => 0,
        self::APP => 2,
        self::IOS => 7,
        self::OPS => 1,
        self::DATA => 3,
        self::BETA_BREAD => 5,
        self::BETA_CAKE => 6,
    ];

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->addProject(self::ACME_FOODS, self::SHOP, self::SHOP_TEAM, 'SHOP', 'Web shop');
            $this->addProject(self::ACME_FOODS, self::CART, self::SHOP_TEAM, 'CART', 'Checkout');
            $this->addProject(self::ACME_FOODS, self::APP, self::SHOP_TEAM, 'APP', 'Mobile app');
            $this->addProject(self::ACME_FOODS, self::IOS, self::MOBILE_TEAM, 'IOS', 'iPhone app');
            $this->addProject(self::ACME_FOODS, self::OPS, null, 'OPS', 'Archive');
            $this->addProject(self::ACME_FOODS, self::DATA, null, 'DATA', 'Data warehouse');
        });
        $this->transactions()->committedAs(self::BETA_BAKERY, function (): void {
            $this->addProject(self::BETA_BAKERY, self::BETA_BREAD, self::SHOP_TEAM, 'BREAD', 'Bread orders');
            $this->addProject(self::BETA_BAKERY, self::BETA_CAKE, null, 'CAKE', 'Cake orders');
        });
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function projectsOfGivesOnlyTheTeamsProjectsByKeyWithTheirOpenTicketCounts(): void
    {
        self::assertSame(
            [
                [self::APP, 'APP', 'Mobile app', 2],
                [self::CART, 'CART', 'Checkout', 0],
                [self::SHOP, 'SHOP', 'Web shop', 4],
            ],
            $this->listed(self::ACME_FOODS, static fn (DbalProjectQueries $queries): array => $queries->projectsOf(new TeamId(self::SHOP_TEAM))),
        );
        self::assertSame(
            [[self::IOS, 'IOS', 'iPhone app', 7]],
            $this->listed(self::ACME_FOODS, static fn (DbalProjectQueries $queries): array => $queries->projectsOf(new TeamId(self::MOBILE_TEAM))),
        );
    }

    #[Test]
    public function unlinkedProjectsGivesOnlyTheProjectsWithNoTeamByKey(): void
    {
        self::assertSame(
            [
                [self::DATA, 'DATA', 'Data warehouse', 3],
                [self::OPS, 'OPS', 'Archive', 1],
            ],
            $this->listed(self::ACME_FOODS, static fn (DbalProjectQueries $queries): array => $queries->unlinkedProjects()),
        );
    }

    #[Test]
    public function aTeamWithNoProjectsGetsAnEmptyListWithoutAskingForCounts(): void
    {
        $openTicketCounts = new RecordedOpenTicketCounts(self::OPEN_TICKETS);

        self::assertSame([], $this->transactions()->rolledBackAs(
            self::ACME_FOODS,
            fn (): array => $this->queries($openTicketCounts)->projectsOf(new TeamId(self::EMPTY_TEAM)),
        ));
        self::assertSame([], $openTicketCounts->calls);
    }

    #[Test]
    public function anOrganizationWithNoUnlinkedProjectsGetsAnEmptyList(): void
    {
        $this->transactions()->committedAs(self::BETA_BAKERY, function (): void {
            $this->connection()->executeStatement('UPDATE tkt_project SET team_id = ? WHERE id = ?', [self::SHOP_TEAM, self::BETA_CAKE]);
        });

        self::assertSame([], $this->listed(self::BETA_BAKERY, static fn (DbalProjectQueries $queries): array => $queries->unlinkedProjects()));
    }

    #[Test]
    public function anotherOrganizationSeesOnlyItsOwnProjects(): void
    {
        self::assertSame(
            [[self::BETA_BREAD, 'BREAD', 'Bread orders', 5]],
            $this->listed(self::BETA_BAKERY, static fn (DbalProjectQueries $queries): array => $queries->projectsOf(new TeamId(self::SHOP_TEAM))),
        );
        self::assertSame(
            [[self::BETA_CAKE, 'CAKE', 'Cake orders', 6]],
            $this->listed(self::BETA_BAKERY, static fn (DbalProjectQueries $queries): array => $queries->unlinkedProjects()),
        );
    }

    #[Test]
    public function eachListCostsOneQueryAndOneBatchCountOfAllItsProjects(): void
    {
        $openTicketCounts = new RecordedOpenTicketCounts(self::OPEN_TICKETS);
        $recordedSql = new RecordedSql();

        $countsAfterEachList = $this->onRecordedConnection(self::ACME_FOODS, $recordedSql, function (Connection $connection) use ($openTicketCounts, $recordedSql): array {
            $queries = new DbalProjectQueries($connection, $openTicketCounts, $this->tenantContext());
            $projectsOf = $queries->projectsOf(new TeamId(self::SHOP_TEAM));
            $afterProjectsOf = [\count($projectsOf), \count($recordedSql->statements), $openTicketCounts->calls];
            $unlinkedProjects = $queries->unlinkedProjects();

            return [$afterProjectsOf, [\count($unlinkedProjects), \count($recordedSql->statements), $openTicketCounts->calls]];
        });

        self::assertSame(
            [
                [3, 1, [[self::APP, self::CART, self::SHOP]]],
                [2, 2, [[self::APP, self::CART, self::SHOP], [self::DATA, self::OPS]]],
            ],
            $countsAfterEachList,
        );
    }

    /**
     * Explains the SQL the queries really send. Without statistics the planner cannot tell the team index from the
     * organization's key index and takes the key one to save the sort, so the plan is made, as the migrator (which
     * owns the table and so may ANALYZE it), over an organization of 400 projects in 40 teams, one in 20 unlinked.
     * The rows and the statistics are rolled back with the transaction.
     */
    #[Test]
    public function bothQueriesUseTheOrganizationAndTeamIndex(): void
    {
        $recordedSql = new RecordedSql();
        $this->onRecordedConnection(self::ACME_FOODS, $recordedSql, function (Connection $connection): void {
            $queries = new DbalProjectQueries($connection, new RecordedOpenTicketCounts(self::OPEN_TICKETS), $this->tenantContext());
            $queries->projectsOf(new TeamId(self::SHOP_TEAM));
            $queries->unlinkedProjects();
        });
        [$projectsOfSql, $unlinkedProjectsSql] = $recordedSql->statements;

        $migrator = $this->migrator();
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $migrator->beginTransaction();
        try {
            $migrator->executeStatement(
                "INSERT INTO tkt_project (organization_id, id, team_id, key, name, next_number)
                 SELECT ?, uuidv7(), CASE WHEN n % 20 = 0 THEN NULL ELSE ('0197a3c0-0000-7000-8000-0000000063' || lpad((n % 40)::text, 2, '0'))::uuid END,
                        'P' || n, 'Project ' || n, 1
                 FROM generate_series(1, 400) AS n",
                [self::ACME_FOODS],
            );
            $migrator->executeStatement('ANALYZE tkt_project');
            // At this size a sequential scan still costs less than any index.
            $migrator->executeStatement('SET LOCAL enable_seqscan = off');
            $projectsOfPlan = $this->planOf($migrator, $projectsOfSql, [self::ACME_FOODS, self::SHOP_TEAM]);
            $unlinkedProjectsPlan = $this->planOf($migrator, $unlinkedProjectsSql, [self::ACME_FOODS]);
        } finally {
            $migrator->rollBack();
            $this->tenantContext()->clear();
        }

        self::assertMatchesRegularExpression('/Index Scan (using|on) tkt_project_organization_id_team_id_idx\b/', $projectsOfPlan);
        self::assertStringContainsString(\sprintf("Index Cond: ((organization_id = '%s'::uuid) AND (team_id = '%s'::uuid))", self::ACME_FOODS, self::SHOP_TEAM), $projectsOfPlan);
        self::assertMatchesRegularExpression('/Index Scan (using|on) tkt_project_organization_id_team_id_idx\b/', $unlinkedProjectsPlan);
        self::assertStringContainsString(\sprintf("Index Cond: ((organization_id = '%s'::uuid) AND (team_id IS NULL))", self::ACME_FOODS), $unlinkedProjectsPlan);
    }

    #[Test]
    public function aProjectTheCountsLeaveOutIsAnError(): void
    {
        $countsWithoutApp = array_diff_key(self::OPEN_TICKETS, [self::APP => true]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('OpenTicketCounts gave no count for a project.');

        $this->transactions()->rolledBackAs(
            self::ACME_FOODS,
            fn (): array => $this->queries(new RecordedOpenTicketCounts($countsWithoutApp))->projectsOf(new TeamId(self::SHOP_TEAM)),
        );
    }

    /**
     * The SQL names the organization of TenantContext, while the transaction's tenant, which row-level security uses,
     * is another one: what still hides Acme Foods' projects from Beta Bakery's transaction is row-level security alone.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsProjectsWithTheTenantFilterOff(): void
    {
        $this->entityManager()->getFilters()->disable(TenantFilter::NAME);

        $listsUnderAcmeContext = $this->onRecordedConnection(self::BETA_BAKERY, new RecordedSql(), function (Connection $connection): array {
            $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
            $queries = new DbalProjectQueries($connection, new RecordedOpenTicketCounts(self::OPEN_TICKETS), $this->tenantContext());

            return [$queries->projectsOf(new TeamId(self::SHOP_TEAM)), $queries->unlinkedProjects()];
        });

        self::assertFalse($this->entityManager()->getFilters()->isEnabled(TenantFilter::NAME));
        self::assertSame([[], []], $listsUnderAcmeContext);
    }

    #[Test]
    public function withNoTenantBothQueriesRefuseBeforeAnyQuery(): void
    {
        $refusals = [];
        foreach ([
            static fn (DbalProjectQueries $queries): array => $queries->projectsOf(new TeamId(self::SHOP_TEAM)),
            static fn (DbalProjectQueries $queries): array => $queries->unlinkedProjects(),
        ] as $list) {
            $recordedSql = new RecordedSql();
            $connection = new CloseOnFailedBeginConnection($this->connection()->getParams(), $recordedSql->wrap(new TenantMiddleware($this->tenantContext())->wrap(new PgSQLDriver())));
            try {
                $list(new DbalProjectQueries($connection, new RecordedOpenTicketCounts(self::OPEN_TICKETS), $this->tenantContext()));
            } catch (TenantNotSet) {
                $refusals[] = $recordedSql->statements;
            } finally {
                $connection->close();
            }
        }

        self::assertSame([[], []], $refusals);
    }

    /**
     * @param list<string> $values the values of the statement's placeholders, in order; uuids only, so they need no
     *                             escaping
     */
    private function planOf(Connection $connection, string $sql, array $values): string
    {
        $literalSql = preg_replace_callback('/\?/', static function () use (&$values): string {
            return "'".array_shift($values)."'";
        }, $sql);
        self::assertSame([], $values, 'Every value fills a placeholder.');

        return implode("\n", array_map(
            static fn (mixed $line): string => \is_string($line) ? $line : '',
            $connection->fetchFirstColumn('EXPLAIN '.$literalSql),
        ));
    }

    /**
     * Runs $work in a rolled-back transaction under $organizationId on a connection of its own whose statements
     * $recordedSql records; the tenant's set_config is not among them.
     *
     * @template T
     *
     * @param \Closure(Connection): T $work
     *
     * @return T
     */
    private function onRecordedConnection(string $organizationId, RecordedSql $recordedSql, \Closure $work): mixed
    {
        $this->tenantContext()->set(new OrganizationId($organizationId));
        $connection = new CloseOnFailedBeginConnection(
            $this->connection()->getParams(),
            $recordedSql->wrap(new TenantMiddleware($this->tenantContext())->wrap(new PgSQLDriver())),
        );
        $connection->beginTransaction();
        try {
            return $work($connection);
        } finally {
            $connection->rollBack();
            $connection->close();
            $this->tenantContext()->clear();
        }
    }

    /**
     * @param \Closure(DbalProjectQueries): list<ProjectSummary> $list
     *
     * @return list<array{string, string, string, int}>
     */
    private function listed(string $organizationId, \Closure $list): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => array_map(
            static fn (ProjectSummary $project): array => [$project->id(), $project->key(), $project->name(), $project->openTicketCount()],
            $list($this->queries(new RecordedOpenTicketCounts(self::OPEN_TICKETS))),
        ));
    }

    private function addProject(string $organizationId, string $projectId, ?string $teamId, string $key, string $name): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO tkt_project (organization_id, id, team_id, key, name, next_number) VALUES (?, ?, ?, ?, ?, 1)',
            [$organizationId, $projectId, $teamId, $key, $name],
        );
    }

    private function queries(RecordedOpenTicketCounts $openTicketCounts): DbalProjectQueries
    {
        return new DbalProjectQueries($this->connection(), $openTicketCounts, $this->tenantContext());
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
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

    private function rows(): ProjectRows
    {
        return new ProjectRows($this->migrator(), $this->tenantContext());
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }
}
