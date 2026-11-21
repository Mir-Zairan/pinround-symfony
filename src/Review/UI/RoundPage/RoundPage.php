<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

/**
 * What the round page shows of one round: the ticket line, the header, the screenshot strip and pager, the one shown
 * screenshot and, for a screen area, its side panel.
 */
final readonly class RoundPage
{
    /**
     * @param string                $title        for example "Round 2"
     * @param string                $detail       the header's muted rest after the title, for example "shot today, 09:12 · Ready for Mia"
     * @param string                $stripLabel   the strip's accessible name: "Screen areas" or "Screenshots"
     * @param list<ScreenshotThumb> $thumbs       in order; none for a round with nothing to show
     * @param ScreenshotPager|null  $pager        null exactly when $thumbs is empty
     * @param ShownScreenshot|null  $shown        null exactly when $thumbs is empty
     * @param RoundPanel|null       $panel        the shown screen area's; null for a round without areas
     * @param string                $emptyMessage what the page says when $thumbs is empty
     */
    public function __construct(
        public string $ticketKey,
        public string $ticketTitle,
        public string $title,
        public string $detail,
        public string $stripLabel,
        public array $thumbs,
        public ?ScreenshotPager $pager,
        public ?ShownScreenshot $shown,
        public ?RoundPanel $panel,
        public string $emptyMessage,
    ) {
    }
}
