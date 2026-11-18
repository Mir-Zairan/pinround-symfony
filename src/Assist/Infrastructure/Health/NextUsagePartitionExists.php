<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Health;

use App\Assist\Infrastructure\Doctrine\UsagePartitions;
use App\SharedKernel\Infrastructure\Doctrine\Migration\MonthlyPartitions;
use App\SharedKernel\Infrastructure\Health\ReadinessCheck;
use App\SharedKernel\Infrastructure\Health\ReadinessCheckFailed;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * ast_usage has a partition for next month (UTC), so an AI call metered after the month turns still has a place: the
 * table has no DEFAULT partition, and an insert past the last one fails. Reads only the catalog, so it needs no tenant
 * and no grant on ast_usage.
 */
final readonly class NextUsagePartitionExists implements ReadinessCheck
{
    private const string PARTITION_OF_AST_USAGE = 'SELECT 1 FROM pg_catalog.pg_inherits i JOIN pg_catalog.pg_class c ON c.oid = i.inhrelid'
        ." WHERE i.inhparent = 'ast_usage'::regclass AND c.relname = ?";

    public function __construct(private Connection $connection, private ClockInterface $clock)
    {
    }

    public function name(): string
    {
        return 'next_usage_partition';
    }

    public function verify(): void
    {
        $nextMonth = MonthlyPartitions::monthOf($this->clock->now())->modify('first day of next month');
        if (false === $this->connection->fetchOne(self::PARTITION_OF_AST_USAGE, [UsagePartitions::partitionNameFor($nextMonth)])) {
            throw new ReadinessCheckFailed('ast_usage has no partition for next month.');
        }
    }
}
