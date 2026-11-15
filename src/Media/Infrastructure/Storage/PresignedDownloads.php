<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Storage;

use App\Media\Application\MediaUrlSigner;
use Aws\S3\S3Client;

/**
 * Presigned GET URLs of stored variants in the private bucket (ADR-0011): the browser reads the bytes from the storage,
 * never through PHP. The response type and disposition are signed into the URL, so the storage serves the bytes as an
 * inline WebP whatever was stored with them.
 */
final readonly class PresignedDownloads implements MediaUrlSigner
{
    private S3Client $presigningClient;

    private string $bucket;

    public function __construct(ObjectStorageSettings $settings)
    {
        $this->bucket = $settings->bucket;
        $this->presigningClient = PresigningClientFactory::create($settings);
    }

    public function forGet(string $storageKey, \DateTimeImmutable $signedAt): string
    {
        if ('' === $storageKey) {
            throw new \InvalidArgumentException('A signed URL needs the storage key of what it shows.');
        }

        $command = $this->presigningClient->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $storageKey,
            'ResponseContentType' => 'image/webp',
            'ResponseContentDisposition' => 'inline',
            'ResponseCacheControl' => \sprintf('private, max-age=%d, immutable', self::MIN_VALID_FOR_SECONDS),
        ]);
        $windowStart = new \DateTimeImmutable('@'.intdiv($signedAt->getTimestamp(), self::SIGNING_WINDOW_SECONDS) * self::SIGNING_WINDOW_SECONDS);
        $expiresAt = $windowStart->add(new \DateInterval(\sprintf('PT%dS', self::VALID_FOR_SECONDS)));

        return (string) $this->presigningClient->createPresignedRequest($command, $expiresAt, ['start_time' => $windowStart])->getUri();
    }
}
