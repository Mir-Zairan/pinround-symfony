<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Application\ReportDraft;

use App\Review\Api\RoundRefused;
use App\Review\Application\DraftRoundAsRead;
use App\Review\Application\DraftRoundContents;
use App\SharedKernel\Domain\UserId;

/**
 * One owner's draft round, refused as UseCaseRoundCommands refuses it: NOT_FOUND for another id or owner and once
 * deleted, DRAFT_CHANGED when the version moved since it was read. Records each delete.
 */
final class InMemoryDraftRoundContents implements DraftRoundContents
{
    /** @var list<array{string, \DateTimeImmutable}> the round id and time of each delete, in order */
    public array $deletions = [];

    /** When true, the next contentsOf is followed by another request's save of the round, before any delete. */
    public bool $isChangedAfterNextRead = false;

    private bool $isDeleted = false;

    public function __construct(
        private DraftRoundAsRead $draft,
        private readonly UserId $ownerId,
    ) {
    }

    public function contentsOf(string $roundId, UserId $ownerId): DraftRoundAsRead
    {
        $this->refuseUnlessOwnUndeletedDraft($roundId, $ownerId);
        $read = $this->draft;
        if ($this->isChangedAfterNextRead) {
            $this->isChangedAfterNextRead = false;
            $this->draft = new DraftRoundAsRead($read->roundId, $read->ticketId, $read->version + 1, $read->screenshots);
        }

        return $read;
    }

    public function deleteAsRead(DraftRoundAsRead $read, UserId $ownerId, \DateTimeImmutable $deletedAt): void
    {
        $this->refuseUnlessOwnUndeletedDraft($read->roundId, $ownerId);
        if ($read->version !== $this->draft->version) {
            throw new RoundRefused(RoundRefused::DRAFT_CHANGED);
        }
        $this->isDeleted = true;
        $this->deletions[] = [$read->roundId, $deletedAt];
    }

    /**
     * @throws RoundRefused NOT_FOUND
     */
    private function refuseUnlessOwnUndeletedDraft(string $roundId, UserId $ownerId): void
    {
        if ($this->isDeleted || $roundId !== $this->draft->roundId || !$ownerId->equals($this->ownerId)) {
            throw new RoundRefused(RoundRefused::NOT_FOUND);
        }
    }
}
