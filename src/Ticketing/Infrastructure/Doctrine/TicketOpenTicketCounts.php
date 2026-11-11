<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\Ticketing\Application\OpenTicketCounts;
use App\Ticketing\Domain\ProjectId;

final readonly class TicketOpenTicketCounts implements OpenTicketCounts
{
    public function openTicketsIn(ProjectId ...$projectIds): array
    {
        // TODO(P03): count open tickets in tkt_ticket once tickets exist
        $counts = [];
        foreach ($projectIds as $projectId) {
            $counts[$projectId->toString()] = 0;
        }

        return $counts;
    }
}
