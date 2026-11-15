<?php

declare(strict_types=1);

namespace App\Media\Application\Processing;

/**
 * One re-encoded WebP and its size in pixels.
 */
final readonly class EncodedVariant
{
    /**
     * @throws \InvalidArgumentException when $bytes is empty, or $width or $height is below 1
     */
    public function __construct(
        public string $bytes,
        public int $width,
        public int $height,
    ) {
        if ('' === $bytes) {
            throw new \InvalidArgumentException('An encoded variant needs its bytes.');
        }
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('An encoded variant needs a width and a height of at least one pixel.');
        }
    }
}
