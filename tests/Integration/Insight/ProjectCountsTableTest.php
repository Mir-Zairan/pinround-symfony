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
 * The ins_project_counts table as Version20261112140000 creates it: who may do what, its key, and row-level security,
 * which alone keeps another organization's counts out of reach.
 */
final class ProjectCountsTableTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000007501';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000007502';
    private const string ACME_PROJECT = '0197a3c0-0000-7000-8000-000000007521';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-000000007522';

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        foreach ([[self::ACME_FOODS, self::ACME_PROJECT, 3], [self::BETA_BAKERY, self::BETA_PROJECT, 9]] as [$organizationId, $projectId, $openCount]) {
            $this->ticketRows()->addProjectOf($organizationId, $projectId);
            $this->transactions()->committedAs($organizationId, fn (): int|string => $this->connection()->executeStatement(
                "INSERT INTO ins_project_counts (organization_id, project_id, visibility, open_count, done_count) VALUES (?, ?, 'all', ?, 0)",
                [$organizationId, $projectId, $openCount],
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
     * The app also deletes, as the recount replaces an organization's rows; support reads under the tenant policy.
     */
    #[Test]
    public function theAppMayReadAddChangeAndRemoveRowsAndSupportOnlyRead(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(
            ['pinround_app DELETE', 'pinround_app INSERT', 'pinround_app SELECT', 'pinround_app UPDATE', 'pinround_readonly SELECT'],
            $connection->fetchFirstColumn(
                "SELECT acl.grantee::regrole::text || ' ' || acl.privilege_type
                 FROM pg_class c, aclexplode(c.relacl) acl
                 WHERE c.oid = 'public.ins_project_counts'::regclass AND acl.grantee <> c.relowner",
            ),
        );
        self::assertSame('pinround_migrator', $connection->fetchOne("SELECT relowner::regrole::text FROM pg_class WHERE oid = 'public.ins_project_counts'::regclass"));
        self::assertSame([], $connection->fetchFirstColumn(
            "SELECT a.attname FROM pg_attribute a, aclexplode(a.attacl) acl WHERE a.attrelid = 'public.ins_project_counts'::regclass",
        ));
    }

    #[Test]
    public function theOnlyIndexIsTheKeyStartingWithTheOrganization(): void
    {
        self::assertSame(
            ['CREATE UNIQUE INDEX ins_project_counts_pkey ON public.ins_project_counts USING btree (organization_id, project_id, visibility)'],
            $this->connection()->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'ins_project_counts'"),
        );
    }

    #[Test]
    public function rowLevelSecurityIsOnAndForced(): void
    {
        self::assertSame(
            ['relrowsecurity' => true, 'relforcerowsecurity' => true],
            $this->connection()->fetchAssociative("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = 'public.ins_project_counts'::regclass"),
        );
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
