<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;

/**
 * One ticket as Insight's turn projector needs it, for other modules. It reads the organization of TenantContext under
 * row-level security, so it needs an open transaction whose tenant is that organization; under any other tenant it
 * finds nothing.
 */
interface TicketTurnQueries
{
    /**
     * A fixed number of queries whatever the team's size: the ticket, then its step from the team's cached steps. The
     * team's lead, the lead's name and its developers and testers come from Organization's team people cache: no
     * query when cached, two on a miss (the team's people, their names). A person Identity does not know is left out.
     * A ticket with no team gets no lead, no people and, as only a team's steps say it, no core code.
     *
     * The cache is shared, so the people can be stale. SetTeamRole clears the team's entry right after its commit, in
     * the same request; but a reader that read the old rows just before that commit and saved them just after the
     * clear keeps them cached until the relayed TeamRoleChanged is handled in worker-notify (the outbox relay, then
     * worker-notify: under a second normally, longer while either is behind or down), as does an entry the clear
     * failed to drop. A renamed lead's name lags until the relayed UserRenamed is handled. A turn row saved from such
     * an answer stays until the ticket's next refresh, as nothing refreshes a team's turn rows on TeamRoleChanged.
     *
     * @return TicketTurnSnapshot|null null when the organization has no ticket with this id
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query; when the ticket's
     *                         step is not the organization's; when its team has no lead or member Identity knows or
     *                         is not the organization's; or when Identity does not know the team's lead
     */
    public function factsOf(TicketId $ticketId): ?TicketTurnSnapshot;
}
