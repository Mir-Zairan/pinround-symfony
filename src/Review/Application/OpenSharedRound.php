<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\Review\Api\ReviewRoundView;
use App\Review\Domain\HandOffRound;
use App\Review\Domain\RoundKind;
use App\SharedKernel\Domain\UserId;

/**
 * An open hand-off round of a ticket (Round::isOpenSharedRound), with only what the round bar names and what decides
 * who closes it: no screenshot, pin or verdict. It answers with the Round aggregate's own rule (HandOffRound), so the
 * pages that show it agree with the move that closes it.
 */
final readonly class OpenSharedRound
{
    private const string OPEN = 'open';

    /**
     * @param int    $number          from 1; a shared round always has one
     * @param string $kind            review or draft, as RoundView's kind
     * @param UserId $ownerId         who shared it
     * @param bool   $isSharedInAMove whether its owner shared it in a move, at hand-off, rather than with "Share now"
     */
    public function __construct(
        public int $number,
        public string $kind,
        public UserId $ownerId,
        public bool $isSharedInAMove,
    ) {
    }

    /**
     * The round page's round as an open hand-off round: a shared review or draft round after the report that is still
     * open. Null for Round 0, the checklist round and a closed round, which no move closes.
     */
    public static function ofReviewRound(ReviewRoundView $round): ?self
    {
        $isHandOffKind = \in_array(RoundKind::tryFrom($round->kind), [RoundKind::Review, RoundKind::Draft], true);
        if ($round->number < 1 || self::OPEN !== $round->state || !$isHandOffKind) {
            return null;
        }

        return new self($round->number, $round->kind, $round->ownerId, $round->isSharedInAMove);
    }

    /**
     * See HandOffRound::isClosedByMoveOf.
     *
     * @param UserId|null $assigneeId the ticket's assignee; null when nobody has it
     */
    public function isClosedByMoveOf(UserId $moverId, bool $isMoversTurn, ?UserId $assigneeId): bool
    {
        return $this->handOffRound()->isClosedByMoveOf($moverId, $isMoversTurn, $assigneeId);
    }

    /**
     * See HandOffRound::findingsReadyFor.
     *
     * @param UserId|null $assigneeId the ticket's assignee; null when nobody has it
     */
    public function findingsReadyFor(?UserId $assigneeId): ?UserId
    {
        return $this->handOffRound()->findingsReadyFor($assigneeId);
    }

    /**
     * See HandOffRound::notReadyForUserId.
     */
    public function notReadyForUserId(): ?UserId
    {
        return $this->handOffRound()->notReadyForUserId();
    }

    private function handOffRound(): HandOffRound
    {
        return new HandOffRound(RoundKind::from($this->kind), $this->ownerId, $this->isSharedInAMove);
    }
}
