<?php

declare(strict_types=1);

namespace App\Media\Application\Processing;

/**
 * Decodes a checked original fully and encodes it again as WebP (ADR-0011). Re-encoding is what makes an upload safe
 * to show: only pixels survive, so metadata (EXIF, GPS) and anything hidden in or after the image (a polyglot's
 * script) are gone.
 */
interface ImageEncoder
{
    public const int VIEW_MAX_WIDTH = 1920;
    public const int THUMB_MAX_WIDTH = 480;

    /**
     * Each variant keeps the original's aspect ratio, is scaled down to its maximum width and never up, and holds no
     * metadata and no byte copied from $original.
     *
     * @param string      $original the whole file, at most UploadPolicy::MAX_BYTES, whose header was already checked
     * @param ImageFormat $format   the format its signature showed; the encoder decodes it as that format only
     *
     * @throws UndecodableImage when $original does not decode fully as $format, or is bigger than the encoder's own
     *                          pixel limit
     */
    public function encode(string $original, ImageFormat $format): EncodedImage;
}
