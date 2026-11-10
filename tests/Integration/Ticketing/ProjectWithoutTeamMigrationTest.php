<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Version20261111100000, which lets tkt_project.team_id be null for an unlinked project, run as pinround_migrator (as
 * doctrine:migrations:migrate does) inside a transaction that is rolled back.
 */
final class ProjectWithoutTeamMigrationTest extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\Version20261111100000';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000022a1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000022c1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000022d1';
    private const string APP_PROJECT = '0197a3c0-0000-7000-8000-0000000022d2';
    private const string NOT_NULL_VIOLATION = '23502';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(TenantContext::class)->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS);
        parent::tearDown();
    }

    #[Test]
    public function upLetsAProjectHaveNoTeamAndDownRequiresATeamAgain(): void
    {
        [$isNullableAfterDown, $isNullableAfterUp, $unlinkedTeam] = $this->rolledBackAs(function (Connection $migrator): array {
            $this->runMigration($migrator, 'down');
            $isNullableAfterDown = $this->isTeamIdNullable($migrator);
            $this->runMigration($migrator, 'up');
            $isNullableAfterUp = $this->isTeamIdNullable($migrator);
            $this->insertProject($migrator, self::SHOP_PROJECT, null);

            return [$isNullableAfterDown, $isNullableAfterUp, $migrator->fetchOne('SELECT team_id FROM tkt_project WHERE id = ?', [self::SHOP_PROJECT])];
        });

        self::assertSame([false, true, null], [$isNullableAfterDown, $isNullableAfterUp, $unlinkedTeam]);
    }

    #[Test]
    public function downKeepsTheTeamOfEveryLinkedProject(): void
    {
        $teamIds = $this->rolledBackAs(function (Connection $migrator): array {
            $this->insertProject($migrator, self::SHOP_PROJECT, self::SHOP_TEAM);
            $this->runMigration($migrator, 'down');

            return $migrator->fetchFirstColumn('SELECT team_id FROM tkt_project WHERE organization_id = ?', [self::ACME_FOODS]);
        });

        self::assertSame([self::SHOP_TEAM], $teamIds);
    }

    #[Test]
    public function downIsRefusedWhileAProjectHasNoTeamAndTheProjectStaysAsItWas(): void
    {
        [$sqlState, $isNullable, $projects] = $this->rolledBackAs(function (Connection $migrator): array {
            $this->insertProject($migrator, self::SHOP_PROJECT, null);
            $this->insertProject($migrator, self::APP_PROJECT, self::SHOP_TEAM);
            $migrator->createSavepoint('before_down');
            $sqlState = null;
            try {
                $this->runMigration($migrator, 'down');
            } catch (DriverException $refusal) {
                $sqlState = $refusal->getSQLState();
            }
            $migrator->rollbackSavepoint('before_down');

            return [
                $sqlState,
                $this->isTeamIdNullable($migrator),
                $migrator->fetchAllKeyValue('SELECT id, team_id FROM tkt_project WHERE organization_id = ? ORDER BY id', [self::ACME_FOODS]),
            ];
        });

        self::assertSame([self::NOT_NULL_VIOLATION, true], [$sqlState, $isNullable]);
        self::assertSame([self::SHOP_PROJECT => null, self::APP_PROJECT => self::SHOP_TEAM], $projects);
    }

    /**
     * @template T
     *
     * @param \Closure(Connection): T $work
     *
     * @return T
     */
    private function rolledBackAs(\Closure $work): mixed
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $migrator = $this->migrator();
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $migrator->beginTransaction();
        try {
            return $work($migrator);
        } finally {
            $migrator->rollBack();
            $tenantContext->clear();
        }
    }

    private function insertProject(Connection $migrator, string $projectId, ?string $teamId): void
    {
        $key = self::SHOP_PROJECT === $projectId ? 'SHOP' : 'APP';
        $migrator->executeStatement(
            'INSERT INTO tkt_project (organization_id, id, team_id, key, name, next_number) VALUES (?, ?, ?, ?, ?, 1)',
            [self::ACME_FOODS, $projectId, $teamId, $key, $key],
        );
    }

    private function isTeamIdNullable(Connection $migrator): bool
    {
        return 'YES' === $migrator->fetchOne(
            "SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'tkt_project' AND column_name = 'team_id'",
        );
    }

    /**
     * @param 'up'|'down' $direction
     */
    private function runMigration(Connection $migrator, string $direction): void
    {
        foreach ($this->plannedSql($migrator, $direction) as $statement) {
            $migrator->executeStatement($statement);
        }
    }

    /**
     * Loads the migration as doctrine:migrations:migrate does, then plans one direction on a fresh instance, as a
     * migration keeps every statement it has planned.
     *
     * @param 'up'|'down' $direction
     *
     * @return list<string>
     */
    private function plannedSql(Connection $migrator, string $direction): array
    {
        $configuration = new Configuration();
        $configuration->setMetadataStorageConfiguration(new TableMetadataStorageConfiguration());
        $configuration->addMigrationsDirectory('DoctrineMigrations', self::getContainer()->getParameter('kernel.project_dir').'/migrations');
        $migrations = DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($migrator));
        $migrationClass = $migrations->getMigrationRepository()->getMigration(new Version(self::MIGRATION))->getMigration()::class;
        /** @var AbstractMigration $migration */
        $migration = new $migrationClass($migrator, new NullLogger());
        $schema = $migrator->createSchemaManager()->introspectSchema();
        'up' === $direction ? $migration->up($schema) : $migration->down($schema);

        return array_values(array_map(static fn ($query): string => $query->getStatement(), $migration->getSql()));
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function rows(): ProjectRows
    {
        return new ProjectRows($this->migrator(), self::getContainer()->get(TenantContext::class));
    }
}
