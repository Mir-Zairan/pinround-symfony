<?php

declare(strict_types=1);

namespace App\Media\Api;

/**
 * The media object whose processing state a person asks to read, as the voter subject of
 * MediaPermission::VIEW_UPLOAD_STATE.
 */
final readonly class UploadedMediaId
{
    /**
     * @param string $id the media id as the person gave it; a malformed one is never granted
     */
    public function __construct(
        public string $id,
    ) {
    }
}
