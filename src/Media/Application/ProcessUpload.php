<?php

declare(strict_types=1);

namespace App\Media\Application;

/**
 * Check and re-encode the file of a pending media object (media-pipeline.md, Processing worker). Sent to async_media
 * once the transaction that added the media object has committed, so the worker always finds its row.
 */
final readonly class ProcessUpload
{
    /**
     * @param string $organizationId a UUIDv7, the tenant the worker reads the media object under
     * @param string $mediaId        a UUIDv7
     */
    public function __construct(
        public string $organizationId,
        public string $mediaId,
    ) {
    }
}
