<?php

declare(strict_types=1);

namespace App\Conversation\Application;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibilityFacts;

/**
 * The one check before a thread write that a person may act on the ticket at all: the ticket belongs to the tenant
 * and the person may see it. Every use case that writes to a ticket's thread from its page's facts runs it first.
 */
final readonly class VisibleTickets
{
    public function __construct(
        private TicketVisibilities $ticketVisibilities,
        private TenantContext $tenantContext,
    ) {
    }

    /**
     * @param TicketVisibilityFacts $ticket the ticket's visibility facts, as the ticket page read them
     *
     * @throws TicketNotFound when no tenant is set, the ticket is of another organization than the tenant, or $personId
     *                        may not see it
     */
    public function refuseUnlessVisibleTo(TicketVisibilityFacts $ticket, UserId $personId): void
    {
        // The visibility predicate trusts the organization it is given: a ticket for everyone of any organization passes it.
        if (true !== $this->tenantContext->get()?->equals($ticket->organizationId)
            || !$this->ticketVisibilities->of($ticket->organizationId, $personId)->isVisible($ticket)) {
            throw new TicketNotFound('That ticket does not exist.');
        }
    }
}
