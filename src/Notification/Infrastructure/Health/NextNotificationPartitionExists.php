<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Health;

use App\Notification\Infrastructure\Doctrine\NotificationPartitions;
use App\SharedKernel\Infrastructure\Doctrine\Migration\MonthlyPartitions;
use App\SharedKernel\Infrastructure\Health\ReadinessCheck;
use App\SharedKernel\Infrastructure\Health\ReadinessCheckFailed;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * ntf_notification has a partition for next month (UTC), so a ping or bell row written after the month turns still has
 * a place: the table has no DEFAULT partition, and an insert past the last one fails. Reads only the catalog, so it
 * needs no tenant and no grant on ntf_notification.
 */
final readonly class NextNotificationPartitionExists implements ReadinessCheck
{
    private const string PARTITION_OF_NTF_NOTIFICATION = 'SELECT 1 FROM pg_catalog.pg_inherits i JOIN pg_catalog.pg_class c ON c.oid = i.inhrelid'
        ." WHERE i.inhparent = 'ntf_notification'::regclass AND c.relname = ?";

    public function __construct(private Connection $connection, private ClockInterface $clock)
    {
    }

    public function name(): string
    {
        return 'next_notification_partition';
    }

    public function verify(): void
    {
        $nextMonth = MonthlyPartitions::monthOf($this->clock->now())->modify('first day of next month');
        if (false === $this->connection->fetchOne(self::PARTITION_OF_NTF_NOTIFICATION, [NotificationPartitions::partitionNameFor($nextMonth)])) {
            throw new ReadinessCheckFailed('ntf_notification has no partition for next month.');
        }
    }
}
