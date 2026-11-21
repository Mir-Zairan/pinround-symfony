<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The migrations of screenshot tries (ADR-0048) never scan rvw_screenshot or rvw_pin, nor build an index on them, under
 * a transaction's ACCESS EXCLUSIVE lock (data-model.md, migration rules), and what they leave behind is valid. The
 * migrations are planned, not run; RoundTablesTest pins the constraints and indexes they make.
 */
final class ScreenshotTriesMigrationsTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function migrationsThatAddConstraints(): iterable
    {
        yield 'rvw_screenshot.retake_of' => ['DoctrineMigrations\Version20261121100000'];
        yield 'rvw_pin.fixed_on_try' => ['DoctrineMigrations\Version20261121120000'];
        yield 'rvw_pin_fixed_on_try_check refusing a pin with no verdict' => ['DoctrineMigrations\Version20261121170000'];
    }

    #[Test]
    #[DataProvider('migrationsThatAddConstraints')]
    public function aMigrationInATransactionAddsItsConstraintsNotValidAndBuildsNoIndex(string $migrationVersion): void
    {
        $migration = $this->migration($migrationVersion);
        $migration->up(new Schema());
        $statements = self::statementsOf($migration);
        $constraintsAdded = array_values(array_filter($statements, static fn (string $statement): bool => str_contains($statement, 'ADD CONSTRAINT')));

        self::assertTrue($migration->isTransactional());
        self::assertNotSame([], $constraintsAdded);
        self::assertSame([], array_values(array_filter($constraintsAdded, static fn (string $statement): bool => !str_ends_with($statement, ' NOT VALID'))));
        self::assertSame([], array_values(array_filter($statements, static fn (string $statement): bool => 1 === preg_match('/\bCREATE\s+(UNIQUE\s+)?INDEX\b/i', $statement))));
    }

    #[Test]
    public function theRetakeIndexIsBuiltConcurrentlyOutsideATransactionLeadingWithTheOrganization(): void
    {
        $migration = $this->migration('DoctrineMigrations\Version20261121180000');
        $migration->up(new Schema());

        self::assertFalse($migration->isTransactional());
        self::assertSame([
            'DROP INDEX CONCURRENTLY IF EXISTS rvw_screenshot_retake_of_idx',
            'CREATE INDEX CONCURRENTLY rvw_screenshot_retake_of_idx ON rvw_screenshot (organization_id, round_id, retake_of)',
        ], self::statementsOf($migration));
    }

    /**
     * A constraint left NOT VALID would never hold the rows written before it, and an INVALID index of a failed
     * concurrent build serves no query.
     */
    #[Test]
    public function everyConstraintAndIndexOfTheRoundTablesIsValid(): void
    {
        $roundTables = "('public.rvw_round'::regclass, 'public.rvw_screenshot'::regclass, 'public.rvw_pin'::regclass, 'public.rvw_check_item'::regclass)";

        self::assertSame(
            [[], []],
            [
                $this->migrator()->fetchFirstColumn('SELECT conname FROM pg_constraint WHERE NOT convalidated AND conrelid IN '.$roundTables),
                $this->migrator()->fetchFirstColumn('SELECT indexrelid::regclass::text FROM pg_index WHERE NOT indisvalid AND indrelid IN '.$roundTables),
            ],
        );
        self::assertContains(
            'rvw_screenshot_retake_of_fkey',
            $this->migrator()->fetchFirstColumn('SELECT conname FROM pg_constraint WHERE convalidated AND conrelid IN '.$roundTables),
        );
    }

    /**
     * Loads the migration as doctrine:migrations:migrate does, on a fresh instance, as a migration keeps every
     * statement it has planned.
     */
    private function migration(string $migrationVersion): AbstractMigration
    {
        $configuration = new Configuration();
        $configuration->setMetadataStorageConfiguration(new TableMetadataStorageConfiguration());
        $configuration->addMigrationsDirectory('DoctrineMigrations', self::getContainer()->getParameter('kernel.project_dir').'/migrations');
        $migrations = DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($this->migrator()));
        $migrationClass = $migrations->getMigrationRepository()->getMigration(new Version($migrationVersion))->getMigration()::class;

        return new $migrationClass($this->migrator(), new NullLogger());
    }

    /**
     * @return list<string>
     */
    private static function statementsOf(AbstractMigration $migration): array
    {
        return array_values(array_map(static fn ($query): string => $query->getStatement(), $migration->getSql()));
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }
}
