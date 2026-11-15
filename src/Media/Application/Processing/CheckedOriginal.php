<?php

declare(strict_types=1);

namespace App\Media\Application\Processing;

/**
 * An uploaded original that passed every check made before decoding: its size, signature, declared type, dimensions
 * and animation.
 */
final readonly class CheckedOriginal
{
    public function __construct(
        public string $bytes,
        public ImageFormat $format,
    ) {
    }
}
