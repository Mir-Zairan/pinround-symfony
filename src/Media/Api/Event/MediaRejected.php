<?php

declare(strict_types=1);

namespace App\Media\Api\Event;

/**
 * The processing worker refused an upload and deleted its file.
 */
final readonly class MediaRejected implements MediaEvent
{
    /**
     * @param string $reason the plain text the person sees, one of the worker's fixed reasons, never user input
     */
    public function __construct(
        public string $organizationId,
        public string $mediaId,
        public string $reason,
        public \DateTimeImmutable $rejectedAt,
    ) {
    }
}
