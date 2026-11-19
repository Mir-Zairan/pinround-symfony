<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;

/**
 * A report from "Report a problem" (P06.T1, FR-INT-01), as TicketCommands::report takes it. The check reasons and the
 * visibility are not in it: Ticketing works them out from the words and these facts, so the form cannot choose them.
 */
final readonly class ProblemReport
{
    /**
     * @param string       $projectId           a project's uuid; a project of the team, or of any team when $teamId is null
     * @param TeamId|null  $teamId              null for "Not sure"
     * @param string       $type                bug, story or task
     * @param string       $priority            critical, major, normal or minor
     * @param UserId|null  $assignee            a lead or member of the team, picked by a reporter who is in it; null for
     *                                          "The team (<lead> decides)" and always for "Not sure"
     * @param list<string> $pinNotes            the notes of the screenshot's pins, in pin order
     * @param list<string> $duplicateKeys       the keys of the possible duplicates the page showed, at most 3
     * @param bool         $aiSaysInfoIsMissing the intake check's missing: true
     * @param bool         $aiSaysRisky         the intake check's risky: true
     */
    public function __construct(
        public OrganizationId $organizationId,
        public UserId $reporter,
        public string $projectId,
        public ?TeamId $teamId,
        public string $title,
        public string $description,
        public string $type,
        public string $priority,
        public ?UserId $assignee,
        public array $pinNotes,
        public \DateTimeImmutable $reportedAt,
        public array $duplicateKeys = [],
        public bool $aiSaysInfoIsMissing = false,
        public bool $aiSaysRisky = false,
    ) {
    }
}
