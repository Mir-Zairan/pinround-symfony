<?php

declare(strict_types=1);

namespace App\Review\UI;

use App\Identity\Api\UserQueries;
use App\Identity\Api\UserView;
use App\Insight\Api\TurnUserQueries;
use App\Review\Api\RoundView;
use App\Review\UI\Component\RoundBar;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketPageView;

/**
 * Who an open shared round is ready for (ADR-0048), the one person the share refusal of a draft, the ticket's round bar
 * and the round page's header all name: the assignee for their findings round, else whoever's turn it is now.
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
     * Reads ins_ticket_turn once, and only when the round is no findings round.
     *
     * @param string $roundKind    RoundView's or ReviewRoundView's kind of the open shared round
     * @param UserId $roundOwnerId who shared it
     *
     * @return UserId|null null when it is no findings round and the turn is a label's or the ticket has no turn row
     */
    public function userIdOf(TicketPageView $ticket, string $roundKind, UserId $roundOwnerId): ?UserId
    {
        return $this->namedOrTurnUserIdOf($ticket, self::findingsReadyFor($roundKind, $roundOwnerId, $ticket->turnSnapshot->assigneeId));
    }

    /**
     * For a caller whom Review already told who the round is ready for (RoundRefused::readyForUserId). Reads
     * ins_ticket_turn once, and only when Review named nobody.
     *
     * @param UserId|null $namedByReview the assignee for their findings round (Round::findingsReadyFor), else null
     *
     * @return UserId|null null when Review named nobody and the turn is a label's or the ticket has no turn row
     */
    public function namedOrTurnUserIdOf(TicketPageView $ticket, ?UserId $namedByReview): ?UserId
    {
        return $namedByReview ?? $this->turnUserQueries->turnUsersOf([$ticket->id])[$ticket->id->toString()] ?? null;
    }

    /**
     * "Ready for you" for the person it is ready for, "Ready for <first name>" for anyone else. Reads as userIdOf, then
     * as firstNameOf.
     *
     * @return string|null null when nobody is named, or Identity knows no name or an empty one for them
     */
    public function readyForLineOf(VisibleTicket $visible, string $roundKind, UserId $roundOwnerId): ?string
    {
        $readyForId = $this->userIdOf($visible->ticket, $roundKind, $roundOwnerId);
        if (null === $readyForId) {
            return null;
        }
        if ($readyForId->equals($visible->actor->userId())) {
            return RoundBar::READY_FOR_YOU;
        }
        $firstName = $this->firstNameOf($readyForId, $visible->ticket);

        return null === $firstName || '' === trim($firstName) ? null : \sprintf(RoundBar::READY_FOR, $firstName);
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

    /**
     * Round::findingsReadyFor's rule: a shared draft of anyone but the assignee is their findings, ready for them.
     */
    private static function findingsReadyFor(string $roundKind, UserId $roundOwnerId, ?UserId $assigneeId): ?UserId
    {
        return null !== $assigneeId && RoundView::DRAFT === $roundKind && !$roundOwnerId->equals($assigneeId) ? $assigneeId : null;
    }
}
