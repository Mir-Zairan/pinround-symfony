<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Storage;

/**
 * A URL the client PUTs one object to, with the headers it must send unchanged: both are part of the signature, so
 * another type or size is refused by the storage. A browser sets Content-Length itself from the body.
 */
final readonly class PresignedPut
{
    /**
     * @param array{'Content-Type': string, 'Content-Length': string} $headers
     */
    public function __construct(
        public string $url,
        public array $headers,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
