<?php

declare(strict_types=1);

namespace App\Insight\Application\Turn;

use App\SharedKernel\Domain\TicketId;

/**
 * Where the turn projector reads one ticket's facts from. Insight implements it (P03): an Infrastructure adapter calls a
 * Ticketing Api query that returns SharedKernel-only data and builds TicketState, TeamFacts and TicketTurnFacts itself,
 * as Ticketing's Api may use SharedKernel only. Insight's Infrastructure cannot see Organization's Api, so the team's
 * lead and member loads come through that Ticketing query too, unless a new ADR widens deptrac.
 */
interface TicketTurnSource
{
    /**
     * @return TicketTurnFacts|null null when the tenant has no ticket with this id, so its turn row goes
     */
    public function factsFor(TicketId $ticketId): ?TicketTurnFacts;
}
