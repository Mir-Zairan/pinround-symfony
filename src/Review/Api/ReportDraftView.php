<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\UserId;

/**
 * One report draft as its owner sees it in the "Report drafts" list and on its page.
 */
final readonly class ReportDraftView
{
    /**
     * @param string                   $id          the draft's uuid
     * @param string                   $projectKey  the project it is for, for example SHOP
     * @param list<ScreenshotWithPins> $screenshots in their order; media ids in lowercase
     * @param int                      $version     from 1; an edit or Send names it so a change from another tab is
     *                                              not overwritten
     */
    public function __construct(
        public string $id,
        public UserId $ownerId,
        public string $projectKey,
        public string $title,
        public string $detail,
        public array $screenshots,
        public int $version,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
