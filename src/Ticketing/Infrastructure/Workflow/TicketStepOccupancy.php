<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Workflow;

use App\Workflow\Api\StepOccupancy;
use Doctrine\DBAL\Connection;

/**
 * Workflow's StepOccupancy, answered by Ticketing, which owns the tickets. Row-level security adds the tenant, so the
 * count uses tkt_ticket_step_id_priority_rank_updated_at_id_idx (organization_id, step_id, ...).
 */
final readonly class TicketStepOccupancy implements StepOccupancy
{
    public function __construct(private Connection $connection)
    {
    }

    public function openTicketsIn(string $stepId): int
    {
        $openTickets = $this->connection->fetchOne(
            'SELECT count(*) FROM tkt_ticket WHERE step_id = ? AND closed_at IS NULL',
            [$stepId],
        );

        return \is_int($openTickets) ? $openTickets : throw new \LogicException('A ticket count from the database is not an integer.');
    }
}
