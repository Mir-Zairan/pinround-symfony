<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves row-level security on every web_ table (ADR-0005) as pinround_app: with Acme Foods as tenant, Beta Bakery's
 * rows are invisible and cannot be written, moved into or out of; without a tenant no row is visible.
 */
final class WebTablesIsolationTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000009a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000009b1';
    // An organization with no rows: the target of smuggled inserts and moved rows.
    private const string THIRD_ORG = '0197a3c0-0000-7000-8000-0000000009c1';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000009d1';
    private const array TABLES = ['web_home_focus'];

    protected function setUp(): void
    {
        $this->removeRowsOf(self::ACME_FOODS, self::BETA_BAKERY, self::THIRD_ORG);
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $this->transactions()->committedAs($organizationId, fn (): int|string => $this->connection()->executeStatement(
                "INSERT INTO web_home_focus (organization_id, user_id, focus, updated_at) VALUES (?, ?, 'my_team', '2027-03-15 10:00:00+00')",
                [$organizationId, self::LENA],
            ));
        }
    }

    protected function tearDown(): void
    {
        $this->removeRowsOf(self::ACME_FOODS, self::BETA_BAKERY, self::THIRD_ORG);
        parent::tearDown();
    }

    #[Test]
    public function theCasesCoverEveryWebTableInTheSchema(): void
    {
        self::assertEqualsCanonicalizing(
            self::TABLES,
            $this->connection()->fetchFirstColumn("SELECT relname FROM pg_class WHERE relnamespace = 'public'::regnamespace AND relkind IN ('r', 'p') AND relname LIKE 'web\\_%'"),
        );
    }

    #[Test]
    public function sqlSeesOnlyTheTenantsRowsAndNoneWithoutATenant(): void
    {
        $visibleOrganizations = fn (string $organizationId): array => $this->transactions()->rolledBackAs(
            $organizationId,
            fn (): array => $this->connection()->fetchFirstColumn('SELECT DISTINCT organization_id FROM web_home_focus'),
        );

        self::assertSame([self::ACME_FOODS], $visibleOrganizations(self::ACME_FOODS));
        self::assertSame([self::BETA_BAKERY], $visibleOrganizations(self::BETA_BAKERY));
        self::assertSame(0, $this->connection()->fetchOne('SELECT count(*) FROM web_home_focus'));
    }

    #[Test]
    public function rowsOfAnotherOrganizationCannotBeAddedChangedMovedOrUpserted(): void
    {
        $betaRows = fn (): mixed => $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): mixed => $this->connection()->fetchAllAssociative('SELECT user_id, focus, updated_at FROM web_home_focus'));
        $betaRowsBefore = $betaRows();

        $this->assertRefusedByRowLevelSecurity(\sprintf(
            "INSERT INTO web_home_focus (organization_id, user_id, focus, updated_at) VALUES ('%s', '%s', 'admin', now())",
            self::THIRD_ORG,
            self::LENA,
        ));
        // The upsert the app runs, aimed at Beta Bakery's row: the conflicting row is invisible, so it is an insert.
        $this->assertRefusedByRowLevelSecurity(\sprintf(
            "INSERT INTO web_home_focus (organization_id, user_id, focus, updated_at) VALUES ('%s', '%s', 'admin', now())"
            .' ON CONFLICT (organization_id, user_id) DO UPDATE SET focus = EXCLUDED.focus',
            self::BETA_BAKERY,
            self::LENA,
        ));
        $this->assertRefusedByRowLevelSecurity(\sprintf("UPDATE web_home_focus SET organization_id = '%s' WHERE organization_id = '%s'", self::THIRD_ORG, self::ACME_FOODS));
        self::assertSame(0, $this->asAcmeFoods(\sprintf("UPDATE web_home_focus SET focus = 'admin' WHERE organization_id = '%s'", self::BETA_BAKERY)));

        self::assertNotSame([], $betaRowsBefore);
        self::assertSame($betaRowsBefore, $betaRows());
    }

    private function assertRefusedByRowLevelSecurity(string $sql): void
    {
        try {
            $this->asAcmeFoods($sql);
        } catch (DriverException $exception) {
            self::assertSame('42501', $exception->getSQLState());
            self::assertStringContainsString('new row violates row-level security policy for table "web_home_focus"', $exception->getMessage());

            return;
        }

        self::fail(\sprintf('Row-level security did not refuse: %s', $sql));
    }

    private function asAcmeFoods(string $sql): int
    {
        return (int) $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement($sql));
    }

    /**
     * Through the migrator, which owns the table and so may delete; FORCE ROW LEVEL SECURITY binds it too.
     */
    private function removeRowsOf(string ...$organizationIds): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        foreach ($organizationIds as $organizationId) {
            $tenantContext->set(new OrganizationId($organizationId));
            try {
                self::getContainer()->get('doctrine.dbal.migrator_connection')->transactional(
                    static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM web_home_focus WHERE organization_id = ?', [$organizationId]),
                );
            } finally {
                $tenantContext->clear();
            }
        }
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }
}
