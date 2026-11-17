<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\Review\Api\RoundCommands;
use App\Review\Api\RoundRefused;
use App\Review\Domain\DraftDecision;
use App\Review\Domain\DraftFacts;
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
 * through RoundCommands::shareDraftAtHandOff, so the move's share takes its number as the draft page's does.
 */
final readonly class RoundShareDraftQuestion implements ShareDraftQuestion
{
    public function __construct(private Rounds $rounds, private RoundCommands $roundCommands)
    {
    }

    public function undecidedDraftOf(TicketId $ticketId, UserId $ownerId): ?UndecidedDraft
    {
        $draft = $this->undecidedDraftFactsOf($ticketId, $ownerId);

        return null === $draft ? null : new UndecidedDraft($draft->id->toString(), $draft->screenshotCount, $this->rounds->nextFreeNumberOf($ticketId));
    }

    public function undecidedDraftIdOf(TicketId $ticketId, UserId $ownerId): ?string
    {
        return $this->undecidedDraftFactsOf($ticketId, $ownerId)?->id->toString();
    }

    public function share(string $roundId, UserId $ownerId, \DateTimeImmutable $at): int
    {
        try {
            return $this->roundCommands->shareDraftAtHandOff($roundId, $ownerId, $at);
        } catch (RoundRefused $refused) {
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
}
