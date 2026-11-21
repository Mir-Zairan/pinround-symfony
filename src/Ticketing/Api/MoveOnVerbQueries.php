<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * The ticket's move-on verbs for the logged-in person, for another module's page that lets the person whose turn it is
 * move the ticket on (the round page, ADR-0048: that move is the open round's verdict). The forms post to the ticket
 * page's own move, so its voter, policy and draft question answer them as on the ticket page.
 */
interface MoveOnVerbQueries
{
    /**
     * Reads under the caller's tenant, inside its open transaction: the saved turn and the waiting turns (one query),
     * and a name the page did not hold (one more, only then).
     *
     * @return MoveOnForm|null null when the logged-in person may not move the ticket (TicketPermission::MOVE), it is
     *                         not their turn, no team owns the ticket, or the policy offers them no move-on verb
     */
    public function moveOnFormOf(TicketPageView $ticket): ?MoveOnForm;
}
