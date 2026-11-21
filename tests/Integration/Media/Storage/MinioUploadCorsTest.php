<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The dev stack's MinIO lets only the app's and the browser extension's browser send presigned PUTs: minio-init sets
 * cors_allow_origin to MINIO_CORS_ALLOW_ORIGIN, whose compose.yaml default is those two origins.
 */
#[Group('object-storage')]
final class MinioUploadCorsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedOrigins(): iterable
    {
        yield 'the app' => ['https://localhost'];
        yield 'the browser extension' => ['chrome-extension://ecijiihpbchkekoddiblnidjpeaajkfo'];
    }

    #[Test]
    #[DataProvider('allowedOrigins')]
    public function aPreflightFromAnAllowedOriginIsAllowed(string $origin): void
    {
        $response = self::preflightPut($origin);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame([$origin], $response->getHeaders(false)['access-control-allow-origin'] ?? []);
        self::assertSame(['PUT'], $response->getHeaders(false)['access-control-allow-methods'] ?? []);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherOrigins(): iterable
    {
        yield 'another site' => ['https://files.example.test'];
        yield 'the app host over http' => ['http://localhost'];
        yield 'the app host on another port' => ['https://localhost:8443'];
        yield 'a look-alike host' => ['https://localhost.example.test'];
        yield 'another browser extension' => ['chrome-extension://abcdefghijklmnopabcdefghijklmnop'];
    }

    #[Test]
    #[DataProvider('otherOrigins')]
    public function aPreflightFromAnotherOriginGetsNoCorsHeaders(string $origin): void
    {
        $response = self::preflightPut($origin);

        $headers = $response->getHeaders(false);
        self::assertArrayNotHasKey('access-control-allow-origin', $headers);
        self::assertArrayNotHasKey('access-control-allow-methods', $headers);
    }

    private static function preflightPut(string $origin): ResponseInterface
    {
        $endpoint = $_SERVER['OBJECT_STORAGE_ENDPOINT'] ?? null;
        $bucket = $_SERVER['OBJECT_STORAGE_BUCKET'] ?? null;
        if (!\is_string($endpoint) || !\is_string($bucket)) {
            throw new \LogicException('OBJECT_STORAGE_ENDPOINT and OBJECT_STORAGE_BUCKET are not set in .env.test.');
        }

        return HttpClient::create()->request('OPTIONS', $endpoint.'/'.$bucket.'/uploads/any', [
            'headers' => [
                'Origin' => $origin,
                'Access-Control-Request-Method' => 'PUT',
                'Access-Control-Request-Headers' => 'content-type',
            ],
        ]);
    }
}
