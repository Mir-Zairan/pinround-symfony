<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine;

use App\SharedKernel\Infrastructure\Doctrine\Migration\MonthlyPartitions;
use Doctrine\DBAL\Connection;

/**
 * The monthly partitions of ntf_notification (MonthlyPartitions), so the migrations, AddNotificationPartitions and
 * DropExpiredNotificationPartitions work on the same partitions, and the lock that keeps two writers of them apart.
 */
final class NotificationPartitions
{
    public const string PARENT = 'ntf_notification';

    /**
     * Waits for, then holds until the open transaction ends, the lock every writer of partitions takes first, so two of
     * them never both find a month missing, or both drop the same one, and the second statement fails. Outside a
     * transaction it is released at once.
     */
    public static function lockForChanges(Connection $connection): void
    {
        $connection->fetchOne("SELECT pg_advisory_xact_lock(hashtext('ntf_notification_partitions'))");
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
     * @return list<string> the attached partitions whose whole UTC month ends at or before the cutoff, oldest first
     */
    public static function partitionsEndedBy(Connection $connection, \DateTimeImmutable $cutoff): array
    {
        return MonthlyPartitions::partitionsEndedBy(self::PARENT, MonthlyPartitions::attachedPartitions($connection, self::PARENT), $cutoff);
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
