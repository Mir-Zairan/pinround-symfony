<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * The rounds of a ticket for the people who may see them (ADR-0013, ADR-0017). It reads under row-level security, so it
 * needs an open transaction whose tenant is the viewer's organization.
 */
interface RoundQueries
{
    /**
     * At most four queries besides the viewer's roles, whatever the number of rounds: Ticketing's visibility check of
     * the ticket, then the rounds, their screenshots and those screenshots' pins, one query each; none of the last
     * three for a ticket the viewer may not see, and none for screenshots or pins that cannot exist.
     *
     * Rounds come by number (Round 0, Round 1, then the rest), then the viewer's own unshared draft, which has none.
     * Another person's unshared draft is left out for everyone, the lead and admins included.
     *
     * @return list<RoundView> none for a ticket the viewer may not see, one of another organization, or an unknown id
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function forTicket(TicketId $ticketId, UserId $viewer): array;
}
