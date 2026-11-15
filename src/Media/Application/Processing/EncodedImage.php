<?php

declare(strict_types=1);

namespace App\Media\Application\Processing;

/**
 * The two WebP variants ImageEncoder made of one original.
 */
final readonly class EncodedImage
{
    public function __construct(
        public EncodedVariant $view,
        public EncodedVariant $thumb,
    ) {
    }
}
