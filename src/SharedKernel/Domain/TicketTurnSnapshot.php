<?php

declare(strict_types=1);

namespace App\SharedKernel\Domain;

/**
 * One ticket, its step and its team's people, as Ticketing's TicketTurnQueries::factsOf reads them and Insight's
 * TurnProjector::refreshFrom takes them. It lives in SharedKernel because both modules' Apis name it and an Api may use
 * SharedKernel only; for the same reason codes and step ids are strings.
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
     * @param UserId|null  $askedUserId     who must answer the question that pauses the ticket; null while none does
     * @param UserId|null  $askerId         who asked that question; null exactly when $askedUserId is null
     *
     * @throws \InvalidArgumentException when the lead does not match the team, the asker does not match the asked
     *                                   person, or the visibility is not all or team
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
        public ?UserId $askedUserId = null,
        public ?UserId $askerId = null,
    ) {
        if ((null === $teamId) !== (null === $leadId) || (null === $leadId) !== (null === $leadDisplayName)) {
            throw new \InvalidArgumentException('A ticket has a team lead and their name exactly when it has a team.');
        }
        if ((null === $askedUserId) !== (null === $askerId)) {
            throw new \InvalidArgumentException('A paused ticket has both the asked person and the asker.');
        }
        // The values of Ticketing's TicketVisibilityFacts::FOR_EVERYONE and TEAM_ONLY, which SharedKernel cannot see.
        if (!\in_array($visibility, ['all', 'team'], true)) {
            throw new \InvalidArgumentException('A ticket visibility must be all or team.');
        }
    }

    /**
     * Whom a question on this ticket may ask: its assignee, reporter, reviewer, tester and team lead, in that order,
     * each once. The team's other members, a pending assignee and whoever the ticket waits on are not among them.
     *
     * @return list<UserId>
     */
    public function peopleOnTheTicket(): array
    {
        $people = [];
        foreach ([$this->assigneeId, $this->reporterId, $this->reviewerId, $this->testerId, $this->leadId] as $listedId) {
            if (null !== $listedId) {
                $people[$listedId->toString()] ??= $listedId;
            }
        }

        return array_values($people);
    }
}
