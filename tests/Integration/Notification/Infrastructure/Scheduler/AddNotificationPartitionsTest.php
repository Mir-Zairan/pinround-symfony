<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Scheduler;

use App\Notification\Infrastructure\Scheduler\AddNotificationPartitions;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The partition job on the migrated test database, at clocks years past the migration's months, so every partition it
 * should add is missing before it runs. tearDown drops what it added.
 */
final class AddNotificationPartitionsTest extends KernelTestCase
{
    private const array FAR_PARTITIONS = [
        'ntf_notification_203106', 'ntf_notification_203107', 'ntf_notification_203108', 'ntf_notification_203109', 'ntf_notification_203110',
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

        self::assertSame(['ntf_notification_203106', 'ntf_notification_203107', 'ntf_notification_203108', 'ntf_notification_203109'], $firstRun);
        self::assertSame([], $secondRun);
        self::assertSame(
            [
                "ntf_notification_203106 FOR VALUES FROM ('2031-06-01 00:00:00+00') TO ('2031-07-01 00:00:00+00')",
                "ntf_notification_203107 FOR VALUES FROM ('2031-07-01 00:00:00+00') TO ('2031-08-01 00:00:00+00')",
                "ntf_notification_203108 FOR VALUES FROM ('2031-08-01 00:00:00+00') TO ('2031-09-01 00:00:00+00')",
                "ntf_notification_203109 FOR VALUES FROM ('2031-09-01 00:00:00+00') TO ('2031-10-01 00:00:00+00')",
            ],
            $this->migrator()->fetchFirstColumn(
                "SELECT c.relname || ' ' || pg_get_expr(c.relpartbound, c.oid) FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid
                 WHERE i.inhparent = 'public.ntf_notification'::regclass AND c.relname LIKE 'ntf_notification_2031%' ORDER BY c.relname",
            ),
        );
    }

    #[Test]
    public function eachAddedPartitionHasRowLevelSecurityAndNoGrants(): void
    {
        $this->jobAt('2031-06-15 10:00:00 UTC')();

        self::assertSame(
            [
                ['relname' => 'ntf_notification_203106', 'relrowsecurity' => true, 'relforcerowsecurity' => true, 'policies' => 1, 'grants' => 0],
                ['relname' => 'ntf_notification_203107', 'relrowsecurity' => true, 'relforcerowsecurity' => true, 'policies' => 1, 'grants' => 0],
                ['relname' => 'ntf_notification_203108', 'relrowsecurity' => true, 'relforcerowsecurity' => true, 'policies' => 1, 'grants' => 0],
                ['relname' => 'ntf_notification_203109', 'relrowsecurity' => true, 'relforcerowsecurity' => true, 'policies' => 1, 'grants' => 0],
            ],
            $this->migrator()->fetchAllAssociative(
                "SELECT c.relname, c.relrowsecurity, c.relforcerowsecurity,
                        (SELECT count(*) FROM pg_policy p WHERE p.polrelid = c.oid AND p.polname = 'tenant_isolation') AS policies,
                        (SELECT count(*) FROM aclexplode(coalesce(c.relacl, acldefault('r', c.relowner))) a WHERE a.grantee <> c.relowner) AS grants
                 FROM pg_class c WHERE c.relname ~ '^ntf_notification_2031\d{2}$' ORDER BY c.relname",
            ),
        );
    }

    #[Test]
    public function itAddsOnlyTheMonthsThatAreMissing(): void
    {
        $this->jobAt('2031-06-15 10:00:00 UTC')();
        $this->migrator()->executeStatement('DROP TABLE ntf_notification_203108');

        self::assertSame(['ntf_notification_203108'], $this->jobAt('2031-06-20 10:00:00 UTC')());
    }

    #[Test]
    public function theContainersJobHasTheRightsToAddPartitionsAndReadsTheAppClock(): void
    {
        self::getContainer()->set('clock', new MockClock('2031-07-15 10:00:00 UTC'));
        $job = self::getContainer()->get(AddNotificationPartitions::class);

        self::assertSame(['ntf_notification_203107', 'ntf_notification_203108', 'ntf_notification_203109', 'ntf_notification_203110'], $job());
    }

    private function jobAt(string $now): AddNotificationPartitions
    {
        return new AddNotificationPartitions($this->migrator(), new MockClock($now));
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
