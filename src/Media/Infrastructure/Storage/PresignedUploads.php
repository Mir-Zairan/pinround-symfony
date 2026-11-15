<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Storage;

use App\Media\Application\PresignedPut;
use App\Media\Application\UploadUrlSigner;
use App\Media\Domain\UploadKey;
use App\Media\Domain\UploadPolicy;
use Aws\S3\S3Client;
use Psr\Clock\ClockInterface;

/**
 * Presigned PUT URLs for direct uploads to the private bucket (ADR-0011): the bytes go from the client to the storage,
 * never through PHP. Signed against OBJECT_STORAGE_PUBLIC_URL, the host the client reaches, path-style.
 */
final readonly class PresignedUploads implements UploadUrlSigner
{
    public const int VALID_FOR_SECONDS = 300;

    private S3Client $presigningClient;

    private string $bucket;

    public function __construct(ObjectStorageSettings $settings, private ClockInterface $clock)
    {
        $this->bucket = $settings->bucket;
        $this->presigningClient = PresigningClientFactory::create(
            $settings,
            static function (string $version, string $service, string $region): ContentPinnedS3Signature {
                if (!\in_array($version, ['v4', 's3v4'], true)) {
                    throw new \LogicException(\sprintf('Presigned uploads sign with SigV4 only, not "%s".', $version));
                }

                return new ContentPinnedS3Signature($service, $region);
            },
        );
    }

    /**
     * The URL works for VALID_FOR_SECONDS from the clock's now.
     */
    public function forPut(UploadKey $key, string $mime, int $bytes): PresignedPut
    {
        if (!UploadPolicy::allowsMediaType($mime)) {
            throw new \InvalidArgumentException('An upload must be image/png, image/jpeg or image/webp, without parameters.');
        }
        if ($bytes < 1) {
            throw new \InvalidArgumentException('An upload needs a size of at least one byte.');
        }
        if ($bytes > UploadPolicy::MAX_BYTES) {
            throw new \InvalidArgumentException(\sprintf('An upload may be at most 15 MB (%d bytes).', UploadPolicy::MAX_BYTES));
        }

        $signedAt = $this->clock->now();
        $expiresAt = $signedAt->add(new \DateInterval(\sprintf('PT%dS', self::VALID_FOR_SECONDS)));
        $command = $this->presigningClient->getCommand('PutObject', [
            'Bucket' => $this->bucket,
            'Key' => $key->toString(),
            'ContentType' => $mime,
            'ContentLength' => $bytes,
        ]);
        $request = $this->presigningClient->createPresignedRequest($command, $expiresAt, ['start_time' => $signedAt]);

        return new PresignedPut(
            (string) $request->getUri(),
            ['Content-Type' => $mime, 'Content-Length' => (string) $bytes],
            $expiresAt,
        );
    }
}
