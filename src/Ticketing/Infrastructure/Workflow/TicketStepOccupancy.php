<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Workflow;

use App\SharedKernel\Application\TenantContext;
use App\Workflow\Api\StepOccupancy;
use Doctrine\DBAL\Connection;

/**
 * Workflow's StepOccupancy, answered by Ticketing, which owns the tickets. The count uses
 * tkt_ticket_step_id_priority_rank_updated_at_id_idx (organization_id, step_id, ...).
 */
final readonly class TicketStepOccupancy implements StepOccupancy
{
    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    /**
     * @throws \LogicException when no transaction is open or no tenant is set, as row-level security would then hide
     *                         every ticket and the count would wrongly be 0
     */
    public function openTicketsIn(string $stepId): int
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Open tickets are counted in a transaction under the organization as tenant; none is open.');
        }

        $openTickets = $this->connection->fetchOne(
            'SELECT count(*) FROM tkt_ticket WHERE organization_id = ? AND step_id = ? AND closed_at IS NULL',
            [$this->tenantContext->require()->toString(), $stepId],
        );

        return \is_int($openTickets) ? $openTickets : throw new \LogicException('A ticket count from the database is not an integer.');
    }
}
