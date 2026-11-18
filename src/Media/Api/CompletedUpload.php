<?php

declare(strict_types=1);

namespace App\Media\Api;

/**
 * The picture a completed direct upload made, and where it is in processing.
 */
final readonly class CompletedUpload
{
    /**
     * @param string $mediaId the media object's id, which is its upload intent's id
     */
    public function __construct(
        public string $mediaId,
        public MediaImageStatus $status,
    ) {
    }
}
