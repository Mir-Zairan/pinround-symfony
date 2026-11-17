<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * A pin of a draft round as RoundNotes carries it: where it sits and what it says. RoundPinView is the listed round's
 * pin and carries the author, source and review state that notes leave out.
 */
final readonly class PinNote
{
    /**
     * @param int    $number   from 1, per screenshot; a removed pin leaves a gap
     * @param float  $xPercent 0 to 100, two decimals
     * @param float  $yPercent 0 to 100, two decimals
     * @param string $note     may be empty
     */
    public function __construct(
        public int $number,
        public float $xPercent,
        public float $yPercent,
        public string $note,
    ) {
    }
}
