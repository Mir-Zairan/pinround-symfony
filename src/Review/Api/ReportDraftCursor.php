<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * Where the next page of an owner's report drafts starts: after the last draft shown, by the list's order (updated_at
 * newest first, then id highest first). It is not signed: it holds only what the owner already saw, and a forged one
 * only moves where the page starts, as the list still reads only the owner's own drafts.
 */
final readonly class ReportDraftCursor
{
    private const string CANONICAL_UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    /**
     * @param \DateTimeImmutable $updatedAt the last shown draft's updated_at, to the microsecond as stored
     * @param string             $draftId   the last shown draft's id
     *
     * @throws \InvalidArgumentException when the draft id is not a canonical lowercase uuid
     */
    public function __construct(public \DateTimeImmutable $updatedAt, public string $draftId)
    {
        if (1 !== preg_match(self::CANONICAL_UUID, $draftId)) {
            throw new \InvalidArgumentException('A report draft cursor\'s draft id must be a canonical lowercase uuid.');
        }
    }
}
