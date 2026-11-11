<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\Ticketing\Application\OpenTicketCounts;
use App\Ticketing\Domain\ProjectId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Row-level security adds the tenant, so the count uses tkt_ticket_project_id_step_id_priority_rank_updated_at_id_idx
 * (organization_id, project_id, ...).
 */
final readonly class TicketOpenTicketCounts implements OpenTicketCounts
{
    public function __construct(private Connection $connection)
    {
    }

    public function openTicketsIn(ProjectId ...$projectIds): array
    {
        $counts = [];
        foreach ($projectIds as $projectId) {
            $counts[$projectId->toString()] = 0;
        }
        if ([] === $counts) {
            return [];
        }

        $rows = $this->connection->fetchAllKeyValue(
            'SELECT project_id, count(*) FROM tkt_ticket WHERE project_id IN (?) AND closed_at IS NULL GROUP BY project_id',
            [array_keys($counts)],
            [ArrayParameterType::STRING],
        );
        foreach ($rows as $projectId => $openTickets) {
            if (!\is_int($openTickets) || !\array_key_exists($projectId, $counts)) {
                throw new \LogicException('A ticket count from the database is not an integer of a given project.');
            }
            $counts[$projectId] = $openTickets;
        }

        return $counts;
    }
}
