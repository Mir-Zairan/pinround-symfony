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
 * The ins_project_counts and ins_project_count_ticket tables as Version20261112140000 creates them: who may do what,
 * their keys, and row-level security, which alone keeps another organization's counts out of reach.
 */
final class ProjectCountsTableTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000007501';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000007502';
    private const string ACME_PROJECT = '0197a3c0-0000-7000-8000-000000007521';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-000000007522';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000007511';
    private const array TABLES = ['ins_project_counts', 'ins_project_count_ticket'];

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        foreach ([[self::ACME_FOODS, self::ACME_PROJECT, 3], [self::BETA_BAKERY, self::BETA_PROJECT, 9]] as [$organizationId, $projectId, $openCount]) {
            $this->ticketRows()->addProjectOf($organizationId, $projectId);
            $this->transactions()->committedAs($organizationId, fn (): int|string => $this->connection()->executeStatement(
                "INSERT INTO ins_project_counts (organization_id, project_id, team_id, visibility, open_count, done_count) VALUES (?, ?, ?, 'all', ?, 0)",
                [$organizationId, $projectId, self::SHOP_TEAM, $openCount],
            ));
        }
    }

    protected function tearDown(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        self::getContainer()->get(TenantContext::class)->reset();
        parent::tearDown();
    }

    /**
     * A count row is never removed by the app, only left at zero; a counted ticket is, when it loses its team. Support
     * reads under the tenant policy.
     */
    #[Test]
    public function theAppMayRemoveOnlyCountedTicketsAndSupportOnlyReads(): void
    {
        $connection = $this->connection();
        $grantsOf = static fn (string $table): array => $connection->fetchFirstColumn(
            "SELECT acl.grantee::regrole::text || ' ' || acl.privilege_type
             FROM pg_class c, aclexplode(c.relacl) acl
             WHERE c.oid = ?::regclass AND acl.grantee <> c.relowner",
            ['public.'.$table],
        );

        self::assertEqualsCanonicalizing(['pinround_app INSERT', 'pinround_app SELECT', 'pinround_app UPDATE', 'pinround_readonly SELECT'], $grantsOf('ins_project_counts'));
        self::assertEqualsCanonicalizing(['pinround_app DELETE', 'pinround_app INSERT', 'pinround_app SELECT', 'pinround_app UPDATE', 'pinround_readonly SELECT'], $grantsOf('ins_project_count_ticket'));
        foreach (self::TABLES as $table) {
            self::assertSame('pinround_migrator', $connection->fetchOne('SELECT relowner::regrole::text FROM pg_class WHERE oid = ?::regclass', ['public.'.$table]));
            self::assertSame([], $connection->fetchFirstColumn('SELECT a.attname FROM pg_attribute a, aclexplode(a.attacl) acl WHERE a.attrelid = ?::regclass', ['public.'.$table]));
        }
    }

    #[Test]
    public function theOnlyIndexesAreTheKeysStartingWithTheOrganization(): void
    {
        self::assertSame(
            [
                'CREATE UNIQUE INDEX ins_project_count_ticket_pkey ON public.ins_project_count_ticket USING btree (organization_id, ticket_id)',
                'CREATE UNIQUE INDEX ins_project_counts_pkey ON public.ins_project_counts USING btree (organization_id, project_id, team_id, visibility)',
            ],
            $this->connection()->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename IN ('ins_project_counts', 'ins_project_count_ticket') ORDER BY indexname"),
        );
    }

    #[Test]
    public function rowLevelSecurityIsOnAndForced(): void
    {
        foreach (self::TABLES as $table) {
            self::assertSame(
                ['relrowsecurity' => true, 'relforcerowsecurity' => true],
                $this->connection()->fetchAssociative('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = ?::regclass', ['public.'.$table]),
                $table,
            );
        }
    }

    /**
     * No WHERE on the organization: the policy alone keeps Beta Bakery's row out of Acme Foods' reads and writes, and
     * no tenant sees nothing.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsCounts(): void
    {
        $readAll = fn (): array => $this->connection()->fetchAllNumeric('SELECT project_id, open_count FROM ins_project_counts ORDER BY project_id');

        self::assertSame([[self::ACME_PROJECT, 3]], $this->transactions()->rolledBackAs(self::ACME_FOODS, $readAll));
        self::assertSame(1, $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement('UPDATE ins_project_counts SET open_count = open_count + 1')));
        self::assertSame([[self::BETA_PROJECT, 9]], $this->transactions()->rolledBackAs(self::BETA_BAKERY, $readAll));
        self::assertSame([], $this->connection()->fetchAllNumeric('SELECT project_id FROM ins_project_counts'));
    }

    #[Test]
    public function theDatabaseRefusesANegativeCountAndAnUnknownVisibility(): void
    {
        foreach (['open_count = -1' => 'ins_project_counts_counts_check', "visibility = 'private'" => 'ins_project_counts_visibility_check'] as $change => $violatedCheck) {
            try {
                $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement('UPDATE ins_project_counts SET '.$change));
                self::fail(\sprintf('The database accepted %s.', $change));
            } catch (DriverException $refusal) {
                self::assertSame('23514', $refusal->getSQLState());
                self::assertStringContainsString($violatedCheck, $refusal->getMessage());
            }
        }
        self::assertSame([[self::ACME_PROJECT, 3]], $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllNumeric('SELECT project_id, open_count FROM ins_project_counts')));
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager')->getConnection();
    }
}
