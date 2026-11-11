<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\Ticketing\Application\OpenTicketCounts;
use App\Ticketing\Domain\ProjectId;

/**
 * Gives the counts it was built with and records each call. Unlike the port's contract, a project missing from
 * $countsByProjectId gets no entry, so a test can check what the caller does then.
 */
final class RecordedOpenTicketCounts implements OpenTicketCounts
{
    /** @var list<list<string>> the project ids of each call, in call order */
    public private(set) array $calls = [];

    /**
     * @param array<string, int> $countsByProjectId
     */
    public function __construct(private readonly array $countsByProjectId)
    {
    }

    public function openTicketsIn(ProjectId ...$projectIds): array
    {
        $ids = array_values(array_map(static fn (ProjectId $projectId): string => $projectId->toString(), $projectIds));
        $this->calls[] = $ids;

        return array_intersect_key($this->countsByProjectId, array_flip($ids));
    }
}
