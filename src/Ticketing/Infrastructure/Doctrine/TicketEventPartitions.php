<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Infrastructure\Doctrine\Migration\MonthlyPartitions;
use Doctrine\DBAL\Connection;

/**
 * The monthly partitions of tkt_ticket_event (MonthlyPartitions), so the migrations and AddTicketEventPartitions
 * create the same partition, and the lock that keeps two writers of them apart.
 */
final class TicketEventPartitions
{
    public const string PARENT = 'tkt_ticket_event';

    /**
     * Waits for, then holds until the open transaction ends, the lock every writer of partitions takes first, so two of
     * them never both find a month missing and the second CREATE fails. Outside a transaction it is released at once.
     */
    public static function lockForChanges(Connection $connection): void
    {
        $connection->fetchOne("SELECT pg_advisory_xact_lock(hashtext('tkt_ticket_event_partitions'))");
    }

    /**
     * @param \DateTimeImmutable $from    any instant; its UTC month is the first one looked at
     * @param \DateTimeImmutable $through any instant; its UTC month is the last one looked at
     *
     * @return list<\DateTimeImmutable> the first second of each month in the range that has no partition, oldest first
     */
    public static function monthsWithoutPartition(Connection $connection, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        return MonthlyPartitions::monthsWithoutPartition($connection, self::PARENT, $from, $through);
    }

    /**
     * The UTC month that holds the given instant, at its first second.
     */
    public static function monthOf(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        return MonthlyPartitions::monthOf($instant);
    }

    /**
     * @param \DateTimeImmutable $month any instant; its UTC month names the partition
     */
    public static function partitionNameFor(\DateTimeImmutable $month): string
    {
        return MonthlyPartitions::partitionNameFor(self::PARENT, $month);
    }

    /**
     * @param \DateTimeImmutable $month any instant; its UTC month is the partition's range
     *
     * @return list<string> see MonthlyPartitions::statementsFor
     */
    public static function statementsFor(\DateTimeImmutable $month): array
    {
        return MonthlyPartitions::statementsFor(self::PARENT, $month);
    }
}
