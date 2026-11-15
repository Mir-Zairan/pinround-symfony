<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Media;

use App\Media\Application\Processing\EncodedImage;
use App\Media\Application\Processing\EncodedVariant;
use App\Media\Application\Processing\ImageEncoder;
use App\Media\Application\Processing\ImageFormat;

/**
 * An ImageEncoder that breaks the contract the way a real one could: it decodes and sizes the original as
 * FakeImageEncoder does, refusals included, then returns the original's bytes unchanged as both variants, as an encoder
 * that skips re-encoding a picture already in WebP and small enough would. A polyglot's tail survives in them.
 */
final class PassThroughImageEncoder implements ImageEncoder
{
    /** How often encode() was called, refused calls included. */
    public int $encodeCount = 0;

    public function encode(string $original, ImageFormat $format): EncodedImage
    {
        ++$this->encodeCount;
        $decoded = new FakeImageEncoder()->encode($original, $format);

        return new EncodedImage(
            new EncodedVariant($original, $decoded->view->width, $decoded->view->height),
            new EncodedVariant($original, $decoded->thumb->width, $decoded->thumb->height),
        );
    }
}
