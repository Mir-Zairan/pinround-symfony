<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\UserId;

/**
 * A pin of a listed round, as rvw_pin holds it.
 */
final readonly class RoundPinView
{
    /**
     * @param int         $number   from 1, per screenshot; a removed pin leaves a gap
     * @param float       $xPercent 0 to 100, two decimals
     * @param float       $yPercent 0 to 100, two decimals
     * @param string|null $verdict  fixed, still_wrong, or null before a reviewer decides
     * @param string      $source   human or ai
     * @param string|null $aiState  suggested, kept or dismissed for an AI pin; null for a person's
     */
    public function __construct(
        public string $id,
        public int $number,
        public float $xPercent,
        public float $yPercent,
        public string $note,
        public ?string $verdict,
        public string $source,
        public ?string $aiState,
        public UserId $authorId,
    ) {
    }
}
