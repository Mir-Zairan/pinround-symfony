<?php

declare(strict_types=1);

namespace App\Review\UI;

use App\Identity\Api\UserQueries;
use App\Identity\Api\UserView;
use App\Insight\Api\TurnUserQueries;
use App\Review\Application\OpenSharedRound;
use App\Review\UI\Component\RoundBar;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketPageView;

/**
 * Who an open shared round is ready for (ADR-0048), the one person the share refusal of a draft, the ticket's round bar
 * and the round page's header all name: the assignee for their findings round, else whoever's turn it is now, unless
 * their own move would not close the round (Round::notReadyForUserId, the owner of a round shared with "Share now").
 * TicketMoveController names the same person for the hand-off dialog from what Review put in the refusal, as Ticketing's
 * UI may not use Review's (deptrac).
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
     * @return UserId|null null when it is no findings round and the turn is a label's, the ticket has no turn row, or
     *                     the turn is the person the round is not ready for
     */
    public function userIdOf(TicketPageView $ticket, OpenSharedRound $round): ?UserId
    {
        return $this->namedOrTurnUserIdOf($ticket, $round->findingsReadyFor($ticket->turnSnapshot->assigneeId), $round->notReadyForUserId());
    }

    /**
     * For a caller whom Review already told who the round is ready for (RoundRefused::readyForUserId) and who not.
     * Reads ins_ticket_turn once, and only when Review named nobody.
     *
     * @param UserId|null $namedByReview the assignee for their findings round (Round::findingsReadyFor), else null
     * @param UserId|null $notReadyFor   Round::notReadyForUserId: whose own move leaves the round open, so whose turn
     *                                   names nobody
     *
     * @return UserId|null null when Review named nobody and the turn is a label's, the ticket has no turn row, or the
     *                     turn is $notReadyFor's
     */
    public function namedOrTurnUserIdOf(TicketPageView $ticket, ?UserId $namedByReview, ?UserId $notReadyFor): ?UserId
    {
        if (null !== $namedByReview) {
            return $namedByReview;
        }
        $turnUserId = $this->turnUserQueries->turnUsersOf([$ticket->id])[$ticket->id->toString()] ?? null;
        if (null === $turnUserId || (null !== $notReadyFor && $turnUserId->equals($notReadyFor))) {
            return null;
        }

        return $turnUserId;
    }

    /**
     * "Ready for you" for the person it is ready for, "Ready for <first name>" for anyone else. Reads as userIdOf, then
     * as firstNameOf.
     *
     * @return string|null null when nobody is named, or Identity knows no name or an empty one for them
     */
    public function readyForLineOf(VisibleTicket $visible, OpenSharedRound $round): ?string
    {
        return $this->readyForLineOfUser($visible, $this->userIdOf($visible->ticket, $round));
    }

    /**
     * readyForLineOf for a caller who already has userIdOf's answer. Reads as firstNameOf.
     *
     * @param UserId|null $readyForId as userIdOf gives it
     */
    public function readyForLineOfUser(VisibleTicket $visible, ?UserId $readyForId): ?string
    {
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
}
