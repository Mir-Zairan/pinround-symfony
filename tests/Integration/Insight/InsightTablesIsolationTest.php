<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insight;

use App\SharedKernel\Application\TenantContext;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves row-level security on every ins_ table (ADR-0005) as pinround_app: with Acme Foods as tenant, Beta Bakery's
 * rows are invisible and cannot be written, removed, moved into or out of; without a tenant no row is visible.
 */
final class InsightTablesIsolationTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000018a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000018b1';
    // An organization with no rows: the target of smuggled inserts and moved rows.
    private const string THIRD_ORG = '0197a3c0-0000-7000-8000-0000000018c1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000018d1';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000018e1';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000018f1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000018d9';
    private const array TABLES = ['ins_ticket_turn', 'ins_project_counts', 'ins_project_count_ticket'];
    private const string COUNTS_INSERT = "INSERT INTO ins_project_counts (organization_id, project_id, team_id, visibility, open_count, done_count) VALUES ('%s', '%s', '".self::SHOP_TEAM."', 'all', 4, 1)";
    private const string COUNTS_UPSERT = ' ON CONFLICT (organization_id, project_id, team_id, visibility) DO UPDATE SET open_count = 99';
    private const string COUNTED_TICKET_INSERT = "INSERT INTO ins_project_count_ticket (organization_id, ticket_id, project_id, team_id, visibility, is_done) VALUES ('%s', '%s', '%s', '".self::SHOP_TEAM."', 'all', true)";
    private const string INSERT = "INSERT INTO ins_ticket_turn (organization_id, ticket_id, project_id, grp, turn_user_id, kind, base_score, waiting_since, visibility, reporter_id) VALUES ('%s', '%s', '%s', 'doing', '%s', 'build', 18, '2027-03-15 10:00:00+00', 'all', '%s')";

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY, self::THIRD_ORG);
        foreach ([self::ACME_FOODS, self::BETA_BAKERY, self::THIRD_ORG] as $organizationId) {
            $this->ticketRows()->addOf($organizationId, self::TICKET, self::SHOP_PROJECT, self::LENA);
        }
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $this->transactions()->committedAs($organizationId, fn (): int|string => $this->connection()->executeStatement($this->insertOf($organizationId)));
            $this->transactions()->committedAs($organizationId, fn (): int|string => $this->connection()->executeStatement(\sprintf(self::COUNTS_INSERT, $organizationId, self::SHOP_PROJECT)));
            $this->transactions()->committedAs($organizationId, fn (): int|string => $this->connection()->executeStatement(\sprintf(self::COUNTED_TICKET_INSERT, $organizationId, self::TICKET, self::SHOP_PROJECT)));
        }
    }

    #[Test]
    public function projectCountsOfAnotherOrganizationCannotBeSeenAddedChangedMovedOrUpserted(): void
    {
        $betaCounts = fn (): mixed => $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): mixed => $this->connection()->fetchAllNumeric('SELECT organization_id, open_count, done_count FROM ins_project_counts'));
        $betaCountsBefore = $betaCounts();

        self::assertSame([self::ACME_FOODS], $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn('SELECT DISTINCT organization_id FROM ins_project_counts')));
        self::assertSame(0, $this->connection()->fetchOne('SELECT count(*) FROM ins_project_counts'));
        $this->assertRefusedByRowLevelSecurity(\sprintf(self::COUNTS_INSERT, self::THIRD_ORG, self::SHOP_PROJECT), 'ins_project_counts');
        // The upsert DbalProjectCountsProjector runs, aimed at Beta Bakery's row: the conflicting row is invisible, so it is an insert.
        $this->assertRefusedByRowLevelSecurity(\sprintf(self::COUNTS_INSERT, self::BETA_BAKERY, self::SHOP_PROJECT).self::COUNTS_UPSERT, 'ins_project_counts');
        $this->assertRefusedByRowLevelSecurity(\sprintf("UPDATE ins_project_counts SET organization_id = '%s' WHERE organization_id = '%s'", self::THIRD_ORG, self::ACME_FOODS), 'ins_project_counts');
        self::assertSame(0, $this->asAcmeFoods(\sprintf("UPDATE ins_project_counts SET open_count = 99 WHERE organization_id = '%s'", self::BETA_BAKERY)));

        self::assertSame([[self::BETA_BAKERY, 4, 1]], $betaCountsBefore);
        self::assertSame($betaCountsBefore, $betaCounts());
    }

    #[Test]
    public function countedTicketsOfAnotherOrganizationCannotBeSeenAddedChangedRemovedMovedOrUpserted(): void
    {
        $betaCountedTickets = fn (): mixed => $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): mixed => $this->connection()->fetchAllNumeric('SELECT organization_id, ticket_id, is_done FROM ins_project_count_ticket'));
        $betaCountedTicketsBefore = $betaCountedTickets();

        self::assertSame([self::ACME_FOODS], $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn('SELECT DISTINCT organization_id FROM ins_project_count_ticket')));
        self::assertSame(0, $this->connection()->fetchOne('SELECT count(*) FROM ins_project_count_ticket'));
        $this->assertRefusedByRowLevelSecurity(\sprintf(self::COUNTED_TICKET_INSERT, self::THIRD_ORG, self::TICKET, self::SHOP_PROJECT), 'ins_project_count_ticket');
        $this->assertRefusedByRowLevelSecurity(\sprintf(self::COUNTED_TICKET_INSERT, self::BETA_BAKERY, self::TICKET, self::SHOP_PROJECT).' ON CONFLICT (organization_id, ticket_id) DO UPDATE SET is_done = false', 'ins_project_count_ticket');
        $this->assertRefusedByRowLevelSecurity(\sprintf("UPDATE ins_project_count_ticket SET organization_id = '%s' WHERE organization_id = '%s'", self::THIRD_ORG, self::ACME_FOODS), 'ins_project_count_ticket');
        self::assertSame(0, $this->asAcmeFoods(\sprintf("UPDATE ins_project_count_ticket SET is_done = false WHERE organization_id = '%s'", self::BETA_BAKERY)));
        self::assertSame(0, $this->asAcmeFoods(\sprintf("DELETE FROM ins_project_count_ticket WHERE organization_id = '%s'", self::BETA_BAKERY)));

        self::assertSame([[self::BETA_BAKERY, self::TICKET, true]], $betaCountedTicketsBefore);
        self::assertSame($betaCountedTicketsBefore, $betaCountedTickets());
    }

    protected function tearDown(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY, self::THIRD_ORG);
        parent::tearDown();
    }

    #[Test]
    public function theCasesCoverEveryInsightTableInTheSchema(): void
    {
        self::assertEqualsCanonicalizing(
            self::TABLES,
            $this->connection()->fetchFirstColumn("SELECT relname FROM pg_class WHERE relnamespace = 'public'::regnamespace AND relkind IN ('r', 'p') AND relname LIKE 'ins\\_%'"),
        );
    }

    #[Test]
    public function sqlSeesOnlyTheTenantsRowsAndNoneWithoutATenant(): void
    {
        $visibleOrganizations = fn (string $organizationId): array => $this->transactions()->rolledBackAs(
            $organizationId,
            fn (): array => $this->connection()->fetchFirstColumn('SELECT DISTINCT organization_id FROM ins_ticket_turn'),
        );

        self::assertSame([self::ACME_FOODS], $visibleOrganizations(self::ACME_FOODS));
        self::assertSame([self::BETA_BAKERY], $visibleOrganizations(self::BETA_BAKERY));
        self::assertSame(0, $this->connection()->fetchOne('SELECT count(*) FROM ins_ticket_turn'));
    }

    #[Test]
    public function rowsOfAnotherOrganizationCannotBeAddedChangedRemovedMovedOrUpserted(): void
    {
        $betaRows = fn (): mixed => $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): mixed => $this->connection()->fetchAllAssociative('SELECT ticket_id, turn_user_id, base_score FROM ins_ticket_turn'));
        $betaRowsBefore = $betaRows();

        $this->assertRefusedByRowLevelSecurity($this->insertOf(self::THIRD_ORG));
        // The upsert DbalTurnProjector runs, aimed at Beta Bakery's row: the conflicting row is invisible, so it is an insert.
        $this->assertRefusedByRowLevelSecurity($this->insertOf(self::BETA_BAKERY).' ON CONFLICT (organization_id, ticket_id) DO UPDATE SET base_score = EXCLUDED.base_score');
        $this->assertRefusedByRowLevelSecurity(\sprintf("UPDATE ins_ticket_turn SET organization_id = '%s' WHERE organization_id = '%s'", self::THIRD_ORG, self::ACME_FOODS));
        self::assertSame(0, $this->asAcmeFoods(\sprintf("UPDATE ins_ticket_turn SET base_score = 99 WHERE organization_id = '%s'", self::BETA_BAKERY)));
        self::assertSame(0, $this->asAcmeFoods(\sprintf("DELETE FROM ins_ticket_turn WHERE organization_id = '%s'", self::BETA_BAKERY)));

        self::assertNotSame([], $betaRowsBefore);
        self::assertSame($betaRowsBefore, $betaRows());
    }

    private function insertOf(string $organizationId): string
    {
        return \sprintf(self::INSERT, $organizationId, self::TICKET, self::SHOP_PROJECT, self::LENA, self::LENA);
    }

    private function assertRefusedByRowLevelSecurity(string $sql, string $table = 'ins_ticket_turn'): void
    {
        try {
            $this->asAcmeFoods($sql);
        } catch (DriverException $exception) {
            self::assertSame('42501', $exception->getSQLState());
            self::assertStringContainsString(\sprintf('new row violates row-level security policy for table "%s"', $table), $exception->getMessage());

            return;
        }

        self::fail(\sprintf('Row-level security did not refuse: %s', $sql));
    }

    private function asAcmeFoods(string $sql): int
    {
        return (int) $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement($sql));
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
