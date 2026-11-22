<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\TicketId;

/**
 * For demo seeds only: TurnProjector starts a turn's wait at the clock's now, so every seeded ticket would wait for the
 * same few seconds. This sets when a ticket's current turn began, so Whose turn, Home and their meters show the varied
 * waits a real team has. Nothing in the product calls it.
 */
interface DemoTurnWaits
{
    /**
     * Sets waiting_since of the ticket's row in ins_ticket_turn, in the transaction's tenant. A ticket with no row (done,
     * nobody's turn, or another organization's) is left as it is, without an error.
     *
     * @throws \LogicException when no transaction is open, as the row is the tenant's and must change with the seed
     */
    public function backdate(TicketId $ticketId, \DateTimeImmutable $waitingSince): void;
}
