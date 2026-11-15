<?php

declare(strict_types=1);

namespace App\Media\Application;

/**
 * Check and re-encode the file of a pending media object (media-pipeline.md, Processing worker). Written to the outbox
 * in the transaction that adds the media object, and relayed to async_media only once it has committed, so the worker
 * always finds its row.
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
