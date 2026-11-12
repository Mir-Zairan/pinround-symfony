<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Identity\Api\UserQueries;
use App\Insight\Api\TeamLoadQueries;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketTurnQueries;
use App\Workflow\Api\NowNext;
use App\Workflow\Api\NowNextContext;
use App\Workflow\Api\RuleQueries;
use App\Workflow\Api\TurnInputs;
use App\Workflow\Api\TurnRules;

/**
 * The "Now" and "Next" lines under a ticket's title (ticket-page.md, Header). The turn and the sentences are Workflow's
 * ({@see TurnRules}, {@see NowNext}); this reads what they need from the other modules' Apis: the ticket and its team
 * from Ticketing, the reviews and tests waiting for each person from Insight, the first names from Identity and the
 * team's rule from Workflow. Seven queries when Workflow's rules are cached: Ticketing's four, the waiting turns, the
 * names and the rules' step policy. Needs an open transaction under the organization as tenant.
 */
final readonly class NowNextSentences
{
    public function __construct(
        private TicketTurnQueries $ticketTurnQueries,
        private TeamLoadQueries $teamLoadQueries,
        private UserQueries $userQueries,
        private RuleQueries $ruleQueries,
        private TurnRules $turnRules,
        private NowNext $nowNext,
    ) {
    }

    /**
     * @param string $stepName the name of the ticket's current step, as the page shows it
     *
     * @return array{now: string, next: string|null}|null null when the ticket has no team, or the tenant no such ticket:
     *                                                    no turn is chosen for it, so the page shows no lines; next is
     *                                                    null when nothing comes next, as in Done
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function of(TicketId $ticketId, string $stepName, ?UserId $viewerId): ?array
    {
        $snapshot = $this->ticketTurnQueries->factsOf($ticketId);
        if (null === $snapshot || null === $snapshot->teamId || null === $snapshot->leadId || null === $snapshot->leadDisplayName) {
            return null;
        }

        $ticket = TurnInputs::ticketState(
            $snapshot->stepId,
            $snapshot->coreCode,
            $snapshot->groupCode,
            $snapshot->teamId,
            $snapshot->assigneeId,
            $snapshot->reviewerId,
            $snapshot->testerId,
            $snapshot->pendingAssigneeId,
            $snapshot->previousStepId,
            $snapshot->reporterId,
            $snapshot->waitingOnUserId,
            $snapshot->waitingOnLabel,
            $snapshot->priority,
            $snapshot->release,
        );
        $team = TurnInputs::teamFacts(
            $snapshot->leadId,
            $snapshot->leadDisplayName,
            $snapshot->developerIds,
            $snapshot->testerIds,
            $this->teamLoadQueries->waitingTurnsOf([...$snapshot->developerIds, ...$snapshot->testerIds], $ticketId),
        );
        $turn = $this->turnRules->turnFor($ticket, $team);
        $context = new NowNextContext(
            $stepName,
            $this->displayNamesOf($snapshot->leadId, $snapshot->leadDisplayName, [
                ...$snapshot->developerIds, ...$snapshot->testerIds, $turn->userId, $snapshot->assigneeId, $snapshot->pendingAssigneeId,
                $snapshot->reviewerId, $snapshot->testerId,
            ]),
            $team,
            $this->ruleQueries->rulesFor($snapshot->teamId)->reviewBeforeTest,
            $viewerId,
        );

        return [
            'now' => $this->nowNext->now($ticket, $turn, $context),
            'next' => $this->nowNext->next($ticket, $turn, $context),
        ];
    }

    /**
     * @param list<UserId|null> $userIds
     *
     * @return array<string, string> display names by user id string; the lead's is already known
     */
    private function displayNamesOf(UserId $leadId, string $leadDisplayName, array $userIds): array
    {
        $toRead = [];
        foreach ($userIds as $userId) {
            if (null !== $userId && !$userId->equals($leadId)) {
                $toRead[$userId->toString()] = $userId;
            }
        }

        $names = [$leadId->toString() => $leadDisplayName];
        foreach ($this->userQueries->byIds(array_values($toRead)) as $userKey => $user) {
            $names[$userKey] = $user->displayName;
        }

        return $names;
    }
}
