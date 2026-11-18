<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\Infrastructure\Scheduler;

use App\Assist\Infrastructure\Doctrine\UsagePartitions;
use App\Assist\Infrastructure\Scheduler\DropExpiredUsagePartitions;
use App\SharedKernel\Infrastructure\Doctrine\Migration\MonthlyPartitions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * On the migrated test database, whose ast_usage partitions run from 2026-11 on. Each test runs the job inside a
 * migrator transaction it rolls back (the job's own becomes a savepoint), so the migrated partitions other tests write
 * to are never really dropped.
 */
final class DropExpiredUsagePartitionsTest extends KernelTestCase
{
    // 13 months before is 2027-01-15 10:00: 2026-12 ended on 2027-01-01, 2027-01 ends on 2027-02-01.
    private const string NOW = '2028-02-15 10:00:00 UTC';

    #[Test]
    public function itDropsTheMonthsThatEndedThirteenMonthsAgoAndASecondRunDropsNothing(): void
    {
        $this->inRolledBackTransaction(function (Connection $migrator): void {
            foreach (UsagePartitions::statementsFor(new \DateTimeImmutable('2026-10-01 UTC')) as $statement) {
                $migrator->executeStatement($statement);
            }
            $job = new DropExpiredUsagePartitions($migrator, new MockClock(self::NOW));

            $firstRun = $job();
            $secondRun = $job();

            self::assertSame(['ast_usage_202610', 'ast_usage_202611', 'ast_usage_202612'], $firstRun);
            self::assertSame([], $secondRun);
            self::assertNull($migrator->fetchOne("SELECT to_regclass('public.ast_usage_202612')"), 'The partition was only detached.');
            self::assertSame(
                ['ast_usage_202701', 'ast_usage_202702', 'ast_usage_202703', 'ast_usage_202704', 'ast_usage_202705'],
                \array_slice(MonthlyPartitions::attachedPartitions($migrator, UsagePartitions::PARENT), 0, 5),
            );
        });

        self::assertNotNull($this->migrator()->fetchOne("SELECT to_regclass('public.ast_usage_202611')"), 'A migrated partition was dropped for real.');
    }

    #[Test]
    public function aMonthIsDroppedOnlyOnceThirteenMonthsHavePassedSinceItsEnd(): void
    {
        // 2026-12 ends at 2027-01-01 00:00, which is 13 months before 2028-02-01 00:00.
        $this->inRolledBackTransaction(static function (Connection $migrator): void {
            self::assertSame(['ast_usage_202611'], new DropExpiredUsagePartitions($migrator, new MockClock('2028-01-31 23:59:59 UTC'))());
        });
        $this->inRolledBackTransaction(static function (Connection $migrator): void {
            self::assertSame(['ast_usage_202611', 'ast_usage_202612'], new DropExpiredUsagePartitions($migrator, new MockClock('2028-02-01 00:00:00 UTC'))());
        });
    }

    #[Test]
    public function aTableNamedLikeAnExpiredPartitionButNotAttachedIsKept(): void
    {
        $this->inRolledBackTransaction(static function (Connection $migrator): void {
            $migrator->executeStatement('CREATE TABLE ast_usage_202001 (id INT)');

            $dropped = new DropExpiredUsagePartitions($migrator, new MockClock(self::NOW))();

            self::assertNotContains('ast_usage_202001', $dropped);
            self::assertSame('ast_usage_202001', $migrator->fetchOne("SELECT to_regclass('public.ast_usage_202001')::text"));
        });
    }

    #[Test]
    public function theContainersJobHasTheRightsToDropPartitionsAndReadsTheAppClock(): void
    {
        // 2028-03-01 00:00 less 13 months is 2027-02-01 00:00, exactly when 2027-01 ends.
        self::getContainer()->set('clock', new MockClock('2028-03-01 00:00:00 UTC'));
        $job = self::getContainer()->get(DropExpiredUsagePartitions::class);

        $this->inRolledBackTransaction(static function () use ($job): void {
            self::assertSame(['ast_usage_202611', 'ast_usage_202612', 'ast_usage_202701'], $job());
        });
    }

    #[Test]
    public function itGivesUpOnATableLockAfterFiveSecondsAndDropsNothing(): void
    {
        $migrator = $this->migrator();
        // Far past the job's own timeout, so only that timeout can end the wait within the bound below.
        $migrator->executeStatement("SET lock_timeout = '60s'");
        $app = self::getContainer()->get('doctrine.dbal.default_connection');
        $app->beginTransaction();
        try {
            $app->executeStatement('LOCK TABLE ast_usage IN ACCESS SHARE MODE');
            $this->inRolledBackTransaction(static function (Connection $migrator) use (&$sqlState, &$waitedSeconds): void {
                $startedAt = hrtime(true);
                try {
                    new DropExpiredUsagePartitions($migrator, new MockClock(self::NOW))();
                    self::fail('The job dropped partitions while a reader held ast_usage.');
                } catch (DriverException $refusal) {
                    $sqlState = $refusal->getSQLState();
                }
                $waitedSeconds = (hrtime(true) - $startedAt) / 1e9;
            });
        } finally {
            $app->rollBack();
            $migrator->executeStatement('RESET lock_timeout');
        }

        self::assertSame('55P03', $sqlState);
        self::assertEqualsWithDelta(5.0, $waitedSeconds, 2.0);
        self::assertNotNull($migrator->fetchOne("SELECT to_regclass('public.ast_usage_202611')"));
    }

    private function inRolledBackTransaction(callable $test): void
    {
        $migrator = $this->migrator();
        $migrator->beginTransaction();
        try {
            $test($migrator);
        } finally {
            $migrator->rollBack();
        }
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }
}
