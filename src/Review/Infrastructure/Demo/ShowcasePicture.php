<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Demo;

use App\Review\Api\PlacedPin;

/**
 * One screenshot of a showcase ticket: a PNG of the demo shop in showcase/, with its title, pins and summary.
 */
final readonly class ShowcasePicture
{
    /**
     * @param string          $file the PNG's name in showcase/
     * @param list<PlacedPin> $pins numbered 1, 2, … in this order
     */
    public function __construct(
        public string $file,
        public string $title,
        public array $pins,
        public ?string $summary = null,
    ) {
    }
}
