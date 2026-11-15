<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine\Migration;

use Doctrine\DBAL\Connection;

/**
 * The monthly partitions of a table partitioned by RANGE on a timestamptz column, named <parent>_YYYYMM with UTC month
 * bounds. One place for the DDL of a month, so the migrations and the partition jobs of every such table create the
 * same partition.
 */
final class MonthlyPartitions
{
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
    public static function partitionNameFor(string $parent, \DateTimeImmutable $month): string
    {
        return $parent.'_'.self::monthOf($month)->format('Ym');
    }

    /**
     * Creates the partition, turns row-level security on for it and takes every right from the roles that read through
     * the parent: PostgreSQL copies neither the RLS flags nor the policy to a partition, and a direct query uses the
     * partition's own. Grants stay on the parent only.
     *
     * @param \DateTimeImmutable $month any instant; its UTC month is the partition's range
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException when the partition name is not a plain lowercase identifier of at most 63 characters
     */
    public static function statementsFor(string $parent, \DateTimeImmutable $month): array
    {
        $firstDay = self::monthOf($month);
        $partition = self::partitionNameFor($parent, $firstDay);

        return [
            // Explicit +00: the bounds are UTC months whatever the session's time zone.
            \sprintf(
                "CREATE TABLE %s PARTITION OF %s FOR VALUES FROM ('%s+00') TO ('%s+00')",
                $partition,
                $parent,
                $firstDay->format('Y-m-d'),
                $firstDay->modify('first day of next month')->format('Y-m-d'),
            ),
            ...TenantTable::enableRls($partition),
            \sprintf('REVOKE ALL ON %s FROM PUBLIC, pinround_app, pinround_readonly', $partition),
        ];
    }

    /**
     * @param \DateTimeImmutable $from    any instant; its UTC month is the first one looked at
     * @param \DateTimeImmutable $through any instant; its UTC month is the last one looked at
     *
     * @return list<\DateTimeImmutable> the first second of each month in the range that has no partition, oldest first
     */
    public static function monthsWithoutPartition(Connection $connection, string $parent, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        $missingMonths = [];
        $lastMonth = self::monthOf($through);
        for ($month = self::monthOf($from); $month <= $lastMonth; $month = $month->modify('first day of next month')) {
            if (null === $connection->fetchOne('SELECT to_regclass(?)', ['public.'.self::partitionNameFor($parent, $month)])) {
                $missingMonths[] = $month;
            }
        }

        return $missingMonths;
    }

    /**
     * Reads the catalog, so a table named like a partition but not attached to the parent is not in it.
     *
     * @return list<string> the partitions attached to the parent, in name order
     *
     * @throws \Doctrine\DBAL\Exception when the parent does not exist
     */
    public static function attachedPartitions(Connection $connection, string $parent): array
    {
        return array_map(static fn (mixed $partition): string => \is_string($partition) ? $partition : throw new \UnexpectedValueException('A partition name is not a string.'), $connection->fetchFirstColumn(
            'SELECT c.relname FROM pg_catalog.pg_inherits i JOIN pg_catalog.pg_class c ON c.oid = i.inhrelid'
            .' WHERE i.inhparent = CAST(? AS regclass) ORDER BY c.relname',
            ['public.'.$parent],
        ));
    }

    /**
     * @param list<string> $partitions
     *
     * @return list<string> the partitions named <parent>_YYYYMM whose whole UTC month ends at or before the cutoff, in
     *                      the given order; a name of any other shape is never in it
     */
    public static function partitionsEndedBy(string $parent, array $partitions, \DateTimeImmutable $cutoff): array
    {
        $ended = [];
        foreach ($partitions as $partition) {
            if (1 !== preg_match('/^'.preg_quote($parent, '/').'_(\d{4})(\d{2})\z/', $partition, $yearAndMonth)) {
                continue;
            }
            $monthNumber = (int) $yearAndMonth[2];
            if ($monthNumber < 1 || $monthNumber > 12) {
                continue;
            }
            $firstDay = new \DateTimeImmutable(\sprintf('%s-%02d-01 00:00:00', $yearAndMonth[1], $monthNumber), new \DateTimeZone('UTC'));
            if ($firstDay->modify('first day of next month') <= $cutoff) {
                $ended[] = $partition;
            }
        }

        return $ended;
    }
}
