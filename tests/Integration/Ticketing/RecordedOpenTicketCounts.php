<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Application\OpenTicketCounts;
use App\Ticketing\Domain\ProjectId;

/**
 * Gives the counts it was built with for a viewer and records each call. Unlike the port's contract, a project missing
 * from $countsByProjectId gets no entry, so a test can check what the caller does then. The guard count is refused: a
 * list shown to a viewer must not count the tickets they may not see.
 */
final class RecordedOpenTicketCounts implements OpenTicketCounts
{
    /** @var list<list<string>> the project ids of each call, in call order */
    public private(set) array $calls = [];

    /** @var list<string> the viewer id of each call, in call order */
    public private(set) array $viewerIds = [];

    /**
     * @param array<string, int> $countsByProjectId
     */
    public function __construct(private readonly array $countsByProjectId)
    {
    }

    public function openTicketsIn(ProjectId ...$projectIds): array
    {
        throw new \LogicException('A list shown to a viewer asked for the guard count of every open ticket.');
    }

    public function openTicketsVisibleTo(TicketVisibility $viewer, ProjectId ...$projectIds): array
    {
        $ids = array_values(array_map(static fn (ProjectId $projectId): string => $projectId->toString(), $projectIds));
        $this->calls[] = $ids;
        $this->viewerIds[] = $viewer->viewerId()->toString();

        return array_intersect_key($this->countsByProjectId, array_flip($ids));
    }
}
