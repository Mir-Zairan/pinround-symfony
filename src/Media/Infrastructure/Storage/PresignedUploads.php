<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Storage;

use Aws\S3\S3Client;
use Psr\Clock\ClockInterface;

/**
 * Presigned PUT URLs for direct uploads to the private bucket (ADR-0011): the bytes go from the client to the storage,
 * never through PHP. Signed against OBJECT_STORAGE_PUBLIC_URL, the host the client reaches, path-style.
 */
final readonly class PresignedUploads
{
    public const int VALID_FOR_SECONDS = 300;

    /** The picture types ADR-0011 accepts; the processing worker checks the bytes again. */
    public const array MEDIA_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    /** 15 MB, the largest original ADR-0011 accepts. */
    public const int MAX_BYTES = 15 * 1024 * 1024;

    private S3Client $presigningClient;

    private string $bucket;

    public function __construct(ObjectStorageSettings $settings, private ClockInterface $clock)
    {
        $this->bucket = $settings->bucket;
        $this->presigningClient = new S3Client([
            'version' => '2006-03-01',
            'region' => $settings->region,
            'endpoint' => $settings->publicUrl,
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $settings->accessKeyId, 'secret' => $settings->secretAccessKey],
            'signature_provider' => static function (string $version, string $service, string $region): ContentPinnedS3Signature {
                if (!\in_array($version, ['v4', 's3v4'], true)) {
                    throw new \LogicException(\sprintf('Presigned uploads sign with SigV4 only, not "%s".', $version));
                }

                return new ContentPinnedS3Signature($service, $region);
            },
        ]);
    }

    /**
     * @param string $contentType   one of MEDIA_TYPES, exactly; the client must send exactly this
     * @param int    $contentLength the exact body size in bytes, 1 to MAX_BYTES; the client must send exactly this
     *
     * @throws \InvalidArgumentException when the type is not one of MEDIA_TYPES or the size is outside 1 to MAX_BYTES
     */
    public function forPut(UploadKey $key, string $contentType, int $contentLength): PresignedPut
    {
        if (!\in_array($contentType, self::MEDIA_TYPES, true)) {
            throw new \InvalidArgumentException('An upload must be image/png, image/jpeg or image/webp, without parameters.');
        }
        if ($contentLength < 1) {
            throw new \InvalidArgumentException('An upload needs a size of at least one byte.');
        }
        if ($contentLength > self::MAX_BYTES) {
            throw new \InvalidArgumentException(\sprintf('An upload may be at most 15 MB (%d bytes).', self::MAX_BYTES));
        }

        $signedAt = $this->clock->now();
        $expiresAt = $signedAt->add(new \DateInterval(\sprintf('PT%dS', self::VALID_FOR_SECONDS)));
        $command = $this->presigningClient->getCommand('PutObject', [
            'Bucket' => $this->bucket,
            'Key' => $key->toString(),
            'ContentType' => $contentType,
            'ContentLength' => $contentLength,
        ]);
        $request = $this->presigningClient->createPresignedRequest($command, $expiresAt, ['start_time' => $signedAt]);

        return new PresignedPut(
            (string) $request->getUri(),
            ['Content-Type' => $contentType, 'Content-Length' => (string) $contentLength],
            $expiresAt,
        );
    }
}
