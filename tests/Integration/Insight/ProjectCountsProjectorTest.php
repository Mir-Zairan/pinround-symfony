<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insight;

use App\Insight\Api\ProjectCountQueries;
use App\Insight\Api\ProjectCounts;
use App\Insight\Api\ProjectCountsRecount;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Factory\Ticketing\TicketFactory;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use App\Ticketing\Api\TicketCommands;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ins_project_counts as ProjectCountsProjector keeps it when a ticket is created, as DbalProjectCountsRecount rebuilds
 * it, and as the backfill migration Version20261112140100 fills it.
 */
final class ProjectCountsProjectorTest extends KernelTestCase
{
    private const string BACKFILL = 'DoctrineMigrations\Version20261112140100';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000007401';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000007402';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000007411';
    private const string BETA_TEAM = '0197a3c0-0000-7000-8000-000000007412';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-000000007421';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-000000007422';
    private const string LENA = '0197a3c0-0000-7000-8000-000000007431';
    private const string BEN = '0197a3c0-0000-7000-8000-000000007432';
    private const string DONE_STEP = '0197a3c0-0000-7000-8000-000000007441';

    protected function setUp(): void
    {
        $this->removeRows();
        foreach ([[self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, 'Lena'], [self::BETA_BAKERY, self::BETA_TEAM, self::BETA_PROJECT, self::BEN, 'Ben']] as [$organizationId, $teamId, $projectId, $leadId, $leadName]) {
            $this->shopTeamRows()->add($organizationId, $teamId, $projectId, $leadId, [$leadId => [$leadName, 'lead', 'developer']]);
            $this->asTenantMigrator($organizationId, static function (Connection $migrator) use ($organizationId, $leadName): void {
                $migrator->executeStatement(
                    "INSERT INTO org_organization (organization_id, name, slug, status, created_at) VALUES (?, ?, ?, 'active', '2027-03-15 10:00:00+00')",
                    [$organizationId, $leadName.' counts', strtolower($leadName).'-counts'],
                );
                $migrator->executeStatement(
                    "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Done', 'done', 'done', 'core', 7)",
                    [$organizationId, self::DONE_STEP],
                );
            });
        }
    }

    protected function tearDown(): void
    {
        $this->removeRows();
        self::getContainer()->get(TenantContext::class)->reset();
        parent::tearDown();
    }

    /**
     * The count is written by the creating transaction itself: it is there before the commit, and a creation that
     * rolls back leaves the earlier count as it was.
     */
    #[Test]
    public function creatingATicketCountsItOpenInTheSameTransaction(): void
    {
        $this->ticketFactory()->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::LENA);
        self::assertSame([[self::SHOP_PROJECT, 'all', 1, 0]], $this->storedCountsOf(self::ACME_FOODS));

