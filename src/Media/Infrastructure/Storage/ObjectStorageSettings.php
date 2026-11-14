<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Storage;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where Media's private bucket is and how to sign for it (ADR-0011). Every value is required: a missing one stops
 * the storage services from being built instead of falling back to a default.
 */
final readonly class ObjectStorageSettings
{
    /**
     * @throws ObjectStorageNotConfigured when a value is empty or a URL is not http(s) with a host
     */
    public function __construct(
        #[Autowire(env: 'OBJECT_STORAGE_ENDPOINT')]
        public string $endpoint,
        #[Autowire(env: 'OBJECT_STORAGE_PUBLIC_URL')]
        public string $publicUrl,
        #[Autowire(env: 'OBJECT_STORAGE_BUCKET')]
        public string $bucket,
        #[Autowire(env: 'OBJECT_STORAGE_REGION')]
        public string $region,
        #[Autowire(env: 'OBJECT_STORAGE_ACCESS_KEY_ID')]
        public string $accessKeyId,
        #[Autowire(env: 'OBJECT_STORAGE_SECRET_ACCESS_KEY')]
        #[\SensitiveParameter]
        public string $secretAccessKey,
    ) {
        self::refuseEmpty('OBJECT_STORAGE_ENDPOINT', $endpoint);
        self::refuseEmpty('OBJECT_STORAGE_PUBLIC_URL', $publicUrl);
        self::refuseEmpty('OBJECT_STORAGE_BUCKET', $bucket);
        self::refuseEmpty('OBJECT_STORAGE_REGION', $region);
        self::refuseEmpty('OBJECT_STORAGE_ACCESS_KEY_ID', $accessKeyId);
        self::refuseEmpty('OBJECT_STORAGE_SECRET_ACCESS_KEY', $secretAccessKey);
        self::refuseNonHttpUrl('OBJECT_STORAGE_ENDPOINT', $endpoint);
        self::refuseNonHttpUrl('OBJECT_STORAGE_PUBLIC_URL', $publicUrl);
    }

    private static function refuseEmpty(string $variable, string $value): void
    {
        if ('' === trim($value)) {
            throw new ObjectStorageNotConfigured(\sprintf('%s is not set: object storage refuses to start without it.', $variable));
        }
    }

    private static function refuseNonHttpUrl(string $variable, string $value): void
    {
        $scheme = strtolower((string) parse_url($value, \PHP_URL_SCHEME));
        $host = (string) parse_url($value, \PHP_URL_HOST);
        if (!\in_array($scheme, ['http', 'https'], true) || '' === $host) {
            throw new ObjectStorageNotConfigured(\sprintf('%s is not an http(s) URL with a host.', $variable));
        }
    }
}
