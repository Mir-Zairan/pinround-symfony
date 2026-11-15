<?php

declare(strict_types=1);

namespace App\Media\Application\Processing;

/**
 * What the first bytes of a picture say about it, read without decoding any pixel.
 */
final readonly class ImageHeader
{
    /**
     * @param int  $width      in pixels, as the header states it, at least 1
     * @param int  $height     in pixels, as the header states it, at least 1
     * @param bool $isAnimated whether the header announces more than one frame (an APNG or an animated WebP)
     *
     * @throws \InvalidArgumentException when $width or $height is below 1
     */
    public function __construct(
        public ImageFormat $format,
        public int $width,
        public int $height,
        public bool $isAnimated,
    ) {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('An image header needs a width and a height of at least one pixel.');
        }
    }
}
