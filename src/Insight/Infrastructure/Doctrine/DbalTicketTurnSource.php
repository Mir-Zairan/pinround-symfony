<?php

declare(strict_types=1);

namespace App\Insight\Infrastructure\Doctrine;

use App\Insight\Api\TeamLoadQueries;
use App\Insight\Application\Turn\TicketTurnFacts;
use App\Insight\Application\Turn\TicketTurnSource;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\Ticketing\Api\TicketTurnQueries;
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

    public function factsFrom(TicketTurnSnapshot $snapshot, ?array $waitingTurns = null): ?TicketTurnFacts
    {
        $this->requireTransaction();
        if (null === $snapshot->leadId || null === $snapshot->leadDisplayName) {
            return $this->noTeamCheckFactsOf($snapshot);
        }

        $team = TurnInputs::teamFacts(
            $snapshot->leadId,
            $snapshot->leadDisplayName,
            $snapshot->developerIds,
            $snapshot->testerIds,
            $waitingTurns ?? $this->teamLoadQueries->waitingTurnsOf([...$snapshot->developerIds, ...$snapshot->testerIds], $snapshot->ticketId),
        );

        // No organization has a current release yet. The quick check and an open question arrive through the snapshot
        // (isCheckNeeded and checkerId, askedUserId), which ticketStateOf passes on.
        return new TicketTurnFacts($snapshot->ticketId, $snapshot->projectId, TurnInputs::ticketStateOf($snapshot), $team, $snapshot->visibility, isRiskCheck: $snapshot->isRiskCheck);
    }

    /**
     * A ticket with no team ("Not sure", P06) waits only on its quick check by the organization's triager (turn rule 2,
     * workflow-engine.md, Whose turn). That rule decides before it reads the team, so the triager stands in as the lead
     * of a team of nobody else; a no-team ticket that needs no check, or has no triager, has no turn row.
     */
    private function noTeamCheckFactsOf(TicketTurnSnapshot $snapshot): ?TicketTurnFacts
    {
        if (null !== $snapshot->teamId || !$snapshot->isCheckNeeded || null === $snapshot->checkerId) {
            return null;
        }
        $team = TurnInputs::teamFacts($snapshot->checkerId, '', [], [], []);

        return new TicketTurnFacts($snapshot->ticketId, $snapshot->projectId, TurnInputs::ticketStateOf($snapshot), $team, $snapshot->visibility, isRiskCheck: $snapshot->isRiskCheck);
    }

    private function requireTransaction(): void
    {
        // Outside a transaction no tenant is set for row-level security, so the loads would silently count zero.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Turn facts for the projector are read in a transaction under the organization as tenant; none is open.');
        }
    }
}
