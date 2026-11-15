<?php

declare(strict_types=1);

namespace App\Media\Api;

/**
 * Where the browser PUTs the file of an upload it began, and the headers it must send unchanged: the URL's signature
 * pins them, so the storage refuses another type or size.
 */
final readonly class UploadSlot
{
    /**
     * @param string                $intentId  the id complete() takes once the PUT succeeded
     * @param array<string, string> $headers   by header name
     * @param \DateTimeImmutable    $expiresAt the first moment the URL no longer works; the intent itself lasts longer
     */
    public function __construct(
        public string $intentId,
        public string $url,
        public array $headers,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
