<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Storage;

use Aws\S3\S3Client;
use Aws\Signature\SignatureInterface;

/**
 * Builds the S3 client presigned URLs are made with. It signs against OBJECT_STORAGE_PUBLIC_URL, the host the client
 * reaches, path-style, and is never used to send a request itself.
 */
final class PresigningClientFactory
{
    private function __construct()
    {
    }

    /**
     * @param (callable(string, string, string): ?SignatureInterface)|null $signatureProvider the SDK's SigV4 when null
     */
    public static function create(ObjectStorageSettings $settings, ?callable $signatureProvider = null): S3Client
    {
        $options = [
            'version' => '2006-03-01',
            'region' => $settings->region,
            'endpoint' => $settings->publicUrl,
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $settings->accessKeyId, 'secret' => $settings->secretAccessKey],
        ];
        if (null !== $signatureProvider) {
            $options['signature_provider'] = $signatureProvider;
        }

        return new S3Client($options);
    }
}
