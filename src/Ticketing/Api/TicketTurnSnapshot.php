<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * One ticket, its step and its team's people, as {@see TicketTurnQueries::factsOf} reads them. Codes and step ids are
 * strings because this Api may use SharedKernel only.
 */
final readonly class TicketTurnSnapshot
{
    /**
     * @param string       $projectId       the uuid of the ticket's project
     * @param string       $stepId          the uuid of the step the ticket is in
     * @param string|null  $coreCode        the step's core code (backlog, todo, progress, review, test, input, hold or
     *                                      done); null for a company or team step
     * @param string       $groupCode       one of todo, doing, check, wait, done
     * @param TeamId|null  $teamId          null for a ticket no team owns
     * @param UserId|null  $leadId          the lead of the ticket's team; null exactly when $teamId is null
     * @param string|null  $leadDisplayName the lead's name, for example "Lena"; null exactly when $leadId is null
     * @param list<UserId> $developerIds    the team's developers, in user id order; the lead may be among them
     * @param list<UserId> $testerIds       the team's testers, in user id order
     * @param string       $visibility      all, or team for a restricted ticket
     * @param string       $priority        one of blocker, critical, major, normal, minor
     * @param bool         $isRiskCheck     true while the ticket's check reasons include "risk"
     *
     * @throws \InvalidArgumentException when the lead does not match the team, or the visibility is not all or team
     */
    public function __construct(
        public TicketId $ticketId,
        public string $projectId,
        public string $stepId,
        public ?string $coreCode,
        public string $groupCode,
        public ?TeamId $teamId,
        public ?UserId $leadId,
        public ?string $leadDisplayName,
        public array $developerIds,
        public array $testerIds,
        public string $visibility,
        public string $priority,
        public ?string $release,
        public UserId $reporterId,
        public ?UserId $assigneeId = null,
        public ?UserId $pendingAssigneeId = null,
        public ?UserId $reviewerId = null,
        public ?UserId $testerId = null,
        public ?UserId $waitingOnUserId = null,
        public ?string $waitingOnLabel = null,
        public ?string $previousStepId = null,
        public bool $isRiskCheck = false,
    ) {
        if ((null === $teamId) !== (null === $leadId) || (null === $leadId) !== (null === $leadDisplayName)) {
            throw new \InvalidArgumentException('A ticket has a team lead and their name exactly when it has a team.');
        }
        if (!\in_array($visibility, [TicketVisibilityFacts::FOR_EVERYONE, TicketVisibilityFacts::TEAM_ONLY], true)) {
            throw new \InvalidArgumentException('A ticket visibility must be all or team.');
        }
    }
}
