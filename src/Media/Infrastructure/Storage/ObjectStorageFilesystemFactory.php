<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Storage;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Visibility;

/**
 * Builds the Flysystem the Media module reads and deletes bucket objects with (ADR-0011), through
 * OBJECT_STORAGE_ENDPOINT, the address the app reaches the storage at on its own network.
 */
final readonly class ObjectStorageFilesystemFactory
{
    // The SDK has no timeout by default, so a stuck storage would hold a web or Messenger worker forever.
    public const int CONNECT_TIMEOUT_SECONDS = 1;

    // A whole request, body included: a 15 MB original (ADR-0011) at 1 MB/s with room to spare.
    public const int REQUEST_TIMEOUT_SECONDS = 30;

    // One retry, not the SDK's two: a worker waits at most two request timeouts on a storage that does not answer.
    public const int RETRIES = 1;

    public function __construct(private ObjectStorageSettings $settings)
    {
    }

    public function create(): FilesystemOperator
    {
        // Private for directories too: the adapter's default would make them public-read.
        return new Filesystem(new AwsS3V3Adapter($this->createClient(), $this->settings->bucket, '', new PortableVisibilityConverter(Visibility::PRIVATE)));
    }

    public function createClient(): S3Client
    {
        return new S3Client([
            'version' => '2006-03-01',
            'region' => $this->settings->region,
            'endpoint' => $this->settings->endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $this->settings->accessKeyId, 'secret' => $this->settings->secretAccessKey],
            'http' => ['connect_timeout' => self::CONNECT_TIMEOUT_SECONDS, 'timeout' => self::REQUEST_TIMEOUT_SECONDS],
            'retries' => self::RETRIES,
        ]);
    }
}
