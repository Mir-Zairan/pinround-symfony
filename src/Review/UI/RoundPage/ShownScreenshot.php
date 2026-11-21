<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

use App\Review\UI\ScreenshotView;

/**
 * The one screenshot the round page shows, with its pins: a screen area's picture with the earlier round's picture of
 * the same place when there is one, or a screenshot of a round without areas, with the same-numbered screenshot of the
 * hand-off round before a shared draft, or of the report when no hand-off round is before it, when there is one. An
 * area's side panel is a RoundPanel of its own, so a verdict's answer can show it again without signing the pictures.
 */
final readonly class ShownScreenshot
{
    /**
     * @param string              $name          the area's name or "Screenshot 2", which names the pictures' section
     * @param ScreenshotView|null $before        an earlier round's picture of the same place; null when there is none,
     *                                           and the page then offers no Before / After / Side by side
     * @param string|null         $beforeCaption for example "Before · round 1", or "Before · round 0" for the report's
     *                                           picture; null exactly when $before is
     * @param string|null         $afterCaption  for example "After · round 2"; null for a screenshot of a round without
     *                                           areas that has no $before, which stands alone
     */
    public function __construct(
        public string $name,
        public ?ScreenshotView $before,
        public ?string $beforeCaption,
        public ScreenshotView $after,
        public ?string $afterCaption,
    ) {
    }
}
