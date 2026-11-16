<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

use App\Review\UI\ScreenshotView;

/**
 * The screen area the round page shows: both pictures with their pins, the earlier pins to check and the verdict given.
 */
final readonly class SelectedArea
{
    /**
     * @param ScreenshotView|null $before        null when the round has no earlier picture of this place
     * @param string|null         $beforeCaption for example "Before · round 1"; null exactly when $before is
     * @param list<EarlierPinRow> $earlierPins   the pins on the "before" picture, by number
     * @param string|null         $verdictLabel  Pass, Needs fix or Question; null before a reviewer decides
     * @param string|null         $note          the reviewer's note with the verdict
     */
    public function __construct(
        public string $name,
        public ?ScreenshotView $before,
        public ?string $beforeCaption,
        public ScreenshotView $after,
        public string $afterCaption,
        public ?string $earlierPinsHeading,
        public array $earlierPins,
        public ?string $verdictLabel,
        public ?string $note,
    ) {
    }
}
