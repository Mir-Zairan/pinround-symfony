<?php

declare(strict_types=1);

namespace App\Insight\Application\Turn;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;

/**
 * Where the turn projector reads one ticket's facts from. Insight implements it (Infrastructure\Doctrine\
 * DbalTicketTurnSource): it calls Ticketing's TicketTurnQueries, which returns SharedKernel-only data, the team's lead
 * and people included, as Insight cannot see Organization's or Identity's Api; it builds TicketState, TeamFacts and
 * TicketTurnFacts itself and counts the member loads in its own ins_ticket_turn.
 */
interface TicketTurnSource
{
    /**
     * @return TicketTurnFacts|null null when the tenant has no ticket with this id, so its turn row goes
     *
     * @throws \LogicException when no transaction is open, before any query
     */
    public function factsFor(TicketId $ticketId): ?TicketTurnFacts;

    /**
     * The facts of a ticket its caller has already read, as {@see factsFor} would build them from the same snapshot;
     * reads no ticket, only the team's waiting reviews and tests, and those only when the caller gives none.
     *
     * @param array<string, array<string, int>>|null $waitingTurns as TurnProjector::refreshFrom takes them
     *
     * @return TicketTurnFacts|null null for a ticket no team owns, so its turn row goes
     *
     * @throws \LogicException when no transaction is open, before any query
     */
    public function factsFrom(TicketTurnSnapshot $snapshot, ?array $waitingTurns = null): ?TicketTurnFacts;
}
