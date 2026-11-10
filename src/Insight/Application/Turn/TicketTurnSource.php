<?php

declare(strict_types=1);

namespace App\Insight\Application\Turn;

use App\SharedKernel\Domain\TicketId;

/**
 * Where the turn projector reads one ticket's facts from; Ticketing's Api provides them (P03).
 */
interface TicketTurnSource
{
    /**
     * @return TicketTurnFacts|null null when the tenant has no ticket with this id, so its turn row goes
     */
    public function factsFor(TicketId $ticketId): ?TicketTurnFacts;
}
