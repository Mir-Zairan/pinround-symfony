<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * One page of an owner's "Report drafts" list (ReportDrafts::forOwner).
 */
final readonly class ReportDraftsPage
{
    /**
     * @param list<ReportDraftSummary> $drafts     at most ReportDrafts::DRAFTS_PER_PAGE, newest change first
     * @param ReportDraftCursor|null   $nextCursor null when no draft follows the last one
     */
    public function __construct(
        public array $drafts,
        public ?ReportDraftCursor $nextCursor,
    ) {
    }
}
