<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Application;

use App\Ticketing\Application\OpenTicketCounts;
use App\Ticketing\Domain\ProjectId;

/**
 * The given open-ticket counts; any other project has none.
 */
final readonly class FixedOpenTicketCounts implements OpenTicketCounts
{
    /**
     * @param array<string, int> $openTicketsByProject by project id
     */
    public function __construct(private array $openTicketsByProject = [])
    {
    }

    public function openTicketsIn(ProjectId ...$projectIds): array
    {
        $counts = [];
        foreach ($projectIds as $projectId) {
            $counts[$projectId->toString()] = $this->openTicketsByProject[$projectId->toString()] ?? 0;
        }

        return $counts;
    }
}
