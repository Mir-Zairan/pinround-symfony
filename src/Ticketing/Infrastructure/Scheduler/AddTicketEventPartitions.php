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
 * lock, creating only the months that are missing.
 *
 * Creating a partition needs the table owner, so this runs on the migrator connection, which prod does not have; prod
 * gets its own runner as pinround_migrator (phase-03 hand-off notes).
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
            // Two runs at once would both see a month missing and the second CREATE would fail.
            $migrator->fetchOne("SELECT pg_advisory_xact_lock(hashtext('tkt_ticket_event_partitions'))");

            $created = [];
            $month = TicketEventPartitions::monthOf($this->clock->now());
            for ($i = 0; $i <= self::MONTHS_AHEAD; ++$i) {
                $partition = TicketEventPartitions::partitionNameFor($month);
                if (null === $migrator->fetchOne('SELECT to_regclass(?)', ['public.'.$partition])) {
                    foreach (TicketEventPartitions::statementsFor($month) as $statement) {
                        $migrator->executeStatement($statement);
                    }
                    $created[] = $partition;
                }
                $month = $month->modify('first day of next month');
            }

            return $created;
        });
    }
}
