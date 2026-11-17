<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

use App\Review\UI\ScreenshotView;

/**
 * The screen area the round page shows: both pictures with their pins. Its side panel is a RoundPanel of its own, so a
 * verdict's answer can show it again without signing the pictures.
 */
final readonly class SelectedArea
{
    /**
     * @param ScreenshotView|null $before        null when the round has no earlier picture of this place
     * @param string|null         $beforeCaption for example "Before · round 1"; null exactly when $before is
     */
    public function __construct(
        public string $name,
        public ?ScreenshotView $before,
        public ?string $beforeCaption,
        public ScreenshotView $after,
        public string $afterCaption,
    ) {
    }
}
