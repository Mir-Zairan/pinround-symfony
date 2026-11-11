<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Scheduler;

use App\Ticketing\Infrastructure\Doctrine\TicketEventPartitions;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\WhenNot;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Keeps tkt_ticket_event partitioned from the current UTC month through three months ahead (data-model.md), so an
 * event never lands past the last partition. Safe to run again or twice at once: one transaction under an advisory
 * lock, creating only the months that are missing. Gives up after 5 s waiting for a table lock; the next day's run, still
 * months ahead of need, tries again.
 *
 * Creating a partition needs the table owner, so this runs on the migrator connection, which prod does not have. Prod
 * has the migrations' partitions through 2027-12 and needs its own runner as pinround_migrator before then (phase-03
 * hand-off notes).
 */
#[WhenNot('prod')]
#[AsPeriodicTask(frequency: '1 day')]
final readonly class AddTicketEventPartitions
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
            // CREATE … PARTITION OF blocks tkt_ticket_event and tkt_ticket; queued behind a long query it would stall both.
            $migrator->executeStatement("SET LOCAL lock_timeout = '5s'");
            TicketEventPartitions::lockForChanges($migrator);

            $created = [];
            $thisMonth = TicketEventPartitions::monthOf($this->clock->now());
            $lastMonth = $thisMonth->modify(\sprintf('+%d months', self::MONTHS_AHEAD));
            foreach (TicketEventPartitions::monthsWithoutPartition($migrator, $thisMonth, $lastMonth) as $month) {
                foreach (TicketEventPartitions::statementsFor($month) as $statement) {
                    $migrator->executeStatement($statement);
                }
                $created[] = TicketEventPartitions::partitionNameFor($month);
            }

            return $created;
        });
    }
}
