<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * One screenshot of a person's open draft round with its tries, as the draft round card numbers it (ScreenshotChains).
 */
final readonly class DraftScreenshotChain
{
    /**
     * @param string      $screenshotId the chain's first try, the id a retake names
     * @param int         $number       the "Screenshot n" of the card, from 1
     * @param string|null $title        the latest try's, as the card shows it
     * @param int         $tryCount     from 1
     */
    public function __construct(
        public string $screenshotId,
        public int $number,
        public ?string $title,
        public int $tryCount,
    ) {
    }
}
