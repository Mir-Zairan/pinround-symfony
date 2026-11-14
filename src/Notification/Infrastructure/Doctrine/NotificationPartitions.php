<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;

/**
 * The monthly partitions of ntf_notification, named ntf_notification_YYYYMM with UTC month bounds. One place for the
 * DDL of a month, so the migrations and a later partition job create the same partition.
 */
final class NotificationPartitions
{
    public const string PARENT = 'ntf_notification';

    /**
     * The UTC month that holds the given instant, at its first second.
     */
    public static function monthOf(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->modify('first day of this month midnight');
    }

    /**
     * @param \DateTimeImmutable $month any instant; its UTC month names the partition
     */
    public static function partitionNameFor(\DateTimeImmutable $month): string
    {
        return self::PARENT.'_'.self::monthOf($month)->format('Ym');
    }

    /**
     * Creates the partition, turns row-level security on for it and takes every right from the roles that read through
     * the parent: PostgreSQL copies neither the RLS flags nor the policy to a partition, and a direct query uses the
     * partition's own. Grants stay on the parent only.
     *
     * @param \DateTimeImmutable $month any instant; its UTC month is the partition's range
     *
     * @return list<string>
     */
    public static function statementsFor(\DateTimeImmutable $month): array
    {
        $firstDay = self::monthOf($month);
        $partition = self::partitionNameFor($firstDay);

        return [
            // Explicit +00: the bounds are UTC months whatever the session's time zone.
            \sprintf(
                "CREATE TABLE %s PARTITION OF %s FOR VALUES FROM ('%s+00') TO ('%s+00')",
                $partition,
                self::PARENT,
                $firstDay->format('Y-m-d'),
                $firstDay->modify('first day of next month')->format('Y-m-d'),
            ),
            ...TenantTable::enableRls($partition),
            \sprintf('REVOKE ALL ON %s FROM PUBLIC, pinround_app, pinround_readonly', $partition),
        ];
    }
}
