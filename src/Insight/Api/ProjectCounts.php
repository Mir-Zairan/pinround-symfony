<?php

declare(strict_types=1);

namespace App\Insight\Api;

/**
 * One project's numbers on the project rail of the Tickets page, as one viewer reads them.
 */
final readonly class ProjectCounts
{
    /**
     * @param string   $projectId the uuid of the project
     * @param int      $openCount tickets in a step of the groups todo, doing, check or wait
     * @param int|null $doneCount tickets in a step of the group done; null when the caller did not ask for them
     */
    public function __construct(
        public string $projectId,
        public int $openCount,
        public ?int $doneCount,
    ) {
    }
}
