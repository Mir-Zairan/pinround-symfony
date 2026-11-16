<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\Review\Api\RoundCommands;
use App\Review\Api\RoundRefused;
use App\Review\Domain\DraftDecision;
use App\Review\Domain\RoundChanged;
use App\Review\Domain\RoundId;
use App\Review\Domain\Rounds;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\ShareDraftQuestion;
use App\Ticketing\Api\TicketChangedMeanwhile;
use App\Ticketing\Api\UndecidedDraft;

/**
 * Ticketing's ShareDraftQuestion on the Round aggregate; the contract is on {@see ShareDraftQuestion}. Sharing goes
 * through RoundCommands::shareDraft, so the move's share takes its number as the draft page's does.
 */
final readonly class RoundShareDraftQuestion implements ShareDraftQuestion
{
    public function __construct(private Rounds $rounds, private RoundCommands $roundCommands)
    {
    }

    public function undecidedDraftOf(TicketId $ticketId, UserId $ownerId): ?UndecidedDraft
    {
        $draft = $this->rounds->openUnsharedDraftOf($ticketId, $ownerId);
        if (null === $draft || null !== $draft->decision() || [] === $draft->screenshots()) {
            return null;
        }

        return new UndecidedDraft($draft->id()->toString(), \count($draft->screenshots()), $this->rounds->nextFreeNumberOf($ticketId));
    }

    public function share(UndecidedDraft $draft, UserId $ownerId, \DateTimeImmutable $at): int
    {
        try {
            $number = $this->roundCommands->shareDraft($draft->roundId, $ownerId, $at);
        } catch (RoundRefused $refused) {
            throw new TicketChangedMeanwhile('The draft round was changed after it was read.', 0, $refused);
        }
        $this->decide($draft, $ownerId, DraftDecision::Shared, $at);

        return $number;
    }

    public function keepPrivate(UndecidedDraft $draft, UserId $ownerId, \DateTimeImmutable $at): void
    {
        $this->decide($draft, $ownerId, DraftDecision::KeptPrivate, $at);
    }

    /**
     * Reads the draft again, as shareDraft has saved it past the version a read before it holds.
     *
     * @throws TicketChangedMeanwhile when the draft is gone, decided, deleted, of another kind or owner, or not as
     *                                shared as the decision needs, or another request saved it first; nothing is saved
     */
    private function decide(UndecidedDraft $draft, UserId $ownerId, DraftDecision $decision, \DateTimeImmutable $at): void
    {
        $round = $this->rounds->get(new RoundId($draft->roundId)) ?? throw new TicketChangedMeanwhile('The draft round is gone.');
        try {
            $round->decide($ownerId, $decision, $at);
            $this->rounds->save($round, $at);
        } catch (\DomainException|RoundChanged $changed) {
            throw new TicketChangedMeanwhile('The draft round was changed after it was read.', 0, $changed);
        }
    }
}
