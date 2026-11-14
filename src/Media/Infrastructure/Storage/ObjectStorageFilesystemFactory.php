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
    public function __construct(private ObjectStorageSettings $settings)
    {
    }

    public function create(): FilesystemOperator
    {
        $client = new S3Client([
            'version' => '2006-03-01',
            'region' => $this->settings->region,
            'endpoint' => $this->settings->endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $this->settings->accessKeyId, 'secret' => $this->settings->secretAccessKey],
        ]);

        // Private for directories too: the adapter's default would make them public-read.
        return new Filesystem(new AwsS3V3Adapter($client, $this->settings->bucket, '', new PortableVisibilityConverter(Visibility::PRIVATE)));
    }
}
