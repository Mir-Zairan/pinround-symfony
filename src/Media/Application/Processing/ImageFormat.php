<?php

declare(strict_types=1);

namespace App\Media\Application\Processing;

/**
 * A picture format the processing worker accepts, as its signature bytes show it; the value is its media type, the
 * one an upload is declared with (UploadPolicy::MEDIA_TYPES).
 */
enum ImageFormat: string
{
    case Png = 'image/png';
    case Jpeg = 'image/jpeg';
    case Webp = 'image/webp';
}
