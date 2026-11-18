<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Scheduler;

use App\Assist\Infrastructure\Doctrine\UsagePartitions;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\WhenNot;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Keeps AI usage 13 months (data-model.md): drops each ast_usage partition whose whole UTC month ended at least 13
 * months ago, so every row it removes is older than that; the current and later months always stay. Safe to run again
 * or twice at once: one transaction under the advisory lock AddUsagePartitions takes too. Gives up after 5 s waiting
 * for a table lock; the next day's run tries again.
 *
 * Dropping a partition needs the table owner, so this runs on the migrator connection, which prod does not have
 * (phase-04 hand-off notes).
 */
#[WhenNot('prod')]
#[AsPeriodicTask(frequency: '1 day')]
final readonly class DropExpiredUsagePartitions
{
    private const string KEPT_FOR = 'P13M';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.migrator_connection')]
        private Connection $migrator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Commits its own transaction; the caller must have none open on the migrator connection.
     *
     * @return list<string> the partitions it dropped, oldest first; empty when none had expired
     */
    public function __invoke(): array
    {
        return $this->migrator->transactional(function (Connection $migrator): array {
            // Dropping a partition takes ACCESS EXCLUSIVE on ast_usage; queued behind a long query it would stall every
            // metered AI call. DETACH … CONCURRENTLY would not, but cannot run inside this locked transaction.
            $migrator->executeStatement("SET LOCAL lock_timeout = '5s'");
            UsagePartitions::lockForChanges($migrator);

            $cutoff = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->sub(new \DateInterval(self::KEPT_FOR));
            $expired = UsagePartitions::partitionsEndedBy($migrator, $cutoff);
            foreach ($expired as $partition) {
                $migrator->executeStatement('DROP TABLE '.$partition);
            }

            return $expired;
        });
    }
}
