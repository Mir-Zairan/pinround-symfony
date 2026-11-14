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

    // type/subtype without parameters; \z, because $ would also accept a trailing newline.
    private const string MEDIA_TYPE = '~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+\z~i';

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
     * @param string $contentType   a bare media type such as image/png; the client must send exactly this
     * @param int    $contentLength the exact body size in bytes; the client must send exactly this
     *
     * @throws \InvalidArgumentException when the type is not a bare media type or the size is below one byte
     */
    public function forPut(UploadKey $key, string $contentType, int $contentLength): PresignedPut
    {
        if (1 !== preg_match(self::MEDIA_TYPE, $contentType)) {
            throw new \InvalidArgumentException('An upload needs a media type such as image/png, without parameters.');
        }
        if ($contentLength < 1) {
            throw new \InvalidArgumentException('An upload needs a size of at least one byte.');
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
