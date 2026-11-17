<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * One shared round of a ticket for the review round page (FR-RND-05), for the people who may see its ticket. It reads
 * under row-level security, so it needs an open transaction whose tenant is the viewer's organization.
 */
interface ReviewRoundQueries
{
    /**
     * At most four queries besides the viewer's roles, whatever the number of areas and pins: Ticketing's visibility
     * check of the ticket, the round, its areas with both pictures, and the pins of those pictures; none of the last
     * three for a ticket the viewer may not see, no area query for a round with none, and no pin query when no picture
     * has a pin that counts.
     *
     * Only pins that count come with each picture: a person's, or an AI pin a person kept (FR-RND-07, ADR-0020); an AI
     * suggestion and a dismissed one never do.
     *
     * @param int $number the round's number on the ticket, 0 for the report
     *
     * @return ReviewRoundView|null null for a ticket the viewer may not see, an unknown ticket or number, a negative number
     *                              (without a query), and an unshared or deleted draft, which has no number; the caller
     *                              answers it with 404
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function roundOf(TicketId $ticketId, int $number, UserId $viewer): ?ReviewRoundView;
}
