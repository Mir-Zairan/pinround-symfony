<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * "Where it goes" as a report draft's page posted it for Send, nothing checked yet: Ticketing's PostedWhereItGoes in a
 * shape this Api may hold (it may use only SharedKernel). ReportDrafts::send checks it as Report a problem checks its own.
 */
final readonly class ReportDraftWhereItGoes
{
    /**
     * @param string $teamId     a team's uuid, or "none" for "Not sure"
     * @param string $assigneeId a person's uuid, or empty for "The team (<lead> decides)"
     * @param string $type       bug, story or task
     * @param string $priority   critical, major, normal or minor
     */
    public function __construct(
        public string $teamId,
        public string $projectId,
        public string $assigneeId,
        public string $type,
        public string $priority,
    ) {
    }
}
