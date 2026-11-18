<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\Infrastructure\Scheduler;

use App\Assist\Infrastructure\Scheduler\AddUsagePartitions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The partition job on the migrated test database, at clocks years past the migration's months, so every partition it
 * should add is missing before it runs. tearDown drops what it added.
 */
final class AddUsagePartitionsTest extends KernelTestCase
{
    private const array FAR_PARTITIONS = [
        'ast_usage_203106', 'ast_usage_203107', 'ast_usage_203108', 'ast_usage_203109', 'ast_usage_203110',
    ];

    protected function setUp(): void
    {
        $this->dropFarPartitions();
    }

    protected function tearDown(): void
    {
        $this->dropFarPartitions();
        parent::tearDown();
    }

    #[Test]
    public function itAddsTheCurrentMonthAndThreeAheadAndASecondRunAddsNothing(): void
    {
        $job = $this->jobAt('2031-06-15 10:00:00 UTC');

        $firstRun = $job();
        $secondRun = $job();

        self::assertSame(['ast_usage_203106', 'ast_usage_203107', 'ast_usage_203108', 'ast_usage_203109'], $firstRun);
        self::assertSame([], $secondRun);
        self::assertSame(
            [
                "ast_usage_203106 FOR VALUES FROM ('2031-06-01 00:00:00+00') TO ('2031-07-01 00:00:00+00')",
                "ast_usage_203107 FOR VALUES FROM ('2031-07-01 00:00:00+00') TO ('2031-08-01 00:00:00+00')",
                "ast_usage_203108 FOR VALUES FROM ('2031-08-01 00:00:00+00') TO ('2031-09-01 00:00:00+00')",
                "ast_usage_203109 FOR VALUES FROM ('2031-09-01 00:00:00+00') TO ('2031-10-01 00:00:00+00')",
            ],
            $this->migrator()->fetchFirstColumn(
                "SELECT c.relname || ' ' || pg_get_expr(c.relpartbound, c.oid) FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid
                 WHERE i.inhparent = 'public.ast_usage'::regclass AND c.relname LIKE 'ast_usage_2031%' ORDER BY c.relname",
            ),
        );
    }

    #[Test]
    public function eachAddedPartitionHasRowLevelSecurityAndNoGrants(): void
    {
        $this->jobAt('2031-06-15 10:00:00 UTC')();

        self::assertSame(
            [
                ['relname' => 'ast_usage_203106', 'relrowsecurity' => true, 'relforcerowsecurity' => true, 'policies' => 1, 'grants' => 0],
                ['relname' => 'ast_usage_203107', 'relrowsecurity' => true, 'relforcerowsecurity' => true, 'policies' => 1, 'grants' => 0],
                ['relname' => 'ast_usage_203108', 'relrowsecurity' => true, 'relforcerowsecurity' => true, 'policies' => 1, 'grants' => 0],
                ['relname' => 'ast_usage_203109', 'relrowsecurity' => true, 'relforcerowsecurity' => true, 'policies' => 1, 'grants' => 0],
            ],
            $this->migrator()->fetchAllAssociative(
                "SELECT c.relname, c.relrowsecurity, c.relforcerowsecurity,
                        (SELECT count(*) FROM pg_policy p WHERE p.polrelid = c.oid AND p.polname = 'tenant_isolation') AS policies,
                        (SELECT count(*) FROM aclexplode(coalesce(c.relacl, acldefault('r', c.relowner))) a WHERE a.grantee <> c.relowner) AS grants
                 FROM pg_class c WHERE c.relname ~ '^ast_usage_2031\d{2}$' ORDER BY c.relname",
            ),
        );
    }

    #[Test]
    public function itAddsOnlyTheMonthsThatAreMissing(): void
    {
        $this->jobAt('2031-06-15 10:00:00 UTC')();
        $this->migrator()->executeStatement('DROP TABLE ast_usage_203108');

        self::assertSame(['ast_usage_203108'], $this->jobAt('2031-06-20 10:00:00 UTC')());
    }

    #[Test]
    public function theContainersJobHasTheRightsToAddPartitionsAndReadsTheAppClock(): void
    {
        self::getContainer()->set('clock', new MockClock('2031-07-15 10:00:00 UTC'));
        $job = self::getContainer()->get(AddUsagePartitions::class);

        self::assertSame(['ast_usage_203107', 'ast_usage_203108', 'ast_usage_203109', 'ast_usage_203110'], $job());
    }

    #[Test]
    public function itGivesUpOnATableLockAfterFiveSecondsAndAddsNothing(): void
    {
        $migrator = $this->migrator();
        // Far past the job's own timeout, so only that timeout can end the wait within the bound below.
        $migrator->executeStatement("SET lock_timeout = '60s'");
        $app = self::getContainer()->get('doctrine.dbal.default_connection');
        $app->beginTransaction();
        try {
            $app->executeStatement('LOCK TABLE ast_usage IN ACCESS SHARE MODE');
            $startedAt = hrtime(true);
            try {
                $this->jobAt('2031-06-15 10:00:00 UTC')();
                self::fail('The job added partitions while a reader held ast_usage.');
            } catch (DriverException $refusal) {
                $sqlState = $refusal->getSQLState();
            }
            $waitedSeconds = (hrtime(true) - $startedAt) / 1e9;
        } finally {
            $app->rollBack();
            $migrator->executeStatement('RESET lock_timeout');
        }

        self::assertSame('55P03', $sqlState);
        self::assertEqualsWithDelta(5.0, $waitedSeconds, 2.0);
        self::assertNull($migrator->fetchOne("SELECT to_regclass('public.ast_usage_203106')"), 'A partition was kept from the run that gave up.');
    }

    private function jobAt(string $now): AddUsagePartitions
    {
        return new AddUsagePartitions($this->migrator(), new MockClock($now));
    }

    private function dropFarPartitions(): void
    {
        foreach (self::FAR_PARTITIONS as $partition) {
            $this->migrator()->executeStatement('DROP TABLE IF EXISTS '.$partition);
        }
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }
}
