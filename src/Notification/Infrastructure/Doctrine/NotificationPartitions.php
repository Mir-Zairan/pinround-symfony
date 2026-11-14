<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine;

use App\SharedKernel\Infrastructure\Doctrine\Migration\MonthlyPartitions;

/**
 * The monthly partitions of ntf_notification (MonthlyPartitions), so the migrations and a later partition job create
 * the same partition.
 */
final class NotificationPartitions
{
    public const string PARENT = 'ntf_notification';

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
