<?php

declare(strict_types=1);

namespace App\SharedKernel\Application;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Auto-clear (FR-PNG-05): acting on a ticket clears the pings that asked the actor to act on it. Ticketing and
 * Conversation call it after their own write; Notification implements it.
 */
interface PingClearing
{
    /**
     * Clears the actor's open pings on the ticket when the organization's auto-clear is on, marks their bell rows done
     * and writes a PingCleared (Acted) for each, all in the caller's open transaction under the organization as tenant,
     * so they commit or roll back with the action. Locks those pings first: a second call at once clears none of them.
     *
     * @return int how many it cleared; 0 when auto-clear is off or the actor has no open ping on the ticket, and then
     *             nothing changed
     *
     * @throws \LogicException when no transaction is open, before any write
     */
    public function clearForActor(OrganizationId $organizationId, TicketId $ticketId, UserId $actorId, \DateTimeImmutable $at): int;
}
