<?php

declare(strict_types=1);

namespace App\Review\UI;

use App\Review\Api\RoundView;
use App\Review\Application\RoundsVisibleTo;
use App\Review\UI\Component\RoundBar;
use App\SharedKernel\Domain\UserId;

/**
 * Builds the round bar for one viewer from the rounds they may see, so a private draft never reaches it, not even its
 * owner's, as no unshared draft is a hand-off round. Reads RoundsVisibleTo's queries, then at most one for whose turn it
 * is and one for a name the ticket's page did not read, whatever the number of rounds.
 */
final readonly class RoundBars
{
    private const string OPEN = 'open';
    private const string REVIEW = 'review';

    public function __construct(
        private RoundsVisibleTo $roundsVisibleTo,
        private RoundReadyFor $roundReadyFor,
    ) {
    }

    /**
     * Runs inside the request transaction whose tenant is the viewer's organization.
     *
     * @return RoundBarView|null null while no shared round of the ticket is open
     */
    public function forTicket(VisibleTicket $visible): ?RoundBarView
    {
        $ticket = $visible->ticket;
        $openRound = self::lowestOpenSharedRoundOf($this->roundsVisibleTo->forVisibleTo($ticket->id, $visible->visibility)->rounds);
        if (null === $openRound) {
            return null;
        }
        $readyForId = $this->roundReadyFor->userIdOf($ticket, self::findingsReadyFor($openRound, $ticket->turnSnapshot->assigneeId));

        return new RoundBarView($openRound->number ?? 0, $this->readyForLineOf($readyForId, $visible));
    }

    private function readyForLineOf(?UserId $readyForId, VisibleTicket $visible): ?string
    {
        if (null === $readyForId) {
            return null;
        }
        if ($readyForId->equals($visible->actor->userId())) {
            return RoundBar::READY_FOR_YOU;
        }
        $firstName = $this->roundReadyFor->firstNameOf($readyForId, $visible->ticket);

        return null === $firstName || '' === trim($firstName) ? null : \sprintf(RoundBar::READY_FOR, $firstName);
    }

    /**
     * Round::isOpenSharedRound's rule: an open review round or draft, once shared; Round 0 and the checklist round never are.
     * ADR-0048 lets one be open at a time, yet older data can hold two, and the lower is the one a share waits for.
     * RoundsVisibleTo lists the newest numbered rounds only, so an open round older than all of those is not seen.
     *
     * @param list<RoundView> $rounds
     */
    private static function lowestOpenSharedRoundOf(array $rounds): ?RoundView
    {
        $lowest = null;
        foreach ($rounds as $round) {
            $isSharedRound = \in_array($round->kind, [self::REVIEW, RoundView::DRAFT], true) && null !== $round->sharedAt;
            if ($isSharedRound && self::OPEN === $round->state && null !== $round->number
                && (null === $lowest || $round->number < ($lowest->number ?? \PHP_INT_MAX))) {
                $lowest = $round;
            }
        }

        return $lowest;
    }

    /**
     * Round::findingsReadyFor's rule: a shared draft of anyone but the assignee is their findings, ready for them.
     */
    private static function findingsReadyFor(RoundView $round, ?UserId $assigneeId): ?UserId
    {
        return null !== $assigneeId && RoundView::DRAFT === $round->kind && !$round->ownerId->equals($assigneeId) ? $assigneeId : null;
    }
}
