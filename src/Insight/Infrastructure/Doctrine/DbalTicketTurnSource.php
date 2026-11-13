<?php

declare(strict_types=1);

namespace App\Insight\Infrastructure\Doctrine;

use App\Insight\Api\TeamLoadQueries;
use App\Insight\Application\Turn\TicketTurnFacts;
use App\Insight\Application\Turn\TicketTurnSource;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\Ticketing\Api\TicketTurnQueries;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\TurnInputs;
use Doctrine\DBAL\Connection;

/**
 * The ticket, its step and its team's people come from Ticketing's {@see TicketTurnQueries}; how many reviews and tests
 * wait for each developer and tester comes from {@see TeamLoadQueries}, in one query for the whole team. A ticket no team owns
 * gets no facts, so no turn row: the turn rules choose among a team's people and fall back to its lead.
 */
final readonly class DbalTicketTurnSource implements TicketTurnSource
{
    public function __construct(
        private TicketTurnQueries $ticketTurnQueries,
        private TeamLoadQueries $teamLoadQueries,
        private Connection $connection,
    ) {
    }

    public function factsFor(TicketId $ticketId): ?TicketTurnFacts
    {
        $this->requireTransaction();
        $snapshot = $this->ticketTurnQueries->factsOf($ticketId);

        return null === $snapshot ? null : $this->factsFrom($snapshot);
    }

    public function factsFrom(TicketTurnSnapshot $snapshot): ?TicketTurnFacts
    {
        $this->requireTransaction();
        if (null === $snapshot->leadId || null === $snapshot->leadDisplayName) {
            return null;
        }

        $team = TurnInputs::teamFacts(
            $snapshot->leadId,
            $snapshot->leadDisplayName,
            $snapshot->developerIds,
            $snapshot->testerIds,
            $this->teamLoadQueries->waitingTurnsOf([...$snapshot->developerIds, ...$snapshot->testerIds], $snapshot->ticketId),
        );

        // No organization has a current release yet, and the quick check and questions come later in P03.
        return new TicketTurnFacts($snapshot->ticketId, $snapshot->projectId, $this->ticketStateOf($snapshot), $team, $snapshot->visibility, isRiskCheck: $snapshot->isRiskCheck);
    }

    private function requireTransaction(): void
    {
        // Outside a transaction no tenant is set for row-level security, so the loads would silently count zero.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Turn facts for the projector are read in a transaction under the organization as tenant; none is open.');
        }
    }

    private function ticketStateOf(TicketTurnSnapshot $snapshot): TicketState
    {
        return TurnInputs::ticketState(
            stepId: $snapshot->stepId,
            coreCode: $snapshot->coreCode,
            groupCode: $snapshot->groupCode,
            teamId: $snapshot->teamId,
            assigneeId: $snapshot->assigneeId,
            reviewerId: $snapshot->reviewerId,
            testerId: $snapshot->testerId,
            pendingAssigneeId: $snapshot->pendingAssigneeId,
            previousStepId: $snapshot->previousStepId,
            reporterId: $snapshot->reporterId,
            waitingOnUserId: $snapshot->waitingOnUserId,
            waitingOnLabel: $snapshot->waitingOnLabel,
            priority: $snapshot->priority,
            release: $snapshot->release,
        );
    }
}
