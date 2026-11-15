<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * One uploaded picture for a new round, with its optional title and summary and its pins.
 */
final readonly class ScreenshotWithPins
{
    /**
     * @param string          $mediaId the id Uploads::complete returned for the picture
     * @param list<PlacedPin> $pins    numbered 1, 2, … in this order
     */
    public function __construct(
        public string $mediaId,
        public ?string $title = null,
        public ?string $summary = null,
        public array $pins = [],
    ) {
    }
}
