<?php

declare(strict_types=1);

namespace App\Review\Application\GeneralNote;

use App\Review\Api\GeneralNoteCommands;
use App\Review\Api\GeneralNoteRefused;
use App\Review\Domain\GeneralNote;
use App\Review\Domain\GeneralNotes;
use App\Review\Domain\ReviewedRound;
use App\Review\Domain\RoundId;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;

/**
 * GeneralNoteCommands on GeneralNotes; the contract is on {@see GeneralNoteCommands}. The round is locked before the
 * notes are counted, so two notes at once cannot both pass the cap.
 */
final readonly class UseCaseGeneralNoteCommands implements GeneralNoteCommands
{
    public function __construct(
        private GeneralNotes $generalNotes,
        private TenantContext $tenantContext,
    ) {
    }

    public function add(string $roundId, UserId $actor, ?string $verdict, string $note, \DateTimeImmutable $at): void
    {
        $this->tenantContext->require();
        try {
            $id = new RoundId($roundId);
        } catch (\InvalidArgumentException) {
            throw new GeneralNoteRefused(GeneralNoteRefused::NOT_FOUND);
        }
        $round = $this->generalNotes->lockRound($id) ?? throw new GeneralNoteRefused(GeneralNoteRefused::NOT_FOUND);
        try {
            $round->requireOpenForGeneralNotes();
            $written = GeneralNote::write($this->generalNotes->nextId(), $round->id, $actor, $verdict, $note, $at);
        } catch (\DomainException $refused) {
            // A round that takes no notes at all is not one the caller could have been shown.
            throw new GeneralNoteRefused(ReviewedRound::TAKES_NO_GENERAL_NOTES === $refused->getMessage() ? GeneralNoteRefused::NOT_FOUND : $refused->getMessage(), 0, $refused);
        }
        if ($this->generalNotes->countOn($round->id) >= GeneralNote::MAX_PER_ROUND) {
            throw new GeneralNoteRefused(GeneralNote::ROUND_FULL);
        }

        $this->generalNotes->add($written);
    }
}
