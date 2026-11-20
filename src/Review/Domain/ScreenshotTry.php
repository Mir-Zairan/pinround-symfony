<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * Where a screenshot stands in its chain of tries (ADR-0048): "Try $number of $count".
 */
final readonly class ScreenshotTry
{
    /**
     * @param ScreenshotId $firstTryId the chain's first try; the screenshot's own id when it is the first
     * @param int          $number     from 1, in the order the tries were added
     * @param int          $count      the tries in the chain, from $number
     *
     * @throws \InvalidArgumentException when $number is below 1 or above $count
     */
    public function __construct(
        public ScreenshotId $screenshotId,
        public ScreenshotId $firstTryId,
        public int $number,
        public int $count,
    ) {
        if ($number < 1 || $number > $count) {
            throw new \InvalidArgumentException(\sprintf('Try %d of %d is not a place in a chain.', $number, $count));
        }
    }
}
