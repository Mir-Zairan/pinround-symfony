<?php

declare(strict_types=1);

namespace App\Review\Application\Checklist;

use App\SharedKernel\Domain\TicketId;

/**
 * The check items of the rounds a page shows, the checklist round's and the findings rounds' alike, for Review's UI.
 * It sits beside ChecklistQueries rather than in it because only the Rounds section and its checklist buttons read more
 * than the ticket's one checklist round.
 */
interface RoundChecklistQueries
{
    /**
     * The items of each given round of the ticket that has any, in one query whatever the number of rounds and items,
     * and no query without a round id. It does not check who asks: the caller must have been granted TicketPermission VIEW
     * on the ticket, and pass rounds the viewer sees.
     *
     * @param list<string> $roundIds lowercase ids of the ticket's rounds, as RoundQueries gave them
     *
     * @return array<string, RoundChecklist> by round id, by round number; a round without items (a review round, a
     *                                       self-check round), another ticket's round and an unknown one are left out
     *
     * @throws \LogicException           when no transaction is open or no tenant is set, before any query
     * @throws \InvalidArgumentException when a round id is malformed, before any query
     */
    public function forRounds(TicketId $ticketId, array $roundIds): array;
}
