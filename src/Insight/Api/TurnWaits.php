<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\TicketId;

/**
 * When a ticket's turn began, for an Undo: TurnProjector starts a wait anew whenever the turn's person or label changes,
 * so a move and its Undo would restart the wait of the person who gets the turn back. A move reads the wait before it
 * and its Undo puts it back. Both work on ins_ticket_turn of the organization of TenantContext under row-level security.
 */
interface TurnWaits
{
    /**
     * @return TurnWait|null null when the ticket has no row (done, nobody's turn, or another organization's)
     *
     * @throws \LogicException when no transaction is open
     */
    public function waitOf(TicketId $ticketId): ?TurnWait;

    /**
     * Sets the row's waiting_since to $wait's since while its turn is $wait's person or label; a row whose turn is
     * someone else's, or no row, is left as it is, without an error.
     *
     * @throws \LogicException when no transaction is open, as the row must change with the Undo
     */
    public function restore(TicketId $ticketId, TurnWait $wait): void;
}
