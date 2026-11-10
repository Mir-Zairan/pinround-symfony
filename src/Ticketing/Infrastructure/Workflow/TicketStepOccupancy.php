<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Workflow;

use App\Workflow\Api\StepOccupancy;

/**
 * Workflow's StepOccupancy, answered by Ticketing, which owns the tickets.
 */
final readonly class TicketStepOccupancy implements StepOccupancy
{
    public function openTicketsIn(string $stepId): int
    {
        // TODO(P03): count open tickets in tkt_ticket once tickets exist
        return 0;
    }
}
