<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\Ticketing\Infrastructure\Doctrine\TicketEventPartitions;
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
 * Version20261111150000, which adds the tkt_ticket_event partitions from 2026-11 through 2027-12 that are missing, so
 * prod, where the partition job does not run, takes events until then.
 */
final class TicketEventPartitionsMigrationTest extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\Version20261111150000';

    #[Test]
    public function theMigratedDatabaseHasAPartitionForEveryMonthFrom202611Through202712(): void
    {
        self::assertSame(
            [
                'tkt_ticket_event_202611', 'tkt_ticket_event_202612', 'tkt_ticket_event_202701', 'tkt_ticket_event_202702',
                'tkt_ticket_event_202703', 'tkt_ticket_event_202704', 'tkt_ticket_event_202705', 'tkt_ticket_event_202706',
                'tkt_ticket_event_202707', 'tkt_ticket_event_202708', 'tkt_ticket_event_202709', 'tkt_ticket_event_202710',
                'tkt_ticket_event_202711', 'tkt_ticket_event_202712',
            ],
            $this->migrator()->fetchFirstColumn(
                "SELECT c.relname FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid
                 WHERE i.inhparent = 'public.tkt_ticket_event'::regclass AND c.relname BETWEEN 'tkt_ticket_event_202611' AND 'tkt_ticket_event_202712'
                 ORDER BY c.relname",
            ),
        );
    }

    #[Test]
    public function upAddsOnlyTheMonthsThatAreMissing(): void
    {
        $migrator = $this->migrator();
        $migrator->beginTransaction();
        try {
            $plannedWithEveryMonth = $this->plannedUpSql($migrator);
            $migrator->executeStatement('DROP TABLE tkt_ticket_event_202708');
            $plannedWithoutAugust = $this->plannedUpSql($migrator);
        } finally {
            $migrator->rollBack();
        }

        self::assertSame([], $plannedWithEveryMonth);
        self::assertSame(TicketEventPartitions::statementsFor(new \DateTimeImmutable('2027-08-01 UTC')), $plannedWithoutAugust);
    }

    /**
     * Loads the migration as doctrine:migrations:migrate does and plans up on a fresh instance, as a migration keeps
     * every statement it has planned.
     *
     * @return list<string>
     */
    private function plannedUpSql(Connection $migrator): array
    {
        $configuration = new Configuration();
        $configuration->setMetadataStorageConfiguration(new TableMetadataStorageConfiguration());
        $configuration->addMigrationsDirectory('DoctrineMigrations', self::getContainer()->getParameter('kernel.project_dir').'/migrations');
        $migrations = DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($migrator));
        $migrationClass = $migrations->getMigrationRepository()->getMigration(new Version(self::MIGRATION))->getMigration()::class;
        /** @var AbstractMigration $migration */
        $migration = new $migrationClass($migrator, new NullLogger());
        $migration->up($migrator->createSchemaManager()->introspectSchema());

        return array_values(array_map(static fn ($query): string => $query->getStatement(), $migration->getSql()));
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }
}
