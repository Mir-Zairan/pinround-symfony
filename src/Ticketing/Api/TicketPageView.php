<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;

/**
 * One ticket as its page shows it (ticket-page.md): the header, The ask and Details, with the facts TicketPermission
 * VIEW is decided on. Codes are strings because other modules may not use Ticketing's or Workflow's Domain enums.
 */
final readonly class TicketPageView
{
    /**
     * @param string                $type            bug, story or task
     * @param string                $priority        blocker, critical, major, normal or minor
     * @param string|null           $release         null when the ticket has none
     * @param string|null           $parentKey       null when the ticket has no parent or the viewer may not see it
     * @param list<string>          $components      in the order the ticket holds them
     * @param string                $groupCode       one of todo, doing, check, wait, done
     * @param string                $groupLabel      the group's name as people read it, for example "Checking"
     * @param string|null           $reporterName    null when no user has the reporter's id any more
     * @param string|null           $assigneeName    null when nobody is assigned, or no user has the assignee's id any more
     * @param TicketVisibilityFacts $visibilityFacts what TicketPermission VIEW is decided on; the page is shown only
     *                                               when it is granted
     * @param TicketTurnSnapshot    $turnSnapshot    the ticket's turn facts, read with the page so the turn needs no second
     *                                               read of the ticket
     * @param array<string, string> $displayNames    display names by user id string of the ticket's people (reporter,
     *                                               assignee, reviewer, tester, pending assignee, waiting-on) and of its
     *                                               team's lead, developers and testers; a person Identity does not know
     *                                               is left out
     */
    public function __construct(
        public TicketId $id,
        public string $key,
        public string $title,
        public string $description,
        public string $type,
        public string $priority,
        public ?string $release,
        public ?string $parentKey,
        public array $components,
        public \DateTimeImmutable $createdAt,
        public string $projectName,
        public string $stepName,
        public string $groupCode,
        public string $groupLabel,
        public ?string $reporterName,
        public ?string $assigneeName,
        public TicketVisibilityFacts $visibilityFacts,
        public TicketTurnSnapshot $turnSnapshot,
        public array $displayNames,
    ) {
    }

    public function isTeamOnly(): bool
    {
        return TicketVisibilityFacts::TEAM_ONLY === $this->visibilityFacts->visibility;
    }
}