        $countsInsideTheCreation = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->ticketCommands()->create(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), self::SHOP_PROJECT, 'Basket count is wrong', '', 'bug', 'normal', null, new \DateTimeImmutable('2027-03-15 11:00:00 UTC'));

            return $this->countsQuery();
        });

        self::assertSame([[self::SHOP_PROJECT, 'all', 2, 0]], $countsInsideTheCreation);
        self::assertSame([[self::SHOP_PROJECT, 'all', 1, 0]], $this->storedCountsOf(self::ACME_FOODS));
        self::assertSame([], $this->storedCountsOf(self::BETA_BAKERY));
    }

    /**
     * Four Acme Foods tickets, one per mix of done and team-only, put there by raw UPDATEs the creation did not count,
     * and one Beta Bakery ticket. The recount follows the step group and visibility, equals a plain count of tkt_ticket,
     * leaves the other organization alone and gives the same rows when run again.
     */
    #[Test]
    public function theRecountCountsEachTicketByStepGroupAndVisibilityInItsOrganizationOnly(): void
    {
        $this->seedTicketsInEveryMix();
        self::assertSame([[self::SHOP_PROJECT, 'all', 4, 0]], $this->storedCountsOf(self::ACME_FOODS), 'The raw UPDATEs left the counts of the creations.');

        $this->recountAs(self::ACME_FOODS);
        $this->recountAs(self::ACME_FOODS);

        $expected = [[self::SHOP_PROJECT, 'all', 1, 1], [self::SHOP_PROJECT, 'team', 1, 1]];
        self::assertSame($expected, $this->storedCountsOf(self::ACME_FOODS));
        self::assertSame($expected, $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllNumeric(
            "SELECT ticket.project_id, ticket.visibility, count(*) FILTER (WHERE step.grp <> 'done'), count(*) FILTER (WHERE step.grp = 'done')
             FROM tkt_ticket ticket JOIN wf_step step ON step.organization_id = ticket.organization_id AND step.id = ticket.step_id
             GROUP BY 1, 2 ORDER BY 1, 2",
        )));
        self::assertSame([[self::BETA_PROJECT, 'all', 1, 0]], $this->storedCountsOf(self::BETA_BAKERY));
    }

    /**
     * No command moves a ticket yet (P03.T7), so the move is a raw UPDATE followed by the recount. T7's move must keep
     * the counts in its own transaction: replace the UPDATE and the recount with the move command, and this must still
     * pass.
     */
    #[Test]
    public function aTicketMovedToDoneCountsAsDoneInsteadOfOpen(): void
    {
        $ticketId = $this->ticketFactory()->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::LENA);
        self::assertSame([[self::SHOP_PROJECT, 'all', 1, 0]], $this->storedCountsOf(self::ACME_FOODS));

        $this->moveToDone(self::ACME_FOODS, $ticketId);
        $this->recountAs(self::ACME_FOODS);

        self::assertSame([[self::SHOP_PROJECT, 'all', 0, 1]], $this->storedCountsOf(self::ACME_FOODS));
    }

    /**
     * Lena leads the Shop team, so she counts the team-only tickets; Ben is a user with no role in Acme Foods, so he
     * counts only the tickets for everyone. Done counts come only when asked for.
     */
    #[Test]
    public function theRailReadGivesTheTeamEveryTicketAndOthersOnlyTicketsForEveryone(): void
    {
        $this->seedTicketsInEveryMix();
        $this->recountAs(self::ACME_FOODS);
        $readAs = fn (string $viewerId, bool $withDone): array => $this->transactions()->rolledBackAs(self::ACME_FOODS, static fn (): array => array_map(
            static fn (ProjectCounts $counts): array => [$counts->projectId, $counts->openCount, $counts->doneCount],
            self::projectCountQueries()->countsOfTeam(new TeamId(self::SHOP_TEAM), new UserId($viewerId), $withDone),
        ));

        self::assertSame([[self::SHOP_PROJECT, 2, 2]], $readAs(self::LENA, true));
        self::assertSame([[self::SHOP_PROJECT, 2, null]], $readAs(self::LENA, false));
        self::assertSame([[self::SHOP_PROJECT, 1, 1]], $readAs(self::BEN, true));
        self::assertSame([[self::SHOP_PROJECT, 1, null]], $readAs(self::BEN, false));
        self::assertSame([], $this->transactions()->rolledBackAs(self::ACME_FOODS, static fn (): array => self::projectCountQueries()->countsOfTeam(new TeamId(self::BETA_TEAM), new UserId(self::LENA), true)));
    }

    #[Test]
    public function theRailReadGivesZerosForAProjectWithNoTicket(): void
    {
        $counts = $this->transactions()->rolledBackAs(self::ACME_FOODS, static fn (): array => self::projectCountQueries()->countsOfTeam(new TeamId(self::SHOP_TEAM), new UserId(self::LENA), true));

        self::assertEquals([new ProjectCounts(self::SHOP_PROJECT, 0, 0)], $counts);
    }

    #[Test]
    public function theBackfillFillsEveryOrganizationAsTheRecountDoes(): void
    {
        $this->seedTicketsInEveryMix();
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $this->asTenantMigrator($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM ins_project_counts'));
        }

        $this->runBackfill();

        self::assertSame([[self::SHOP_PROJECT, 'all', 1, 1], [self::SHOP_PROJECT, 'team', 1, 1]], $this->storedCountsOf(self::ACME_FOODS));
        self::assertSame([[self::BETA_PROJECT, 'all', 1, 0]], $this->storedCountsOf(self::BETA_BAKERY));
        self::assertSame('on', $this->connection()->fetchOne("SELECT CASE WHEN relforcerowsecurity THEN 'on' ELSE 'off' END FROM pg_class WHERE oid = 'public.org_organization'::regclass"));
    }

    #[Test]
    public function theRecountRefusesAnotherOrganizationThanTheTenantAndChangesNothing(): void
    {
        $this->ticketFactory()->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::LENA);
        $this->ticketFactory()->createOne(self::BETA_BAKERY, self::BETA_PROJECT, self::BEN);

        try {
            $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->recount()->recount(new OrganizationId(self::BETA_BAKERY)));
            self::fail('The recount ran for another organization than the tenant.');
        } catch (\LogicException $refusal) {
            self::assertSame('The project counts are recounted under their own organization as tenant.', $refusal->getMessage());
        }

        self::assertSame([[self::BETA_PROJECT, 'all', 1, 0]], $this->storedCountsOf(self::BETA_BAKERY));
    }

    #[Test]
    public function theRecountRefusesToRunOutsideATransaction(): void
    {
        $this->expectExceptionObject(new \LogicException('The project counts are recounted in a transaction under the organization as tenant; none is open.'));

        $this->recount()->recount(new OrganizationId(self::ACME_FOODS));
    }

    /**
     * Acme Foods: one open and one done ticket for everyone, one open and one done team-only. Beta Bakery: one open.
     */
    private function seedTicketsInEveryMix(): void
    {
        $tickets = [];
        foreach (['Open for all', 'Done for all', 'Open team-only', 'Done team-only'] as $title) {
            $tickets[$title] = $this->ticketFactory()->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::LENA, ['title' => $title]);
        }
        $this->ticketFactory()->createOne(self::BETA_BAKERY, self::BETA_PROJECT, self::BEN);

        $this->moveToDone(self::ACME_FOODS, $tickets['Done for all']);
        $this->moveToDone(self::ACME_FOODS, $tickets['Done team-only']);
        $this->asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE tkt_ticket SET visibility = 'team' WHERE id IN (?, ?)",
            [$tickets['Open team-only']->toString(), $tickets['Done team-only']->toString()],
        ));
    }

    private function moveToDone(string $organizationId, TicketId $ticketId): void
    {
        $this->asTenantMigrator($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE tkt_ticket SET step_id = ?, closed_at = '2027-03-16 10:00:00+00' WHERE id = ?",
            [self::DONE_STEP, $ticketId->toString()],
        ));
    }

    private function recountAs(string $organizationId): void
    {
        $this->transactions()->committedAs($organizationId, fn () => $this->recount()->recount(new OrganizationId($organizationId)));
    }

    /**
     * @return list<list<mixed>> project, visibility, open and done count, by project and visibility
     */
    private function storedCountsOf(string $organizationId): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->countsQuery());
    }

    /**
     * @return list<list<mixed>>
     */
    private function countsQuery(): array
    {
        return $this->connection()->fetchAllNumeric('SELECT project_id, visibility, open_count, done_count FROM ins_project_counts ORDER BY project_id, visibility');
    }

    /** Loads the migration as doctrine:migrations:migrate does and runs its SQL as the migrator. */
    private function runBackfill(): void
    {
        $migrator = $this->migrator();
        $configuration = new Configuration();
        $configuration->setMetadataStorageConfiguration(new TableMetadataStorageConfiguration());
        $configuration->addMigrationsDirectory('DoctrineMigrations', self::getContainer()->getParameter('kernel.project_dir').'/migrations');
        $migrations = DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($migrator));
        $backfill = $migrations->getMigrationRepository()->getMigration(new Version(self::BACKFILL))->getMigration();
        $backfill->up($migrator->createSchemaManager()->introspectSchema());

        $statements = $backfill->getSql();
        self::assertCount(1, $statements);
        foreach ($statements as $statement) {
            $migrator->executeStatement($statement->getStatement());
        }
    }

    /**
     * @param \Closure(Connection): mixed $work
     */
    private function asTenantMigrator(string $organizationId, \Closure $work): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId($organizationId));
        try {
            $this->migrator()->transactional($work);
        } finally {
            $tenantContext->clear();
        }
    }

    private function removeRows(): void
    {
        $this->shopTeamRows()->removeAllOf([self::LENA, self::BEN], self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function ticketFactory(): TicketFactory
    {
        return new TicketFactory($this->ticketCommands(), $this->transactions(), $this->connection());
    }

    private function ticketCommands(): TicketCommands
    {
        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        \assert($ticketCommands instanceof TicketCommands);

        return $ticketCommands;
    }

    private static function projectCountQueries(): ProjectCountQueries
    {
        $queries = self::getContainer()->get('test.insight_project_count_queries');
        \assert($queries instanceof ProjectCountQueries);

        return $queries;
    }

    private function recount(): ProjectCountsRecount
    {
        return self::getContainer()->get(ProjectCountsRecount::class);
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager')->getConnection();
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows($this->migrator(), self::getContainer()->get(TenantContext::class));
    }
}
