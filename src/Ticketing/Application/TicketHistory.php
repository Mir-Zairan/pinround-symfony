<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * What happened to a ticket, one row of tkt_ticket_event per change, for its activity. Rows are only ever added.
 */
interface TicketHistory
{
    /**
     * Adds the "created" row in the caller's transaction; the ticket must be saved before, as the row points at it.
     *
     * @param string $stepId the wf_step id the ticket starts in
     *
     * @throws \LogicException when no transaction is open
     */
    public function recordCreated(OrganizationId $organizationId, TicketId $ticketId, UserId $reporter, string $stepId, \DateTimeImmutable $at): void;
}
