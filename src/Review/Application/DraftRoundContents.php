<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\Review\Api\RoundRefused;
use App\SharedKernel\Domain\UserId;

/**
 * What a draft round holds, taken out of it for "Make it a new ticket" (ADR-0044). Inside Review only:
 * ReportDrafts::createFromDraftRound is its one caller.
 */
interface DraftRoundContents
{
    /**
     * Deletes the owner's unshared draft as RoundCommands::deleteDraft does, over the version it was read at, and gives
     * back what that version held, so a picture another request added meanwhile is never lost: that request's save
     * makes this one DRAFT_CHANGED. The pictures stay in Media; the deleted draft's rows keep pointing to them.
     *
     * @throws RoundRefused    NOT_FOUND, DRAFT_CHANGED, or when the draft is shared; nothing is saved
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function take(string $roundId, UserId $ownerId, \DateTimeImmutable $takenAt): TakenDraftRound;
}
