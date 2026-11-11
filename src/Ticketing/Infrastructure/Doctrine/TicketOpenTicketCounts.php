<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\Ticketing\Application\OpenTicketCounts;
use App\Ticketing\Domain\ProjectId;
use Doctrine\DBAL\Connection;

/**
 * The count uses tkt_ticket_project_id_step_id_priority_rank_updated_at_id_idx (organization_id, project_id, ...).
 */
final readonly class TicketOpenTicketCounts implements OpenTicketCounts
{
    public function __construct(private Connection $connection, private TenantContext $tenantContext)
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
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Open tickets are counted in a transaction under the organization as tenant; none is open.');
        }

        // One array literal keeps the SQL text the same for any number of ids; a ProjectId is a uuid, so needs no quoting.
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT project_id, count(*) FROM tkt_ticket WHERE organization_id = ? AND project_id = ANY(?::uuid[]) AND closed_at IS NULL GROUP BY project_id',
            [$this->tenantContext->require()->toString(), '{'.implode(',', array_keys($counts)).'}'],
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
