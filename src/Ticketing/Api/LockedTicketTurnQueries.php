<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;

/**
 * TicketTurnQueries for a module that writes on the ticket's facts, such as who its assignee is, and so must keep them
 * from changing until its transaction ends.
 */
interface LockedTicketTurnQueries extends TicketTurnQueries
{
    /**
     * factsOf's answer, with the ticket's row held (SELECT ... FOR SHARE) until the caller's transaction ends: a move or
     * a give of the ticket waits for the caller's commit, and a read waiting on one that is not committed yet gets the
     * facts it saved. Call it before taking any other module's lock on the ticket's rows: a move holds the ticket's row
     * from its save on and only then takes those, so the other order can deadlock with it.
     *
     * @return TicketTurnSnapshot|null null when the organization has no ticket with this id; nothing is locked then
     *
     * @throws \LogicException as factsOf does
     */
    public function lockedFactsOf(TicketId $ticketId): ?TicketTurnSnapshot;
}
