<?php

declare(strict_types=1);

namespace App\Insight\Infrastructure\Doctrine;

use App\Insight\Application\Turn\TicketTurnFacts;
use App\Insight\Application\Turn\TicketTurnSource;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketTurnSnapshot;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\TurnInputs;
use Doctrine\DBAL\Connection;

/**
 * The ticket, its step and its team's people come from Ticketing's {@see TicketTurnQueries}; how many reviews and tests
 * wait for each developer and tester comes from {@see DbalTeamLoadQueries}, in one query for the whole team. A ticket no team owns
 * gets no facts, so no turn row: the turn rules choose among a team's people and fall back to its lead.
 */
final readonly class DbalTicketTurnSource implements TicketTurnSource
{
    private DbalTeamLoadQueries $teamLoadQueries;

    public function __construct(
        private TicketTurnQueries $ticketTurnQueries,
        private Connection $connection,
        TenantContext $tenantContext,
    ) {
        $this->teamLoadQueries = new DbalTeamLoadQueries($connection, $tenantContext);
    }

    public function factsFor(TicketId $ticketId): ?TicketTurnFacts
    {
        // Outside a transaction no tenant is set for row-level security, so the loads would silently count zero.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Turn facts for the projector are read in a transaction under the organization as tenant; none is open.');
        }

        $snapshot = $this->ticketTurnQueries->factsOf($ticketId);
        if (null === $snapshot || null === $snapshot->leadId || null === $snapshot->leadDisplayName) {
            return null;
        }

        $team = TurnInputs::teamFacts(
            $snapshot->leadId,
            $snapshot->leadDisplayName,
            $snapshot->developerIds,
            $snapshot->testerIds,
            $this->teamLoadQueries->waitingTurnsOf([...$snapshot->developerIds, ...$snapshot->testerIds], $ticketId),
        );

        // No organization has a current release yet, and the quick check and questions come later in P03.
        return new TicketTurnFacts($ticketId, $snapshot->projectId, $this->ticketStateOf($snapshot), $team, $snapshot->visibility, isRiskCheck: $snapshot->isRiskCheck);
    }

    private function ticketStateOf(TicketTurnSnapshot $snapshot): TicketState
    {
        return TurnInputs::ticketState(
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
    }
}
