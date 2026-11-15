<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * A pin as a form or a report draft gives it: its spot in percent of the picture and its note. Its number is its place
 * in the screenshot's list, from 1.
 */
final readonly class PlacedPin
{
    public function __construct(
        public float $xPercent,
        public float $yPercent,
        public string $note,
    ) {
    }
}
