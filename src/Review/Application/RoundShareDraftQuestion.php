<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\Review\Api\RoundCommands;
use App\Review\Api\RoundRefused;
use App\Review\Domain\DraftDecision;
use App\Review\Domain\DraftFacts;
use App\Review\Domain\Round;
use App\Review\Domain\RoundChanged;
use App\Review\Domain\RoundId;
use App\Review\Domain\Rounds;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\MoveFacts;
use App\Ticketing\Api\MoveRefused;
use App\Ticketing\Api\ShareDraftQuestion;
use App\Ticketing\Api\TicketChangedMeanwhile;
use App\Ticketing\Api\UndecidedDraft;

/**
 * Ticketing's ShareDraftQuestion on the Round aggregate; the contract is on {@see ShareDraftQuestion}. Sharing goes
 * through RoundCommands::shareDraftAtHandOff, so the move's share takes its number as the draft page's does, and a
 * move closes rounds through LockedRoundClosing, as that share closes a findings round.
 */
final readonly class RoundShareDraftQuestion implements ShareDraftQuestion
{
    public function __construct(private Rounds $rounds, private RoundCommands $roundCommands, private LockedRoundClosing $lockedRoundClosing)
    {
    }

    public function undecidedDraftOf(TicketId $ticketId, MoveFacts $move): ?UndecidedDraft
    {
        $draft = $this->undecidedDraftFactsOf($ticketId, $move->moverId);
        if (null === $draft) {
            return null;
        }
        $roundStillOpen = $this->roundStillOpenAfter($ticketId, $move);

        return new UndecidedDraft(
            $draft->id->toString(),
            $draft->screenshotCount,
            $this->rounds->nextFreeNumberOf($ticketId),
            $roundStillOpen?->number(),
            $roundStillOpen?->findingsReadyFor($move->assigneeId),
        );
    }

    public function undecidedDraftIdOf(TicketId $ticketId, UserId $ownerId): ?string
    {
        return $this->undecidedDraftFactsOf($ticketId, $ownerId)?->id->toString();
    }

    public function closeRoundsOnMove(TicketId $ticketId, MoveFacts $move, \DateTimeImmutable $at): void
    {
        if (!self::mayCloseARound($move)) {
            return;
        }
        foreach ($this->rounds->openSharedRoundsOf($ticketId) as $openRound) {
            if ($openRound->isClosedByMoveOf($move->moverId, $move->isMoversTurn, $move->assigneeId)) {
                $this->lockedRoundClosing->closeLockedRound($openRound, $at);
            }
        }
    }

    public function share(string $roundId, UserId $ownerId, \DateTimeImmutable $at): int
    {
        try {
            return $this->roundCommands->shareDraftAtHandOff($roundId, $ownerId, $at);
        } catch (RoundRefused $refused) {
            $openRoundNumber = $refused->openRoundNumber();
            if (null !== $openRoundNumber) {
                throw MoveRefused::roundStillOpen($refused->getMessage(), $openRoundNumber, $refused->readyForUserId(), $refused);
            }
            if ($refused->isSharingBlocked()) {
                throw new MoveRefused($refused->getMessage(), [], $refused);
            }

            throw new TicketChangedMeanwhile('The draft round was changed after it was read.', 0, $refused);
        }
    }

    public function keepPrivate(string $roundId, UserId $ownerId, \DateTimeImmutable $at): void
    {
        try {
            $round = $this->rounds->get(new RoundId($roundId));
        } catch (\InvalidArgumentException $malformed) {
            throw new TicketChangedMeanwhile('The draft round is gone.', 0, $malformed);
        }
        if (null === $round) {
            throw new TicketChangedMeanwhile('The draft round is gone.');
        }
        try {
            $round->decide($ownerId, DraftDecision::KeptPrivate, $at);
            $this->rounds->save($round, $at);
        } catch (\DomainException|RoundChanged $changed) {
            throw new TicketChangedMeanwhile('The draft round was changed after it was read.', 0, $changed);
        }
    }

    private function undecidedDraftFactsOf(TicketId $ticketId, UserId $ownerId): ?DraftFacts
    {
        $draft = $this->rounds->openUnsharedDraftFactsOf($ticketId, $ownerId);

        return null === $draft || null !== $draft->decision || 0 === $draft->screenshotCount ? null : $draft;
    }

    /**
     * The lowest-numbered open shared round that neither this move nor the mover's share in it closes, read under
     * Rounds::openSharedRoundsOf's locks, so the answer holds until the move's transaction ends.
     */
    private function roundStillOpenAfter(TicketId $ticketId, MoveFacts $move): ?Round
    {
        $mayCloseARound = self::mayCloseARound($move);
        foreach ($this->rounds->openSharedRoundsOf($ticketId) as $openRound) {
            $isClosedByTheMove = $mayCloseARound && $openRound->isClosedByMoveOf($move->moverId, $move->isMoversTurn, $move->assigneeId);
            if (!$isClosedByTheMove && !$openRound->isClosedByShareOf($move->moverId, $move->assigneeId)) {
                return $openRound;
            }
        }

        return null;
    }

    /**
     * A lead-only move closes nothing, and so does a move by someone whose turn it was not and who is not the assignee;
     * neither needs to read the rounds.
     */
    private static function mayCloseARound(MoveFacts $move): bool
    {
        if ($move->isLeadOnlyMove) {
            return false;
        }

        return $move->isMoversTurn || (null !== $move->assigneeId && $move->moverId->equals($move->assigneeId));
    }
}
