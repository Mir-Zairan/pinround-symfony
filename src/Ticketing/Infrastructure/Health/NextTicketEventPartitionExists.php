<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Health;

use App\SharedKernel\Infrastructure\Health\ReadinessCheck;
use App\SharedKernel\Infrastructure\Health\ReadinessCheckFailed;
use App\Ticketing\Infrastructure\Doctrine\TicketEventPartitions;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * tkt_ticket_event has a partition for next month (UTC), so a ticket that moves after the month turns still has a
 * place for its history: the table has no DEFAULT partition, and an insert past the last one fails. Reads only the
 * catalog, so it needs no tenant and no grant on tkt_ticket_event.
 */
final readonly class NextTicketEventPartitionExists implements ReadinessCheck
{
    private const string PARTITION_OF_TKT_TICKET_EVENT = 'SELECT 1 FROM pg_catalog.pg_inherits i JOIN pg_catalog.pg_class c ON c.oid = i.inhrelid'
        ." WHERE i.inhparent = 'tkt_ticket_event'::regclass AND c.relname = ?";

    public function __construct(private Connection $connection, private ClockInterface $clock)
    {
    }

    public function name(): string
    {
        return 'next_ticket_event_partition';
    }

    public function verify(): void
    {
        $nextMonth = TicketEventPartitions::monthOf($this->clock->now())->modify('first day of next month');
        if (false === $this->connection->fetchOne(self::PARTITION_OF_TKT_TICKET_EVENT, [TicketEventPartitions::partitionNameFor($nextMonth)])) {
            throw new ReadinessCheckFailed('tkt_ticket_event has no partition for next month.');
        }
    }
}
