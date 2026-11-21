<?php

declare(strict_types=1);

namespace App\Review\Domain;

use App\SharedKernel\Domain\UserId;

/**
 * The ADR-0048 rule of one shared round: whose move on closes it while it is open, and whom it is findings for. Round
 * asks it, and so do the reads behind the round bar, the round page's header and its move-on buttons, so a page never
 * offers a move that leaves the round open.
 */
final readonly class HandOffRound
{
    /**
     * @param bool $isSharedInAMove whether the owner shared it in a move, at hand-off (DraftDecision::Shared); a draft
     *                              shared with "Share now" and a review round were shared outside a move
     */
    public function __construct(
        private RoundKind $kind,
        private UserId $ownerId,
        private bool $isSharedInAMove,
    ) {
    }

    /**
     * A shared draft of anyone but the assignee (ADR-0044), as FindingsChecklist has it.
     */
    public function isFindingsFor(UserId $assigneeId): bool
    {
        return RoundKind::Draft === $this->kind && !$this->ownerId->equals($assigneeId);
    }

    /**
     * The assignee when this is their findings round, which is ready for them; null for any other round, which is
     * ready for whoever's turn it is unless that is notReadyForUserId, a person Review does not know.
     *
     * @param UserId|null $assigneeId the ticket's assignee; null when nobody has it
     */
    public function findingsReadyFor(?UserId $assigneeId): ?UserId
    {
        return null !== $assigneeId && $this->isFindingsFor($assigneeId) ? $assigneeId : null;
    }

    /**
     * The owner of a round shared outside a move: their own move never closes it, so it is not ready for them even on
     * their turn; it waits for the next person whose turn it is. Null for a round shared in a move.
     */
    public function notReadyForUserId(): ?UserId
    {
        return $this->isSharedInAMove ? null : $this->ownerId;
    }

    /**
     * Whether a move-on verb by $moverId closes the round while it is open: on the mover's turn, unless they are
     * notReadyForUserId, and the assignee's move over their findings round. A lead-only move closes nothing, which is
     * the caller's to know.
     *
     * @param bool        $isMoversTurn whether the ticket was $moverId's turn when they moved it
     * @param UserId|null $assigneeId   the ticket's assignee; null when nobody has it
     */
    public function isClosedByMoveOf(UserId $moverId, bool $isMoversTurn, ?UserId $assigneeId): bool
    {
        $notReadyForUserId = $this->notReadyForUserId();
        if (null !== $notReadyForUserId && $notReadyForUserId->equals($moverId)) {
            return false;
        }

        return $isMoversTurn || (null !== $assigneeId && $moverId->equals($assigneeId) && $this->isFindingsFor($assigneeId));
    }
}
