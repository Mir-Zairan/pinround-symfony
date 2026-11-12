<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insight;

use App\Insight\Api\ProjectCountQueries;
use App\Insight\Api\ProjectCounts;
use App\Insight\Api\TurnProjector;
use App\Insight\Infrastructure\Doctrine\CountedTicket;
use App\Insight\Infrastructure\Doctrine\DbalProjectCountsProjector;
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
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ins_project_counts as TurnProjector::refresh moves each ticket between its rows (DbalProjectCountsProjector), as the
 * rail reads it, and as the backfill migration Version20261112140100 fills it. No command moves a ticket yet (P03.T7),
 * so a move here is a raw UPDATE followed by the refresh every change must call.
 */
final class DbalProjectCountsProjectorTest extends KernelTestCase
{
    private const string BACKFILL = 'DoctrineMigrations\Version20261112140100';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000007401';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000007402';
    private const string NO_ORGANIZATION = '0197a3c0-0000-7000-8000-000000007499';
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
        self::assertSame([[self::SHOP_PROJECT, self::SHOP_TEAM, 'all', 1, 0]], $this->storedCountsOf(self::ACME_FOODS));

        $countsInsideTheCreation = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->ticketCommands()->create(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), self::SHOP_PROJECT, 'Basket count is wrong', '', 'bug', 'normal', null, new \DateTimeImmutable('2027-03-15 11:00:00 UTC'));

            return $this->countsQuery();
        });

        self::assertSame([[self::SHOP_PROJECT, self::SHOP_TEAM, 'all', 2, 0]], $countsInsideTheCreation);
        self::assertSame([[self::SHOP_PROJECT, self::SHOP_TEAM, 'all', 1, 0]], $this->storedCountsOf(self::ACME_FOODS));
        self::assertSame([], $this->storedCountsOf(self::BETA_BAKERY));
    }

    /**
     * Four Acme Foods tickets, one per mix of done and team-only, and one Beta Bakery ticket. The refreshes follow the
     * step group and visibility, equal a plain count of tkt_ticket, leave the other organization alone, and change
     * nothing when run again.
     */
    #[Test]
    public function eachRefreshCountsTheTicketByStepGroupTeamAndVisibilityInItsOrganizationOnly(): void
    {
        $tickets = $this->seedTicketsInEveryMix();
        $this->refreshAs(self::ACME_FOODS, ...$tickets);

        $expected = [[self::SHOP_PROJECT, self::SHOP_TEAM, 'all', 1, 1], [self::SHOP_PROJECT, self::SHOP_TEAM, 'team', 1, 1]];
        self::assertSame($expected, $this->storedCountsOf(self::ACME_FOODS));
        self::assertSame($expected, $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllNumeric(
            "SELECT ticket.project_id, ticket.team_id, ticket.visibility, count(*) FILTER (WHERE step.grp <> 'done'), count(*) FILTER (WHERE step.grp = 'done')
             FROM tkt_ticket ticket JOIN wf_step step ON step.organization_id = ticket.organization_id AND step.id = ticket.step_id
             GROUP BY 1, 2, 3 ORDER BY 1, 2, 3",
        )));
        self::assertSame([[self::BETA_PROJECT, self::BETA_TEAM, 'all', 1, 0]], $this->storedCountsOf(self::BETA_BAKERY));

        $this->refreshAs(self::ACME_FOODS, ...$tickets);

        self::assertSame($expected, $this->storedCountsOf(self::ACME_FOODS));
    }

    /**
     * Replace the raw UPDATEs with T7's move command, and this must still pass.
     */
    #[Test]
    public function aTicketMovedToDoneCountsAsDoneAndBackAsOpenWhenReopened(): void
    {
        $ticketId = $this->ticketFactory()->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::LENA);

        $this->moveToDone(self::ACME_FOODS, $ticketId);
        $this->refreshAs(self::ACME_FOODS, $ticketId);
        $countsWhenDone = $this->storedCountsOf(self::ACME_FOODS);
        $this->asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET step_id = ?, closed_at = NULL WHERE id = ?',
            [ShopTeamRows::TO_DO_STEP, $ticketId->toString()],
        ));
        $this->refreshAs(self::ACME_FOODS, $ticketId);

        self::assertSame([[self::SHOP_PROJECT, self::SHOP_TEAM, 'all', 0, 1]], $countsWhenDone);
        self::assertSame([[self::SHOP_PROJECT, self::SHOP_TEAM, 'all', 1, 0]], $this->storedCountsOf(self::ACME_FOODS));
    }

    #[Test]
    public function aTicketThatLosesItsTeamIsCountedNowhere(): void
    {
        $ticketId = $this->ticketFactory()->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::LENA);
        $this->ticketFactory()->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::LENA, ['title' => 'Still in the team']);

        $this->asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement('UPDATE tkt_ticket SET team_id = NULL WHERE id = ?', [$ticketId->toString()]));
        $this->refreshAs(self::ACME_FOODS, $ticketId);

        self::assertSame([[self::SHOP_PROJECT, self::SHOP_TEAM, 'all', 1, 0]], $this->storedCountsOf(self::ACME_FOODS));
        self::assertSame(1, $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM ins_project_count_ticket')));
    }

    /**
     * Lena leads the Shop team, so she counts the team-only tickets; Ben is a user with no role in Acme Foods, so he
     * counts only the tickets for everyone. Done counts come only when asked for.
     */
    #[Test]
    public function theRailReadGivesTheTeamEveryTicketAndOthersOnlyTicketsForEveryone(): void
    {
        $this->refreshAs(self::ACME_FOODS, ...$this->seedTicketsInEveryMix());
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
    public function theRailReadRefusesToRunOutsideATransaction(): void
    {
        $this->expectExceptionObject(new \LogicException('The project counts are read in a transaction under the organization as tenant; none is open.'));

        self::projectCountQueries()->countsOfTeam(new TeamId(self::SHOP_TEAM), new UserId(self::LENA), true);
    }

    /**
     * Stale rows in both organizations are replaced with what the refreshes kept, twice the same, and the caller's
     * tenant setting is put back.
     */
    #[Test]
    public function theBackfillReplacesStaleRowsAsTheRefreshesKeepThemAndPutsTheTenantBack(): void
    {
        $this->refreshAs(self::ACME_FOODS, ...$this->seedTicketsInEveryMix());
        $keptByRefreshes = $this->storedRowsOfBoth();
        $this->asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement('UPDATE ins_project_counts SET open_count = open_count + 7'));
        $this->asTenantMigrator(self::BETA_BAKERY, static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM ins_project_count_ticket'));
        self::assertNotSame($keptByRefreshes, $this->storedRowsOfBoth(), 'The stale rows differ from the kept ones.');

        $tenantAfter = $this->migrator()->transactional(function (Connection $migrator): mixed {
            $migrator->executeQuery("SELECT set_config('app.org_id', ?, true)", [self::NO_ORGANIZATION]);
            $this->runBackfill($migrator);
            $this->runBackfill($migrator);

            return $migrator->fetchOne("SELECT current_setting('app.org_id')");
        });

        self::assertSame(self::NO_ORGANIZATION, $tenantAfter);
        self::assertSame($keptByRefreshes, $this->storedRowsOfBoth());
        self::assertSame('on', $this->connection()->fetchOne("SELECT CASE WHEN relforcerowsecurity THEN 'on' ELSE 'off' END FROM pg_class WHERE oid = 'public.org_organization'::regclass"));
    }

    #[Test]
    public function theCountMoveRefusesToRunOutsideATransactionAndChangesNothing(): void
    {
        $ticketId = $this->ticketFactory()->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::LENA);
        $this->ticketFactory()->createOne(self::BETA_BAKERY, self::BETA_PROJECT, self::BEN);
        $before = $this->storedRowsOfBoth();
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));

        try {
            $this->countsProjector()->moveTicket($ticketId, new CountedTicket(self::SHOP_PROJECT, new TeamId(self::SHOP_TEAM), 'all', true));
            self::fail('The count moved outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('A ticket\'s project count is moved in the transaction of the change; none is open.', $refusal->getMessage());
        } finally {
            self::getContainer()->get(TenantContext::class)->clear();
        }

        self::assertSame($before, $this->storedRowsOfBoth());
    }

    /**
     * Under Acme Foods, Beta Bakery's ticket is not there: its refresh counts nothing, and a direct move is refused by
     * the ticket's foreign key, as the pair (Acme Foods, that ticket) names no ticket.
     */
    #[Test]
    public function anotherOrganizationsTicketUnderTheTenantChangesNoCount(): void
    {
        $this->ticketFactory()->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::LENA);
        $betaTicketId = $this->ticketFactory()->createOne(self::BETA_BAKERY, self::BETA_PROJECT, self::BEN);
        $before = $this->storedRowsOfBoth();
        self::assertSame([[self::SHOP_PROJECT, self::SHOP_TEAM, 'all', 1, 0]], $before[self::ACME_FOODS][0]);
        self::assertSame([[self::BETA_PROJECT, self::BETA_TEAM, 'all', 1, 0]], $before[self::BETA_BAKERY][0]);

        $this->refreshAs(self::ACME_FOODS, $betaTicketId);
        try {
            $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->countsProjector()->moveTicket($betaTicketId, new CountedTicket(self::SHOP_PROJECT, new TeamId(self::SHOP_TEAM), 'all', true)));
            self::fail('Another organization\'s ticket was counted under the tenant.');
        } catch (ForeignKeyConstraintViolationException $refusal) {
            self::assertStringContainsString('violates foreign key constraint "ins_project_count_ticket_ticket_fkey"', $refusal->getMessage());
        }

        self::assertSame($before, $this->storedRowsOfBoth());
    }

    /**
     * A ticket counted in a row that has since gone (only a hand edit can do that) fails the change, rather than
     * counting the ticket only where it went.
     */
    #[Test]
    public function aMissingCountRowFailsTheChangeAndKeepsTheTicketWhereItWas(): void
    {
        $ticketId = $this->ticketFactory()->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::LENA);
        $this->asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM ins_project_counts'));
        $this->moveToDone(self::ACME_FOODS, $ticketId);

        try {
            $this->refreshAs(self::ACME_FOODS, $ticketId);
            self::fail('The ticket moved out of a count row that is missing.');
        } catch (\LogicException $refusal) {
            self::assertSame('The project count row of a ticket it counts is missing.', $refusal->getMessage());
        }

        self::assertSame([], $this->storedCountsOf(self::ACME_FOODS));
        self::assertSame([[$ticketId->toString(), false]], $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllNumeric('SELECT ticket_id, is_done FROM ins_project_count_ticket')));
    }

    /**
     * Acme Foods: one open and one done ticket for everyone, one open and one done team-only, put there by raw UPDATEs
     * the creation did not count. Beta Bakery: one open.
     *
     * @return list<TicketId> the Acme Foods tickets
     */
    private function seedTicketsInEveryMix(): array
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
        self::assertSame([[self::SHOP_PROJECT, self::SHOP_TEAM, 'all', 4, 0]], $this->storedCountsOf(self::ACME_FOODS), 'The raw UPDATEs left the counts of the creations.');

        return array_values($tickets);
    }

    private function moveToDone(string $organizationId, TicketId $ticketId): void
    {
        $this->asTenantMigrator($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE tkt_ticket SET step_id = ?, closed_at = '2027-03-16 10:00:00+00' WHERE id = ?",
            [self::DONE_STEP, $ticketId->toString()],
        ));
    }

    private function refreshAs(string $organizationId, TicketId ...$ticketIds): void
    {
        $this->transactions()->committedAs($organizationId, static function () use ($ticketIds): void {
            foreach ($ticketIds as $ticketId) {
                self::getContainer()->get(TurnProjector::class)->refresh($ticketId);
            }
        });
    }

    /**
     * @return list<list<mixed>> project, team, visibility, open and done count, by project, team and visibility
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
        return $this->connection()->fetchAllNumeric('SELECT project_id, team_id, visibility, open_count, done_count FROM ins_project_counts ORDER BY project_id, team_id, visibility');
    }

    /**
     * @return array<string, array{list<list<mixed>>, list<list<mixed>>}> each organization's count rows and counted tickets
     */
    private function storedRowsOfBoth(): array
    {
        $rows = [];
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $rows[$organizationId] = [$this->storedCountsOf($organizationId), $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchAllNumeric(
                'SELECT ticket_id, project_id, team_id, visibility, is_done FROM ins_project_count_ticket ORDER BY ticket_id',
            ))];
        }

        return $rows;
    }

    /** Loads the migration as doctrine:migrations:migrate does and runs its SQL on the migrator's open transaction. */
    private function runBackfill(Connection $migrator): void
    {
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

    private function countsProjector(): DbalProjectCountsProjector
    {
        return new DbalProjectCountsProjector($this->connection(), self::getContainer()->get(TenantContext::class));
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
