<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\LocalAi;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Version20261118140000, which labels every posted laptop result, run as pinround_migrator (as
 * doctrine:migrations:migrate does: with no tenant set) inside a transaction that is rolled back. Its down() refuses, so
 * the test takes the columns away with the statements down() ran before, then runs up(). Acme Foods and Beta Bakery
 * each have used and unused packages, committed first under their own tenant; their labels are then read by the owner
 * with row-level security lifted, so a backfill that row-level security hid from the owner leaves them unlabelled.
 */
final class ResultLabelBackfillTest extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\Version20261118140000';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000fb101';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000fb102';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000fb111';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000fb121';
    private const string ACME_QWEN = '0197a3c0-0000-7000-8000-0000000fb131';
    private const string ACME_LLAMA = '0197a3c0-0000-7000-8000-0000000fb132';
    private const string ACME_UNUSED = '0197a3c0-0000-7000-8000-0000000fb133';
    private const string BETA_GEMMA = '0197a3c0-0000-7000-8000-0000000fb134';
    private const string BETA_UNUSED = '0197a3c0-0000-7000-8000-0000000fb135';

    /** What down() ran before it refused: the state up() starts from. */
    private const array STATEMENTS_BEFORE_THE_MIGRATION = [
        'DROP INDEX ast_local_package_ticket_id_used_at_idx',
        'ALTER TABLE ast_local_package DROP COLUMN made_main_at, DROP COLUMN result_label',
        'ALTER TABLE tkt_summary DROP CONSTRAINT tkt_summary_laptop_label_check',
        'ALTER TABLE tkt_summary DROP COLUMN chosen_by_id',
        'ALTER TABLE tkt_summary DROP CONSTRAINT tkt_summary_source_check',
        "ALTER TABLE tkt_summary ADD CONSTRAINT tkt_summary_source_check CHECK (source IN ('sync', 'ai', 'explanation', 'human'))",
    ];

    protected function setUp(): void
    {
        $this->removePackages();
        $this->committedAs(self::ACME_FOODS, function (Connection $migrator): void {
            $this->insertPackage($migrator, self::ACME_FOODS, self::ACME_QWEN, 'qwen3:4b');
            $this->insertPackage($migrator, self::ACME_FOODS, self::ACME_LLAMA, 'llama3.2:3b');
            $this->insertPackage($migrator, self::ACME_FOODS, self::ACME_UNUSED, null);
        });
        $this->committedAs(self::BETA_BAKERY, function (Connection $migrator): void {
            $this->insertPackage($migrator, self::BETA_BAKERY, self::BETA_UNUSED, null);
            $this->insertPackage($migrator, self::BETA_BAKERY, self::BETA_GEMMA, 'gemma3:1b');
        });
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removePackages();
        parent::tearDown();
    }

    #[Test]
    public function everyPostedResultOfEachOrganizationIsLabelledWithItsModelAndAnUnusedPackageKeepsNone(): void
    {
        $labels = $this->rolledBackWithNoTenant(function (Connection $migrator): array {
            $this->undoTheMigration($migrator);
            $this->runUp($migrator);

            // Read as the owner with row-level security lifted, in the transaction the rollback undoes.
            $migrator->executeStatement('ALTER TABLE ast_local_package NO FORCE ROW LEVEL SECURITY');
            $labels = [];
            foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
                /** @var array<string, string|null> $labelsOfTheOrganization */
                $labelsOfTheOrganization = $migrator->fetchAllKeyValue('SELECT id, result_label FROM ast_local_package WHERE organization_id = ? ORDER BY id', [$organizationId]);
                $labels[$organizationId] = $labelsOfTheOrganization;
            }

            return $labels;
        });

        self::assertSame([
            self::ACME_FOODS => [
                self::ACME_QWEN => 'posted by someone using local model qwen3:4b (unverified)',
                self::ACME_LLAMA => 'posted by someone using local model llama3.2:3b (unverified)',
                self::ACME_UNUSED => null,
            ],
            self::BETA_BAKERY => [
                self::BETA_GEMMA => 'posted by someone using local model gemma3:1b (unverified)',
                self::BETA_UNUSED => null,
            ],
        ], $labels);
    }

    #[Test]
    public function theMigrationLeavesRowLevelSecurityForcedOnTheLaptopPackageTable(): void
    {
        $isForced = $this->rolledBackWithNoTenant(function (Connection $migrator): mixed {
            $this->undoTheMigration($migrator);
            $this->runUp($migrator);

            return $migrator->fetchOne("SELECT relrowsecurity AND relforcerowsecurity FROM pg_class WHERE oid = 'public.ast_local_package'::regclass");
        });

        self::assertTrue($isForced);
    }

    #[Test]
    public function downRefusesAndPlansNothing(): void
    {
        $migration = $this->migration($this->migrator());

        try {
            $migration->down($this->migrator()->createSchemaManager()->introspectSchema());
            self::fail('down() dropped the laptop summary columns.');
        } catch (IrreversibleMigration $refusal) {
            self::assertSame('Laptop summaries and their chooser cannot be kept without these columns; restore a backup instead.', $refusal->getMessage());
        }
        self::assertSame([], $migration->getSql());
    }

    private function undoTheMigration(Connection $migrator): void
    {
        foreach (self::STATEMENTS_BEFORE_THE_MIGRATION as $statement) {
            $migrator->executeStatement($statement);
        }
    }

    private function runUp(Connection $migrator): void
    {
        $migration = $this->migration($migrator);
        $migration->up($migrator->createSchemaManager()->introspectSchema());
        foreach ($migration->getSql() as $query) {
            $migrator->executeStatement($query->getStatement());
        }
    }

    /**
     * Loads the migration as doctrine:migrations:migrate does, on a fresh instance, as a migration keeps every
     * statement it has planned.
     */
    private function migration(Connection $migrator): AbstractMigration
    {
        $configuration = new Configuration();
        $configuration->setMetadataStorageConfiguration(new TableMetadataStorageConfiguration());
        $configuration->addMigrationsDirectory('DoctrineMigrations', self::getContainer()->getParameter('kernel.project_dir').'/migrations');
        $migrations = DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($migrator));
        $migrationClass = $migrations->getMigrationRepository()->getMigration(new Version(self::MIGRATION))->getMigration()::class;
        /** @var AbstractMigration $migration */
        $migration = new $migrationClass($migrator, new NullLogger());

        return $migration;
    }

    /**
     * @param ?string $model null for a package nobody posted a result to
     */
    private function insertPackage(Connection $migrator, string $organizationId, string $packageId, ?string $model): void
    {
        $migrator->executeStatement(
            "INSERT INTO ast_local_package (organization_id, id, user_id, ticket_id, purpose, prompt_version, created_at, expires_at, used_at, result_text, model, result_label)
             VALUES (?, ?, ?, ?, 'summary', 'summary.v1', '2027-03-15 10:00:00+00', '2027-03-15 10:05:00+00', ?, ?, ?, ?)",
            null === $model
                ? [$organizationId, $packageId, self::OMAR, self::TICKET, null, null, null, null]
                : [$organizationId, $packageId, self::OMAR, self::TICKET, '2027-03-15 10:01:00+00', 'Pay does nothing on the last step.', $model, 'posted by Omar using local model '.$model.' (unverified)'],
        );
    }

    /**
     * @template T
     *
     * @param \Closure(Connection): T $work
     *
     * @return T
     */
    private function rolledBackWithNoTenant(\Closure $work): mixed
    {
        self::getContainer()->get(TenantContext::class)->clear();
        $migrator = $this->migrator();
        $migrator->beginTransaction();
        try {
            return $work($migrator);
        } finally {
            $migrator->rollBack();
        }
    }

    /**
     * @param \Closure(Connection): void $work
     */
    private function committedAs(string $organizationId, \Closure $work): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId($organizationId));
        try {
            $this->migrator()->transactional($work);
        } finally {
            $tenantContext->clear();
        }
    }

    private function removePackages(): void
    {
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $this->committedAs($organizationId, static function (Connection $migrator): void {
                $migrator->executeStatement('DELETE FROM ast_local_package');
            });
        }
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }
}
