<?php

declare(strict_types=1);

namespace App\Tests\Integration\Audit\Infrastructure\Health;

use App\Audit\Infrastructure\Health\NextAuditPartitionExists;
use App\SharedKernel\Infrastructure\Health\ReadinessCheckFailed;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * On the migrated test database as pinround_app, whose partitions run from 2026-11 to 2027-12 (Version20261107170000).
 */
final class NextAuditPartitionExistsTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function nowsWhoseNextMonthHasAPartition(): iterable
    {
        yield 'the first month' => ['2026-11-15 10:00:00 UTC'];
        yield 'the month before the last' => ['2027-11-30 23:59:59 UTC'];
        // 23:00 UTC, still November.
        yield 'a local time west of UTC, still the same month in UTC' => ['2027-11-30 18:00:00 America/New_York'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nowsWhoseNextMonthHasNoPartition(): iterable
    {
        yield 'the last month' => ['2027-12-15 10:00:00 UTC'];
        // 2027-11-30 in New York is already 2027-12-01 in UTC, whose next month (2028-01) has no partition.
        yield 'a local time whose UTC month is already the last one' => ['2027-11-30 20:00:00 America/New_York'];
        yield 'before the first month' => ['2026-09-15 10:00:00 UTC'];
    }

    #[Test]
    #[DataProvider('nowsWhoseNextMonthHasAPartition')]
    public function itPassesWhenNextMonthsPartitionExists(string $now): void
    {
        $check = new NextAuditPartitionExists($this->connection(), new MockClock($now));

        $check->verify();

        self::assertSame('next_audit_partition', $check->name());
    }

    #[Test]
    #[DataProvider('nowsWhoseNextMonthHasNoPartition')]
    public function itThrowsWhenNextMonthsPartitionIsMissing(string $now): void
    {
        $this->expectException(ReadinessCheckFailed::class);

        new NextAuditPartitionExists($this->connection(), new MockClock($now))->verify();
    }

    #[Test]
    public function aTableNamedLikeAPartitionButNotAttachedToAudEventDoesNotCount(): void
    {
        // Never committed: the check reads the catalog on the same connection, inside the transaction.
        $migrator = $this->migratorConnection();
        $migrator->beginTransaction();

        try {
            $migrator->executeStatement('CREATE TABLE aud_event_202801 (id INT)');
            $this->expectException(ReadinessCheckFailed::class);

            new NextAuditPartitionExists($migrator, new MockClock('2027-12-15 10:00:00 UTC'))->verify();
        } finally {
            $migrator->rollBack();
        }
    }

    #[Test]
    public function aPartitionAddedForNextMonthMakesTheCheckPass(): void
    {
        // The counterpart of the test above, also never committed: the same name as a real partition of aud_event.
        $migrator = $this->migratorConnection();
        $migrator->beginTransaction();

        try {
            $migrator->executeStatement("CREATE TABLE aud_event_202801 PARTITION OF aud_event FOR VALUES FROM ('2028-01-01+00') TO ('2028-02-01+00')");

            new NextAuditPartitionExists($migrator, new MockClock('2027-12-15 10:00:00 UTC'))->verify();
        } finally {
            $migrator->rollBack();
        }

        self::assertNull($migrator->fetchOne("SELECT to_regclass('public.aud_event_202801')"), 'The partition was committed.');
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function migratorConnection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }
}
