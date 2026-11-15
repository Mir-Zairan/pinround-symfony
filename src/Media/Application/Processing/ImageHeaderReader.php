<?php

declare(strict_types=1);

namespace App\Media\Application\Processing;

/**
 * Reads a picture's format, size and animation from its first bytes alone, without decoding it, so an image bomb or an
 * animation is refused before any decoder sees it.
 */
interface ImageHeaderReader
{
    /**
     * How many leading bytes a caller passes at most: enough for the metadata a header may put before the size or the
     * first image data, and small next to the 15 MB an original may have.
     */
    public const int LEADING_BYTES = 1024 * 1024;

    /**
     * @param string $leadingBytes the first bytes of the file, at most LEADING_BYTES; fewer when the file is shorter
     *
     * @throws UnknownImageFormat    when the bytes start with no PNG, JPEG or WebP signature
     * @throws UnreadableImageHeader when the signature is known but the header is broken, states no size, or does not
     *                               end within $leadingBytes
     */
    public function read(string $leadingBytes): ImageHeader;
}
