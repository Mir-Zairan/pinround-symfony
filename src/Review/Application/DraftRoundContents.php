<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\Review\Api\RoundRefused;
use App\SharedKernel\Domain\UserId;

/**
 * What a draft round holds, read and then deleted for "Make it a new ticket" (ADR-0044), so the caller checks every
 * rule of what it makes of the draft before anything is written. Inside Review only:
 * ReportDrafts::createFromDraftRound is its one caller.
 */
interface DraftRoundContents
{
    /**
     * Reads only. The earlier tries of a retaken screenshot stay out (ADR-0048): the report draft gets the latest try of
     * each, so its picture cap counts screenshots, not tries.
     *
     * @throws RoundRefused NOT_FOUND for an id that is not $ownerId's undeleted draft (a malformed one too)
     */
    public function contentsOf(string $roundId, UserId $ownerId): DraftRoundAsRead;

    /**
     * Deletes the draft as RoundCommands::deleteDraft does, only while it is still at the version $read was read at, so
     * a picture another request added meanwhile is never lost and two moves of one draft cannot both succeed: the save
     * is a version-checked UPDATE, so of two requests the second waits for the first and is refused. The pictures stay
     * in Media and the deleted draft's rows keep pointing to them; nothing about them is deleted or queued.
     *
     * @throws RoundRefused    NOT_FOUND once the draft is deleted; DRAFT_CHANGED when it was saved since $read; or the
     *                         draft's refusal when it is shared; nothing is saved
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function deleteAsRead(DraftRoundAsRead $read, UserId $ownerId, \DateTimeImmutable $deletedAt): void;
}
