<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Scheduler;

use App\Assist\Infrastructure\Doctrine\UsagePartitions;
use App\SharedKernel\Infrastructure\Doctrine\Migration\MonthlyPartitions;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\WhenNot;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Keeps ast_usage partitioned from the current UTC month through three months ahead (data-model.md), so a metered AI
 * call never lands past the last partition. Safe to run again or twice at once: one transaction under an advisory lock,
 * creating only the months that are missing. Gives up after 5 s waiting for a table lock; the next day's run, still
 * months ahead of need, tries again.
 *
 * Creating a partition needs the table owner, so this runs on the migrator connection, which prod does not have. Prod
 * has the migration's partitions through 2027-12 and needs its own runner as pinround_migrator before then (phase-04
 * hand-off notes).
 */
#[WhenNot('prod')]
#[AsPeriodicTask(frequency: '1 day')]
final readonly class AddUsagePartitions
{
    private const int MONTHS_AHEAD = 3;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.migrator_connection')]
        private Connection $migrator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Commits its own transaction; the caller must have none open on the migrator connection.
     *
     * @return list<string> the partitions it created, oldest first; empty when every month already had one
     */
    public function __invoke(): array
    {
        return $this->migrator->transactional(function (Connection $migrator): array {
            // CREATE … PARTITION OF blocks ast_usage; queued behind a long query it would stall every metered AI call.
            $migrator->executeStatement("SET LOCAL lock_timeout = '5s'");
            UsagePartitions::lockForChanges($migrator);

            $created = [];
            $thisMonth = MonthlyPartitions::monthOf($this->clock->now());
            $lastMonth = $thisMonth->modify(\sprintf('+%d months', self::MONTHS_AHEAD));
            foreach (UsagePartitions::monthsWithoutPartition($migrator, $thisMonth, $lastMonth) as $month) {
                foreach (UsagePartitions::statementsFor($month) as $statement) {
                    $migrator->executeStatement($statement);
                }
                $created[] = UsagePartitions::partitionNameFor($month);
            }

            return $created;
        });
    }
}
