<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Processing;

use App\Media\Application\Processing\EncodedImage;
use App\Media\Application\Processing\ImageEncoder;
use App\Media\Application\Processing\ImageFormat;

// TODO(P05.T2 part 4b): replace with the libvips adapter once the worker image ships libvips (media-pipeline.md).
/**
 * The ImageEncoder until the worker image has libvips: it fails every call, so a checked upload is neither shown
 * unprocessed nor rejected for a missing tool. Its object stays pending and its message ends in the failed transport,
 * from where it can be retried once the real encoder is in. A duplicate of a ready picture needs no encoder and is
 * still made ready.
 */
final readonly class UnavailableImageEncoder implements ImageEncoder
{
    public function encode(string $original, ImageFormat $format): EncodedImage
    {
        throw new \RuntimeException('No image encoder is installed, so uploads cannot be re-encoded yet.');
    }
}
