<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * What a report draft holds besides its project: the ticket's title and detail, and its pictures in order, each with
 * its optional title and summary and its pins (FR-MCP-12, ADR-0040).
 */
final readonly class ReportDraftContent
{
    /**
     * @param string                   $title       the ticket's title to be, 1 to 200 characters once trimmed
     * @param string                   $detail      the ticket's description to be, at most 50,000 characters
     * @param list<ScreenshotWithPins> $screenshots at most ReportDrafts::MAX_SCREENSHOTS, each a picture the owner
     *                                              uploaded; a picture still processing may be in a draft
     */
    public function __construct(
        public string $title,
        public string $detail,
        public array $screenshots,
    ) {
    }
}
