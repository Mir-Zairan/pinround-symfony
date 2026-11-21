<?php

declare(strict_types=1);

namespace App\Review\UI;

use App\Identity\Api\UserQueries;
use App\Identity\Api\UserView;
use App\Insight\Api\TurnUserQueries;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketPageView;

/**
 * Who an open shared round is ready for (ADR-0048), the one person the share refusal of a draft and the ticket's round
 * bar both name: the person Review names (the assignee, for their findings round), else whoever's turn it is now.
 * TicketMoveController names the same person for the hand-off dialog with its own copy of this rule, as Ticketing's UI
 * may not use Review's (deptrac).
 */
final readonly class RoundReadyFor
{
    public function __construct(
        private TurnUserQueries $turnUserQueries,
        private UserQueries $userQueries,
    ) {
    }

    /**
     * Reads ins_ticket_turn once, and only when Review named nobody.
     *
     * @param UserId|null $namedByReview the assignee for their findings round (Round::findingsReadyFor), else null
     *
     * @return UserId|null null when Review named nobody and the turn is a label's or the ticket has no turn row
     */
    public function userIdOf(TicketPageView $ticket, ?UserId $namedByReview): ?UserId
    {
        return $namedByReview ?? $this->turnUserQueries->turnUsersOf([$ticket->id])[$ticket->id->toString()] ?? null;
    }

    /**
     * From the names the ticket's page read, which hold the ticket's people and its team's; one query for anyone else.
     *
     * @return string|null null when Identity knows nobody by that id; empty for an empty name
     */
    public function firstNameOf(UserId $userId, TicketPageView $ticket): ?string
    {
        $displayName = $ticket->displayNames[$userId->toString()] ?? $this->userQueries->byIds([$userId])[$userId->toString()]->displayName ?? null;

        return null === $displayName ? null : new UserView($userId, $displayName)->firstName();
    }
}
