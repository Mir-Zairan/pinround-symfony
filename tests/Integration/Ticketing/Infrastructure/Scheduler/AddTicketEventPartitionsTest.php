<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing\Infrastructure\Scheduler;

use App\Ticketing\Infrastructure\Scheduler\AddTicketEventPartitions;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The partition job on the migrated test database, at clocks years past the migration's months, so every partition it
 * should add is missing before it runs. tearDown drops what it added.
 */
final class AddTicketEventPartitionsTest extends KernelTestCase
{
    private const array FAR_PARTITIONS = [
        'tkt_ticket_event_203106', 'tkt_ticket_event_203107', 'tkt_ticket_event_203108', 'tkt_ticket_event_203109', 'tkt_ticket_event_203110',
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

        self::assertSame(['tkt_ticket_event_203106', 'tkt_ticket_event_203107', 'tkt_ticket_event_203108', 'tkt_ticket_event_203109'], $firstRun);
        self::assertSame([], $secondRun);
        self::assertSame(
            [
                "tkt_ticket_event_203106 FOR VALUES FROM ('2031-06-01 00:00:00+00') TO ('2031-07-01 00:00:00+00')",
                "tkt_ticket_event_203107 FOR VALUES FROM ('2031-07-01 00:00:00+00') TO ('2031-08-01 00:00:00+00')",
                "tkt_ticket_event_203108 FOR VALUES FROM ('2031-08-01 00:00:00+00') TO ('2031-09-01 00:00:00+00')",
                "tkt_ticket_event_203109 FOR VALUES FROM ('2031-09-01 00:00:00+00') TO ('2031-10-01 00:00:00+00')",
            ],
            $this->migrator()->fetchFirstColumn(
                "SELECT c.relname || ' ' || pg_get_expr(c.relpartbound, c.oid) FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid
                 WHERE i.inhparent = 'public.tkt_ticket_event'::regclass AND c.relname LIKE 'tkt_ticket_event_2031%' ORDER BY c.relname",
            ),
        );
    }

    #[Test]
    public function eachAddedPartitionHasRowLevelSecurityAndNoGrants(): void
    {
        $this->jobAt('2031-06-15 10:00:00 UTC')();

        self::assertSame(
            [
                ['relname' => 'tkt_ticket_event_203106', 'relrowsecurity' => true, 'relforcerowsecurity' => true, 'policies' => 1, 'grants' => 0],
                ['relname' => 'tkt_ticket_event_203107', 'relrowsecurity' => true, 'relforcerowsecurity' => true, 'policies' => 1, 'grants' => 0],
                ['relname' => 'tkt_ticket_event_203108', 'relrowsecurity' => true, 'relforcerowsecurity' => true, 'policies' => 1, 'grants' => 0],
                ['relname' => 'tkt_ticket_event_203109', 'relrowsecurity' => true, 'relforcerowsecurity' => true, 'policies' => 1, 'grants' => 0],
            ],
            $this->migrator()->fetchAllAssociative(
                "SELECT c.relname, c.relrowsecurity, c.relforcerowsecurity,
                        (SELECT count(*) FROM pg_policy p WHERE p.polrelid = c.oid AND p.polname = 'tenant_isolation') AS policies,
                        (SELECT count(*) FROM aclexplode(coalesce(c.relacl, acldefault('r', c.relowner))) a WHERE a.grantee <> c.relowner) AS grants
                 FROM pg_class c WHERE c.relname ~ '^tkt_ticket_event_2031\d{2}$' ORDER BY c.relname",
            ),
        );
    }

    #[Test]
    public function itCountsMonthsInUtcWhateverTheClocksTimeZone(): void
    {
        // 20:00 on 30 June in New York is already 1 July in UTC.
        $added = $this->jobAt('2031-06-30 20:00:00 America/New_York')();

        self::assertSame(['tkt_ticket_event_203107', 'tkt_ticket_event_203108', 'tkt_ticket_event_203109', 'tkt_ticket_event_203110'], $added);
    }

    #[Test]
    public function itAddsOnlyTheMonthsThatAreMissing(): void
    {
        $this->jobAt('2031-06-15 10:00:00 UTC')();
        $this->migrator()->executeStatement('DROP TABLE tkt_ticket_event_203108');

        self::assertSame(['tkt_ticket_event_203108'], $this->jobAt('2031-06-20 10:00:00 UTC')());
    }

    #[Test]
    public function theContainersJobHasTheRightsToAddPartitionsAndReadsTheAppClock(): void
    {
        self::getContainer()->set('clock', new MockClock('2031-06-15 10:00:00 UTC'));
        $job = self::getContainer()->get(AddTicketEventPartitions::class);

        self::assertSame(['tkt_ticket_event_203106', 'tkt_ticket_event_203107', 'tkt_ticket_event_203108', 'tkt_ticket_event_203109'], $job());
    }

    private function jobAt(string $now): AddTicketEventPartitions
    {
        return new AddTicketEventPartitions($this->migrator(), new MockClock($now));
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
