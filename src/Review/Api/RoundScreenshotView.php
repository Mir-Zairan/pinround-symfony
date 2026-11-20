<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * A screenshot of a listed round with its pins, as rvw_screenshot holds it.
 */
final readonly class RoundScreenshotView
{
    /**
     * @param string             $mediaId      the Media object that holds the picture
     * @param int                $position     from 0, its place in the round
     * @param string|null        $variantLabel before, after, or null outside a compare
     * @param string|null        $verdict      looks_right, not_right, or null
     * @param list<RoundPinView> $pins         by number
     * @param string|null        $retakeOf     the first try of its chain when it is a later try (ADR-0048); null for a
     *                                         first try, and always null in a shared round
     */
    public function __construct(
        public string $id,
        public string $mediaId,
        public int $position,
        public ?string $variantLabel,
        public ?string $verdict,
        public ?string $title,
        public ?string $summary,
        public array $pins,
        public ?string $retakeOf = null,
    ) {
    }
}
