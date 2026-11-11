<?php

declare(strict_types=1);

namespace App\Insight\Application\Turn;

use App\SharedKernel\Domain\TicketId;

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
     */
    public function factsFor(TicketId $ticketId): ?TicketTurnFacts;
}
