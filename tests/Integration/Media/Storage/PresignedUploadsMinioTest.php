<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media\Storage;

use App\Media\Application\PresignedPut;
use App\Media\Domain\UploadIntentId;
use App\Media\Domain\UploadKey;
use App\Media\Infrastructure\Storage\ObjectStorageFilesystemFactory;
use App\Media\Infrastructure\Storage\ObjectStorageSettings;
use App\Media\Infrastructure\Storage\PresignedUploads;
use App\SharedKernel\Domain\OrganizationId;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Against the dev stack's MinIO. The URL is signed for OBJECT_STORAGE_ENDPOINT here, because the browser-facing
 * OBJECT_STORAGE_PUBLIC_URL (localhost) is not reachable from inside the app container; the signed host is the only
 * difference from production.
 */
#[Group('object-storage')]
final class PresignedUploadsMinioTest extends TestCase
{
    private const string ORGANIZATION = '0192f3a0-7b2c-7d3e-8a4f-5b6c7d8e9f01';

    private const string PNG_BODY = "\x89PNG\r\n\x1a\nnot really a picture";

    /** @var list<UploadKey> */
    private array $uploadedKeys = [];

    protected function tearDown(): void
    {
        foreach ($this->uploadedKeys as $key) {
            $this->storage()->delete($key->toString());
        }
    }

    #[Test]
    public function aPutWithTheSignedTypeAndLengthStoresTheObject(): void
    {
        $key = $this->newKey();
        $put = $this->uploads(new NativeClock())->forPut($key, 'image/png', \strlen(self::PNG_BODY));

        $response = $this->put($put, self::PNG_BODY);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::PNG_BODY, $this->storage()->read($key->toString()));
        self::assertSame('image/png', $this->storage()->mimeType($key->toString()));
    }

    #[Test]
    public function aPutWithAnotherContentTypeIsRefusedAndStoresNothing(): void
    {
        $key = $this->newKey();
        $put = $this->uploads(new NativeClock())->forPut($key, 'image/png', \strlen(self::PNG_BODY));

        $response = $this->putWithHeaders($put->url, ['Content-Type' => 'text/html', 'Content-Length' => (string) \strlen(self::PNG_BODY)], self::PNG_BODY);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('SignatureDoesNotMatch', $response->getContent(false));
        self::assertFalse($this->storage()->fileExists($key->toString()));
    }

    #[Test]
    public function aPutWithoutAContentTypeIsRefusedAndStoresNothing(): void
    {
        $key = $this->newKey();
        $put = $this->uploads(new NativeClock())->forPut($key, 'image/png', \strlen(self::PNG_BODY));

        $response = $this->putWithHeaders($put->url, ['Content-Type' => '', 'Content-Length' => (string) \strlen(self::PNG_BODY)], self::PNG_BODY);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('SignatureDoesNotMatch', $response->getContent(false));
        self::assertFalse($this->storage()->fileExists($key->toString()));
    }

    #[Test]
    public function aPutWithAnotherLengthIsRefusedAndStoresNothing(): void
    {
        $key = $this->newKey();
        $put = $this->uploads(new NativeClock())->forPut($key, 'image/png', \strlen(self::PNG_BODY));
        $biggerBody = self::PNG_BODY.str_repeat('x', 100);

        $response = $this->putWithHeaders($put->url, ['Content-Type' => 'image/png', 'Content-Length' => (string) \strlen($biggerBody)], $biggerBody);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('SignatureDoesNotMatch', $response->getContent(false));
        self::assertFalse($this->storage()->fileExists($key->toString()));
    }

    #[Test]
    public function aUrlSignedLongerAgoThanItsFiveMinutesIsRefused(): void
    {
        $key = $this->newKey();
        $signedLongAgo = $this->uploads(new MockClock('2026-03-10 09:00:00 UTC'))->forPut($key, 'image/png', \strlen(self::PNG_BODY));

        $response = $this->put($signedLongAgo, self::PNG_BODY);

        self::assertSame(403, $response->getStatusCode());
        $refusal = $response->getContent(false);
        self::assertStringContainsString('<Code>AccessDenied</Code>', $refusal);
        self::assertStringContainsString('<Message>Request has expired</Message>', $refusal);
        self::assertFalse($this->storage()->fileExists($key->toString()));
    }

    #[Test]
    public function anAnonymousGetOfAnUploadedObjectIsRefused(): void
    {
        $key = $this->newKey();
        $this->storage()->write($key->toString(), self::PNG_BODY);

        $response = HttpClient::create()->request('GET', $this->settings()->endpoint.'/'.$this->settings()->bucket.'/'.$key->toString());

        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString('not really a picture', $response->getContent(false));
        self::assertTrue($this->storage()->fileExists($key->toString()));
    }

    private function put(PresignedPut $put, string $body): ResponseInterface
    {
        return $this->putWithHeaders($put->url, $put->headers, $body);
    }

    /**
     * @param array<string, string> $headers
     */
    private function putWithHeaders(string $url, array $headers, string $body): ResponseInterface
    {
        return HttpClient::create()->request('PUT', $url, ['headers' => $headers, 'body' => $body]);
    }

    private function newKey(): UploadKey
    {
        $key = UploadKey::forIntent(new OrganizationId(self::ORGANIZATION), new UploadIntentId(Uuid::v7()->toRfc4122()));
        $this->uploadedKeys[] = $key;

        return $key;
    }

    private function uploads(\Psr\Clock\ClockInterface $clock): PresignedUploads
    {
        $settings = $this->settings();

        return new PresignedUploads(
            new ObjectStorageSettings($settings->endpoint, $settings->endpoint, $settings->bucket, $settings->region, $settings->accessKeyId, $settings->secretAccessKey),
            $clock,
        );
    }

    private function settings(): ObjectStorageSettings
    {
        return new ObjectStorageSettings(
            self::env('OBJECT_STORAGE_ENDPOINT'),
            self::env('OBJECT_STORAGE_PUBLIC_URL'),
            self::env('OBJECT_STORAGE_BUCKET'),
            self::env('OBJECT_STORAGE_REGION'),
            self::env('OBJECT_STORAGE_ACCESS_KEY_ID'),
            self::env('OBJECT_STORAGE_SECRET_ACCESS_KEY'),
        );
    }

    private static function env(string $name): string
    {
        $value = $_SERVER[$name] ?? null;

        return \is_string($value) ? $value : throw new \LogicException($name.' is not set in .env.test.');
    }

    private function storage(): FilesystemOperator
    {
        return new ObjectStorageFilesystemFactory($this->settings())->create();
    }
}
