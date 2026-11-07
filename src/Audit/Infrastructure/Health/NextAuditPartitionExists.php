<?php

declare(strict_types=1);

namespace App\Audit\Infrastructure\Health;

use App\SharedKernel\Infrastructure\Health\ReadinessCheck;
use App\SharedKernel\Infrastructure\Health\ReadinessCheckFailed;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * aud_event has a partition for next month (UTC), so an audit entry recorded after the month turns still has a
 * place to go: aud_event has no DEFAULT partition, and an insert past the last one fails (ADR-0026). Reads only the
 * catalog, so it needs no tenant and no grant on aud_event.
 */
final readonly class NextAuditPartitionExists implements ReadinessCheck
{
    // Partitions are named aud_event_YYYYMM with UTC month bounds (Version20261107170000).
    private const string PARTITION_OF_AUD_EVENT = 'SELECT 1 FROM pg_catalog.pg_inherits i JOIN pg_catalog.pg_class c ON c.oid = i.inhrelid'
        ." WHERE i.inhparent = 'aud_event'::regclass AND c.relname = ?";

    public function __construct(private Connection $connection, private ClockInterface $clock)
    {
    }

    public function name(): string
    {
        return 'next_audit_partition';
    }

    public function verify(): void
    {
        $nextMonth = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->modify('first day of next month');
        if (false === $this->connection->fetchOne(self::PARTITION_OF_AUD_EVENT, ['aud_event_'.$nextMonth->format('Ym')])) {
            throw new ReadinessCheckFailed('aud_event has no partition for next month.');
        }
    }
}
