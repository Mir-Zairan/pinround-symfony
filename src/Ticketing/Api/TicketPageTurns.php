<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\UserId;

/**
 * A ticket's Now and Next lines and People section, read as the ticket page reads them, for other modules: the turn
 * Insight saved for Home and Whose turn, or Workflow's turn rules' for a ticket with none, worded by Workflow's Now and
 * Next sentences with first names and no viewer, so they never say "you".
 */
interface TicketPageTurns
{
    /**
     * One query for the saved turn and the waiting turns, the team's rules (cached), and one more for the names the
     * page does not hold, only when one is missing. Needs an open transaction under the organization as tenant.
     *
     * @param list<UserId> $otherPeopleIds people the caller names besides the ticket's own, such as the people of its
     *                                     open questions; their names come in the same one query
     *
     * @throws \LogicException           when no transaction is open or no tenant is set
     * @throws \ValueError               when the saved turn's kind is not one of Workflow's NeedsYouKind
     * @throws \InvalidArgumentException when the ticket's core code is not one of the core steps
     */
    public function of(TicketPageView $page, array $otherPeopleIds = []): TicketPageTurnView;
}
