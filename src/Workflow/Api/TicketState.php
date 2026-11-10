<?php

declare(strict_types=1);

namespace App\Workflow\Api;

use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;

/**
 * What the workflow needs to know about one ticket to decide its moves; Ticketing fills it. Codes and step ids are
 * strings because other modules may not use Workflow's Domain types.
 */
final readonly class TicketState
{
    /**
     * @param string      $stepId            the uuid of the step the ticket is in
     * @param string|null $coreCode          backlog, todo, progress, review, test, input, hold or done when the step is
     *                                       a core step; null for a company or team step
     * @param string      $groupCode         one of todo, doing, check, wait, done
     * @param TeamId|null $teamId            null for a ticket no team owns yet
     * @param UserId|null $pendingAssigneeId the person the ticket is offered to, until they take it
     * @param string|null $previousStepId    the uuid of the step before the current one, for the "go on" verbs
     * @param string|null $waitingOnLabel    who the ticket waits on when that is not a user, for example a supplier
     * @param UserId|null $askedUserId       the person an open question waits on; null while no question is open
     * @param bool        $isCheckNeeded     true while a new ticket waits for its quick check
     * @param string|null $priority          one of blocker, critical, major, normal, minor
     * @param string|null $release           the release the ticket is planned for
     */
    public function __construct(
        public string $stepId,
        public ?string $coreCode,
        public string $groupCode,
        public ?TeamId $teamId,
        public ?UserId $assigneeId = null,
        public ?UserId $reviewerId = null,
        public ?UserId $testerId = null,
        public ?UserId $askedUserId = null,
        public ?UserId $pendingAssigneeId = null,
        public ?string $previousStepId = null,
        public ?UserId $reporterId = null,
        public ?UserId $waitingOnUserId = null,
        public ?string $waitingOnLabel = null,
        public bool $isCheckNeeded = false,
        public ?UserId $checkerId = null,
        public ?string $priority = null,
        public ?string $release = null,
    ) {
    }
}
