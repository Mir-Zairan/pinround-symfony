<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\TicketId;

/**
 * Keeps the ticket's row in ins_ticket_turn, the turn read model of ADR-0015, in step with the ticket. Every command
 * that can change a turn calls it in its own transaction, so Home is right as soon as that transaction commits.
 */
interface TurnProjector
{
    /**
     * Reads the ticket's facts and writes its row with one upsert, or deletes the row when the ticket is done, is
     * nobody's turn or no longer exists. Reads no other ticket. waiting_since is kept while the turn's person or label
     * stays the same and starts now when either changes.
     *
     * @throws \LogicException when no transaction is open: the row must share the fate of the change
     */
    public function refresh(TicketId $ticketId): void;
}
