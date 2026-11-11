<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Connection;

/**
 * The monthly partitions of tkt_ticket_event, named tkt_ticket_event_YYYYMM with UTC month bounds. One place for the
 * DDL of a month, so the migrations and AddTicketEventPartitions create the same partition.
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
        $missingMonths = [];
        $lastMonth = self::monthOf($through);
        for ($month = self::monthOf($from); $month <= $lastMonth; $month = $month->modify('first day of next month')) {
            if (null === $connection->fetchOne('SELECT to_regclass(?)', ['public.'.self::partitionNameFor($month)])) {
                $missingMonths[] = $month;
            }
        }

        return $missingMonths;
    }

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
