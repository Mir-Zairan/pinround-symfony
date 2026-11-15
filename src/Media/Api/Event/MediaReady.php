<?php

declare(strict_types=1);

namespace App\Media\Api\Event;

/**
 * The processing worker checked an upload and stored its re-encoded variants, or found the same picture of the
 * organization ready already and points to its variants.
 */
final readonly class MediaReady implements MediaEvent
{
    public function __construct(
        public string $organizationId,
        public string $mediaId,
        public \DateTimeImmutable $readyAt,
    ) {
    }
}
