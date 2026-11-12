<?php

declare(strict_types=1);

namespace App\Workflow\Api;

use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;

/**
 * Makes the two inputs of {@see TurnRules} and {@see NowNext}, TicketState and TeamFacts, from SharedKernel values and
 * strings, so every module that reads a ticket's turn builds them the same way: Insight's turn projector and the ticket
 * page. Pure; it holds no state.
 */
final class TurnInputs
{
    private function __construct()
    {
    }

    /**
     * @param string|null $coreCode       the step's core code; null for a company or team step
     * @param string      $groupCode      one of todo, doing, check, wait, done
     * @param string|null $waitingOnLabel who the ticket waits on when that is not a user
     * @param string|null $priority       one of blocker, critical, major, normal, minor
     */
    public static function ticketState(
        string $stepId,
        ?string $coreCode,
        string $groupCode,
        ?TeamId $teamId,
        ?UserId $assigneeId,
        ?UserId $reviewerId,
        ?UserId $testerId,
        ?UserId $pendingAssigneeId,
        ?string $previousStepId,
        ?UserId $reporterId,
        ?UserId $waitingOnUserId,
        ?string $waitingOnLabel,
        ?string $priority,
        ?string $release,
    ): TicketState {
        return new TicketState(
            $stepId,
            $coreCode,
            $groupCode,
            $teamId,
            assigneeId: $assigneeId,
            reviewerId: $reviewerId,
            testerId: $testerId,
            pendingAssigneeId: $pendingAssigneeId,
            previousStepId: $previousStepId,
            reporterId: $reporterId,
            waitingOnUserId: $waitingOnUserId,
            waitingOnLabel: $waitingOnLabel,
            priority: $priority,
            release: $release,
        );
    }

    /**
     * @param list<UserId>                      $developerIds the team's developers; the lead may be among them
     * @param list<UserId>                      $testerIds    the team's testers
     * @param array<string, array<string, int>> $waitingTurns review and test turns waiting, by user id string and kind
     *                                                        ("review", "test"); a person or kind left out counts 0
     */
    public static function teamFacts(UserId $leadId, string $leadDisplayName, array $developerIds, array $testerIds, array $waitingTurns): TeamFacts
    {
        return new TeamFacts(
            $leadId,
            $leadDisplayName,
            array_map(static fn (UserId $developerId): TeamMemberLoad => new TeamMemberLoad($developerId, openReviews: $waitingTurns[$developerId->toString()]['review'] ?? 0), $developerIds),
            array_map(static fn (UserId $testerId): TeamMemberLoad => new TeamMemberLoad($testerId, ticketsInTesting: $waitingTurns[$testerId->toString()]['test'] ?? 0), $testerIds),
        );
    }
}
