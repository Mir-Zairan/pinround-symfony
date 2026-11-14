<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Doctrine\Migration;

use App\Notification\Infrastructure\Doctrine\NotificationPartitions;
use App\SharedKernel\Infrastructure\Doctrine\Migration\MonthlyPartitions;
use App\Ticketing\Infrastructure\Doctrine\TicketEventPartitions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MonthlyPartitionsTest extends TestCase
{
    #[Test]
    public function aMonthIsTheUtcMonthOfTheInstant(): void
    {
        // 01:30 on 1 August in UTC+02:00 is still July in UTC.
        $instant = new \DateTimeImmutable('2027-08-01 01:30:00+02:00');

        self::assertSame('2027-07-01 00:00:00 +00:00', MonthlyPartitions::monthOf($instant)->format('Y-m-d H:i:s P'));
        self::assertSame('tkt_ticket_event_202707', MonthlyPartitions::partitionNameFor('tkt_ticket_event', $instant));
    }

    #[Test]
    public function thePartitionStatementsAreTheOnesTheAppliedMigrationsRan(): void
    {
        self::assertSame(
            [
                "CREATE TABLE tkt_ticket_event_202712 PARTITION OF tkt_ticket_event FOR VALUES FROM ('2027-12-01+00') TO ('2028-01-01+00')",
                'ALTER TABLE "tkt_ticket_event_202712" ENABLE ROW LEVEL SECURITY',
                'ALTER TABLE "tkt_ticket_event_202712" FORCE ROW LEVEL SECURITY',
                "CREATE POLICY tenant_isolation ON \"tkt_ticket_event_202712\" FOR ALL USING (organization_id = NULLIF(current_setting('app.org_id', true), '')::uuid) WITH CHECK (organization_id = NULLIF(current_setting('app.org_id', true), '')::uuid)",
                'REVOKE ALL ON tkt_ticket_event_202712 FROM PUBLIC, pinround_app, pinround_readonly',
            ],
            TicketEventPartitions::statementsFor(new \DateTimeImmutable('2027-12-15 10:00:00+00')),
        );
    }

    #[Test]
    public function eachTableNamesItsOwnPartitions(): void
    {
        $month = new \DateTimeImmutable('2027-03-15 10:00:00+00');

        self::assertSame('ntf_notification_202703', NotificationPartitions::partitionNameFor($month));
        self::assertSame(
            "CREATE TABLE ntf_notification_202703 PARTITION OF ntf_notification FOR VALUES FROM ('2027-03-01+00') TO ('2027-04-01+00')",
            NotificationPartitions::statementsFor($month)[0],
        );
    }

    #[Test]
    public function aPartitionHasEndedOnceItsWholeMonthIsBeforeTheCutoff(): void
    {
        $partitions = ['ntf_notification_202610', 'ntf_notification_202611', 'ntf_notification_202612', 'ntf_notification_202701', 'ntf_notification_202704', 'ntf_notification_202707'];

        // 2027-04-15 10:00 less 90 days: 2026-12 ended on 2027-01-01, 2027-01 ends on 2027-02-01.
        self::assertSame(
            ['ntf_notification_202610', 'ntf_notification_202611', 'ntf_notification_202612'],
            MonthlyPartitions::partitionsEndedBy('ntf_notification', $partitions, new \DateTimeImmutable('2027-01-15 10:00:00 UTC')),
        );
    }

    #[Test]
    public function aMonthEndingExactlyAtTheCutoffHasEndedAndOneSecondLaterItHasNot(): void
    {
        $partitions = ['ntf_notification_202701'];

        self::assertSame($partitions, MonthlyPartitions::partitionsEndedBy('ntf_notification', $partitions, new \DateTimeImmutable('2027-02-01 00:00:00 UTC')));
        self::assertSame([], MonthlyPartitions::partitionsEndedBy('ntf_notification', $partitions, new \DateTimeImmutable('2027-01-31 23:59:59 UTC')));
        // 01:00 on 1 February in UTC+02:00 is still January in UTC.
        self::assertSame([], MonthlyPartitions::partitionsEndedBy('ntf_notification', $partitions, new \DateTimeImmutable('2027-02-01 01:00:00+02:00')));
    }

    #[Test]
    public function aNameOfAnotherShapeOrTableHasNeverEnded(): void
    {
        $partitions = [
            'ntf_notification_202613', 'ntf_notification_202600', 'ntf_notification_20261', 'ntf_notification_2026011',
            "ntf_notification_202601\n", 'ntf_notification_old', 'tkt_ticket_event_202601', 'xntf_notification_202601',
        ];

        self::assertSame([], MonthlyPartitions::partitionsEndedBy('ntf_notification', $partitions, new \DateTimeImmutable('2031-01-01 00:00:00 UTC')));
    }

    #[Test]
    public function aPartitionNameLongerThanPostgresKeepsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a plain lowercase table name.');

        MonthlyPartitions::statementsFor(str_repeat('a', 57), new \DateTimeImmutable('2027-03-15 10:00:00+00'));
    }
}
