<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * One report draft as its owner's "Report drafts" list shows it: no detail, pictures or version, so the list reads
 * only these columns.
 */
final readonly class ReportDraftSummary
{
    /**
     * @param string             $id              the draft's uuid, in lowercase
     * @param string             $projectKey      the project it is for, for example SHOP
     * @param int                $screenshotCount 0 to ReportDrafts::MAX_SCREENSHOTS
     * @param \DateTimeImmutable $updatedAt       its last change, as stored, to the microsecond
     */
    public function __construct(
        public string $id,
        public string $projectKey,
        public string $title,
        public int $screenshotCount,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
