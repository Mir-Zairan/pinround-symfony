<?php

declare(strict_types=1);

namespace App\Assist\Application\Gateway;

use App\Assist\Application\PromptTicket;
use App\Organization\Api\ActorContext;
use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Api\TicketKeyQueries;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;

/**
 * Reads the ticket as its page does: the viewer's predicate from TicketVisibilities, TicketQueries::pageOf under it, then
 * the predicate again on the page's own facts, the second guard the ticket page keeps too. A worker has no security
 * token for the voter, so the predicate the voter decides VIEW on is asked directly.
 */
final readonly class TicketPageAskedTicketQueries implements AskedTicketQueries
{
    public function __construct(
        private TicketKeyQueries $ticketKeyQueries,
        private TicketVisibilities $ticketVisibilities,
        private TicketQueries $ticketQueries,
    ) {
    }

    public function visibleTicketOf(TicketId $ticketId, ActorContext $viewer): ?AskedTicket
    {
        $key = $this->ticketKeyQueries->keyOf($ticketId);
        if (null === $key) {
            return null;
        }

        $visibility = $this->ticketVisibilities->of($viewer->organizationId(), $viewer->userId());
        $page = $this->ticketQueries->pageOf($key, $visibility);
        if (null === $page || !$visibility->isVisible($page->visibilityFacts)) {
            return null;
        }

        return new AskedTicket(new PromptTicket($page->title, '' === $page->description ? null : $page->description), $page->isTeamOnly());
    }
}
