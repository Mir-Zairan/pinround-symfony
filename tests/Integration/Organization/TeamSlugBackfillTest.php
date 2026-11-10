<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Domain\TeamSlug;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
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
 * Version20261110180000, which gives every team a slug, run as pinround_migrator (as doctrine:migrations:migrate does)
 * inside a transaction that is rolled back: its down() first, so the teams have no slug, then its up(). Its SQL copies
 * TeamSlug::fromName's rule, so the slugs are compared with TeamSlug here. The tenant is set only when a transaction
 * begins, so the test with two organizations commits both teams first, each under its own tenant.
 */
final class TeamSlugBackfillTest extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\Version20261110180000';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000001ca1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000001cb1';
    private const string LENA = '0197a3c0-0000-7000-8000-000000001c52';

    private int $teamCount = 0;

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(TenantContext::class)->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    /** The later of two teams with the same slug gets "-2", also when its id is the lower one. */
    #[Test]
    public function theLaterOfTwoTeamsWithTheSameSlugGetsTheFirstFreeSuffix(): void
    {
        $slugs = $this->rolledBackAs(self::ACME_FOODS, function (Connection $migrator): array {
            $this->runMigration($migrator, 'down');
            $this->insertTeam($migrator, self::ACME_FOODS, 'Shop-Web', '2027-03-15 10:00:00+00');
            $this->insertTeam($migrator, self::ACME_FOODS, 'Shop Web', '2027-03-15 09:00:00+00');
            $this->insertTeam($migrator, self::ACME_FOODS, 'Shop 2', '2027-03-15 08:00:00+00');
            $this->insertTeam($migrator, self::ACME_FOODS, 'Shop', '2027-03-15 08:30:00+00');
            $this->insertTeam($migrator, self::ACME_FOODS, 'SHOP!', '2027-03-15 08:45:00+00');
            $this->insertTeam($migrator, self::ACME_FOODS, '!!!', '2027-03-15 11:00:00+00');
            $this->runMigration($migrator, 'up');

            return $this->slugsByName($migrator);
        });

        self::assertSame(['!!!' => 'team', 'SHOP!' => 'shop-3', 'Shop' => 'shop', 'Shop 2' => 'shop-2', 'Shop Web' => 'shop-web', 'Shop-Web' => 'shop-web-2'], $slugs);
    }

    /** Beta Bakery's team is the older, so a backfill that numbered across organizations would give Acme Foods "-2". */
    #[Test]
    public function eachOrganizationNumbersOnlyItsOwnTeams(): void
    {
        $this->commitTeam(self::BETA_BAKERY, 'Shop Web', '2027-03-15 08:00:00+00');
        $this->commitTeam(self::ACME_FOODS, 'Shop Web', '2027-03-15 09:00:00+00');

        $slugs = [];
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $slugs[$organizationId] = $this->rolledBackAs($organizationId, function (Connection $migrator): array {
                $this->runMigration($migrator, 'down');
                $this->runMigration($migrator, 'up');

                return $this->slugsByName($migrator);
            });
        }

        self::assertSame([self::ACME_FOODS => ['Shop Web' => 'shop-web'], self::BETA_BAKERY => ['Shop Web' => 'shop-web']], $slugs);
    }

    /** Every letter TeamSlug transliterates, between two ASCII letters, and names that test the cut and the hyphens. */
    #[Test]
    public function theMigrationMakesTheSameSlugAsTeamSlugFromName(): void
    {
        $names = [
            str_repeat('a', 49).' bcd',
            str_repeat('b', 60),
            '  Shop & Web -- Checkout!  ',
            'Shop 東京 Web',
            'Cafe'."\u{301}".' Bar',
        ];
        foreach (array_keys(TeamSlug::TRANSLITERATIONS) as $index => $letter) {
            $names[] = \sprintf('%d x%sy', $index, $letter);
        }

        $slugs = $this->rolledBackAs(self::ACME_FOODS, function (Connection $migrator) use ($names): array {
            $this->runMigration($migrator, 'down');
            foreach ($names as $name) {
                $this->insertTeam($migrator, self::ACME_FOODS, $name, '2027-03-15 09:00:00+00');
            }
            $this->runMigration($migrator, 'up');

            return $this->slugsByName($migrator);
        });

        $expected = [];
        foreach ($names as $name) {
            $expected[$name] = TeamSlug::fromName($name)->toString();
        }
        ksort($expected, \SORT_STRING);
        ksort($slugs, \SORT_STRING);
        self::assertSame($expected, $slugs);
    }

    #[Test]
    public function aSlugATeamHasAlreadyIsKeptAndCountsAsTaken(): void
    {
        $slugs = $this->rolledBackAs(self::ACME_FOODS, function (Connection $migrator): array {
            $this->runMigration($migrator, 'down');
            $this->insertTeam($migrator, self::ACME_FOODS, 'Shop', '2027-03-15 08:00:00+00');
            $this->insertTeam($migrator, self::ACME_FOODS, 'Checkout', '2027-03-15 09:00:00+00');
            $statements = $this->plannedSql($migrator, 'up');
            $addColumn = array_shift($statements);
            self::assertNotNull($addColumn);
            $migrator->executeStatement($addColumn);
            $migrator->executeStatement("UPDATE org_team SET slug = 'shop' WHERE name = 'Checkout'");
            foreach ($statements as $statement) {
                $migrator->executeStatement($statement);
            }

            return $this->slugsByName($migrator);
        });

        self::assertSame(['Checkout' => 'shop', 'Shop' => 'shop-2'], $slugs);
    }

    #[Test]
    public function theMigrationLeavesRowLevelSecurityForcedOnTheTeamTable(): void
    {
        $isForced = $this->rolledBackAs(self::ACME_FOODS, function (Connection $migrator): mixed {
            $this->runMigration($migrator, 'down');
            $this->insertTeam($migrator, self::ACME_FOODS, 'Shop', '2027-03-15 08:00:00+00');
            $this->runMigration($migrator, 'up');

            return $migrator->fetchOne("SELECT relrowsecurity AND relforcerowsecurity FROM pg_class WHERE oid = 'public.org_team'::regclass");
        });

        self::assertTrue($isForced);
    }

    /**
     * @template T
     *
     * @param \Closure(Connection): T $work
     *
     * @return T
     */
    private function rolledBackAs(string $organizationId, \Closure $work): mixed
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $migrator = $this->migrator();
        $tenantContext->set(new OrganizationId($organizationId));
        $migrator->beginTransaction();
        try {
            return $work($migrator);
        } finally {
            $migrator->rollBack();
            $tenantContext->clear();
        }
    }

    private function commitTeam(string $organizationId, string $name, string $createdAt): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId($organizationId));
        try {
            $this->migrator()->transactional(function (Connection $migrator) use ($organizationId, $name, $createdAt): void {
                $this->insertTeam($migrator, $organizationId, $name, $createdAt, TeamSlug::fromName($name)->toString());
            });
        } finally {
            $tenantContext->clear();
        }
    }

    /**
     * @param ?string $slug null while the migration's down() has removed the column
     */
    private function insertTeam(Connection $migrator, string $organizationId, string $name, string $createdAt, ?string $slug = null): void
    {
        ++$this->teamCount;
        $teamId = \sprintf('0197a3c0-0000-7000-8000-%012x', 0x1D00 + $this->teamCount);
        if (null === $slug) {
            $migrator->executeStatement(
                'INSERT INTO org_team (organization_id, id, name, lead_user_id, created_at) VALUES (?, ?, ?, ?, ?)',
                [$organizationId, $teamId, $name, self::LENA, $createdAt],
            );

            return;
        }
        $migrator->executeStatement(
            'INSERT INTO org_team (organization_id, id, name, slug, lead_user_id, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$organizationId, $teamId, $name, $slug, self::LENA, $createdAt],
        );
    }

    /**
     * Team ids rise with each insert; the first test inserts the later-created team first, so an order by id fails it.
     *
     * @return array<string, string> slug by team name, by name
     */
    private function slugsByName(Connection $migrator): array
    {
        /** @var array<string, string> $slugs */
        $slugs = $migrator->fetchAllKeyValue('SELECT name::text, slug FROM org_team ORDER BY name::text COLLATE "C"');

        return $slugs;
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

    private function rows(): OrganizationRows
    {
        return new OrganizationRows($this->migrator(), self::getContainer()->get(TenantContext::class));
    }
}
